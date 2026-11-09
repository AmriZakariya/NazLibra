<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Models\AppUpdatePolicy;
use App\Support\AppVersions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Mises à jour de l'app »: the latest and minimum app versions per
 * platform, and the APK for Android devices without Google Play (PAX).
 */
class AppUpdateAdminController extends Controller
{
    public function index(): View
    {
        return view('castlit.admin.app-updates', [
            'policies' => collect(AppUpdatePolicy::PLATFORMS)->mapWithKeys(fn ($p) => [$p => AppUpdatePolicy::for($p)]),
            'uploadLimit' => $this->uploadLimit(),
            'storeUrls' => ['android' => AppVersions::storeUrl('android'), 'ios' => AppVersions::storeUrl('ios')],
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        // Over post_max_size PHP drops the whole body: say why, not "required".
        $length = (int) $request->server('CONTENT_LENGTH');
        if ($length > 0 && $request->request->count() === 0 && $request->files->count() === 0) {
            return back()->with('error', 'Fichier trop gros pour ce serveur (limite '.$this->uploadLimit().'). '
                .'Déposez l’APK ailleurs et collez son lien.');
        }

        $data = $request->validate([
            'platform' => ['required', 'in:'.implode(',', AppUpdatePolicy::PLATFORMS)],
            'latest_version' => ['nullable', 'string', 'max:32'],
            'min_version' => ['nullable', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:500'],
            'apk' => ['nullable', 'file', 'max:300000'],
            'apk_url' => ['nullable', 'url:https', 'max:500'],
            'apk_version' => ['nullable', 'string', 'max:32'],
            'remove_apk' => ['nullable', 'boolean'],
        ], [], [
            'latest_version' => 'dernière version', 'min_version' => 'version minimale',
            'apk_url' => 'lien de l’APK', 'apk_version' => 'version de l’APK',
        ]);

        foreach (['latest_version' => 'dernière version', 'min_version' => 'version minimale', 'apk_version' => 'version de l’APK'] as $field => $label) {
            if (filled($data[$field] ?? null) && ! AppVersions::valid($data[$field])) {
                throw ValidationException::withMessages([$field => "La {$label} doit ressembler à 1.0.10."]);
            }
        }
        if (filled($data['min_version'] ?? null) && filled($data['latest_version'] ?? null)
            && AppVersions::compare($data['min_version'], $data['latest_version']) > 0) {
            throw ValidationException::withMessages(['min_version' => 'La version minimale ne peut pas dépasser la dernière version.']);
        }

        $policy = AppUpdatePolicy::for($data['platform']);
        $policy->fill([
            'latest_version' => $data['latest_version'] ?? null,
            'min_version' => $data['min_version'] ?? null,
            'message' => $data['message'] ?? null,
        ]);

        if ($data['platform'] === 'android') {
            $disk = Storage::disk(AppVersions::APK_DISK);
            if ($file = $request->file('apk')) {
                if (strtolower($file->getClientOriginalExtension()) !== 'apk') {
                    throw ValidationException::withMessages(['apk' => 'Choisissez un fichier .apk.']);
                }
                $old = $policy->apk_path;
                $path = $file->storeAs(AppVersions::APK_DIR, 'castlitpos-'.now()->format('YmdHis').'.apk', AppVersions::APK_DISK);
                $policy->fill(['apk_path' => $path, 'apk_size' => $file->getSize()]);
                if ($old && $old !== $path) {
                    $disk->delete($old);
                }
            } elseif ($request->boolean('remove_apk') && $policy->apk_path) {
                $disk->delete($policy->apk_path);
                $policy->fill(['apk_path' => null, 'apk_size' => null]);
            }
            $policy->fill([
                'apk_url' => $data['apk_url'] ?? null,
                'apk_version' => ($data['apk_version'] ?? null) ?: $policy->latest_version,
            ]);
        }

        $policy->save();

        return redirect()->route('castlit.admin.app-updates')
            ->with('success', ($data['platform'] === 'ios' ? 'iOS' : 'Android').' : règles de mise à jour enregistrées.');
    }

    /** The APK kept on the master, for devices without Google Play. */
    public function apk(): Response
    {
        $policy = AppUpdatePolicy::where('platform', 'android')->first();
        $disk = Storage::disk(AppVersions::APK_DISK);
        abort_unless($policy?->apk_path && $disk->exists($policy->apk_path), 404);

        $name = 'castlitpos-'.($policy->apk_version ?: 'latest').'.apk';

        return $disk->download($policy->apk_path, $name, [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    private function uploadLimit(): string
    {
        $bytes = fn (string $v) => (int) $v * match (strtolower(substr(trim($v), -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
        $upload = (string) ini_get('upload_max_filesize');
        $post = (string) ini_get('post_max_size');

        return $bytes($upload) <= $bytes($post) || $bytes($post) === 0 ? $upload : $post;
    }
}
