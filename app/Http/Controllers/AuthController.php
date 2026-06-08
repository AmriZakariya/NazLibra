<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login', [
            'tenant' => Tenant::query()->first(),
            'demoLoginEmail' => \App\Models\User::where('is_active', true)->orderBy('id')->value('email'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides ou compte désactivé.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->forget(['pos_session_locked', 'pos_session_locked_at']);

        return redirect()->intended(route('dashboard'))->with('status', 'Connexion réussie.');
    }

    public function lockSession(Request $request): RedirectResponse
    {
        $request->session()->put('pos_session_locked', true);
        $request->session()->put('pos_session_locked_at', now()->toIso8601String());

        return redirect()->route('session.locked');
    }

    public function lockedScreen(Request $request): View|RedirectResponse
    {
        if (! (bool) $request->session()->get('pos_session_locked', false)) {
            return redirect()->route('dashboard');
        }

        $user = $request->user();
        $tenant = $user?->currentTenant ?: Tenant::query()->first();

        return view('auth.locked', [
            'tenant' => $tenant,
            'user' => $user,
            'lockedAt' => $request->session()->get('pos_session_locked_at'),
            'hasPin' => filled($user?->pin_hash),
        ]);
    }

    public function unlockSession(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pin' => ['required', 'digits_between:4,8'],
        ]);

        $user = $request->user();

        if (! $user?->pin_hash || ! Hash::check($data['pin'], $user->pin_hash)) {
            throw ValidationException::withMessages([
                'pin' => 'PIN incorrect.',
            ]);
        }

        $request->session()->forget(['pos_session_locked', 'pos_session_locked_at']);

        return redirect()->intended(route('dashboard'))->with('status', 'Session déverrouillée.');
    }

    public function unlockWithPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Mot de passe incorrect.',
            ]);
        }

        $request->session()->forget(['pos_session_locked', 'pos_session_locked_at']);

        return redirect()->route('dashboard')->with('status', 'Session déverrouillée. Demandez au propriétaire de définir ou réinitialiser votre PIN.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Déconnexion réussie.');
    }

    public function profile(): View
    {
        $user = Auth::user();
        $tenant = $user?->currentTenant ?: Tenant::query()->firstOrFail();
        $tenantUser = $tenant->users()->whereKey($user?->id)->first();
        $roleKey = (string) ($tenantUser?->pivot?->role ?? '');

        return view('auth.profile', [
            'tenant' => $tenant,
            'active' => 'settings',
            'user' => $user,
            'roleName' => Role::where('tenant_id', $tenant->id)->where('key', $roleKey)->value('name') ?: ucfirst($roleKey ?: 'Aucun rôle'),
            'roleKey' => $roleKey,
            'isOwner' => $this->isOwner($tenant, $user),
            'recentAuditLogs' => $this->isOwner($tenant, $user)
                ? AuditLog::where('tenant_id', $tenant->id)->with('user')->latest()->take(5)->get()
                : collect(),
        ]);
    }

    public function activity(Request $request): View
    {
        $user = $request->user();
        $tenant = $user?->currentTenant ?: Tenant::query()->firstOrFail();

        if (! $this->isOwner($tenant, $user)) {
            abort(403, 'Seul le propriétaire peut consulter le journal d’activité.');
        }

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'method' => ['nullable', 'in:POST,PUT,PATCH,DELETE'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->with('user')
            ->latest();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', \Carbon\Carbon::parse($filters['to'])->endOfDay());
        }

        if (! empty($filters['method'])) {
            $query->where('properties->method', $filters['method']);
        }

        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $query->where(function ($builder) use ($search): void {
                $builder->where('action', 'like', '%'.$search.'%')
                    ->orWhere('subject_type', 'like', '%'.$search.'%')
                    ->orWhere('properties->path', 'like', '%'.$search.'%')
                    ->orWhere('properties->url', 'like', '%'.$search.'%');
            });
        }

        $logs = $query->paginate(20)->withQueryString();

        return view('auth.activity', [
            'tenant' => $tenant,
            'active' => 'settings',
            'user' => $user,
            'logs' => $logs,
            'users' => $tenant->users()->orderBy('name')->get(),
            'filters' => [
                'user_id' => $filters['user_id'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'method' => $filters['method'] ?? '',
                'q' => $filters['q'] ?? '',
            ],
            'totals' => [
                'all' => AuditLog::where('tenant_id', $tenant->id)->count(),
                'today' => AuditLog::where('tenant_id', $tenant->id)->whereDate('created_at', now()->toDateString())->count(),
                'users' => AuditLog::where('tenant_id', $tenant->id)->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
            ],
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:60'],
            'avatar_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'profile_photo' => ['nullable', 'image', 'max:2048'],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => ['nullable', 'confirmed', 'min:8', 'max:120'],
        ]);

        if (! empty($data['password']) && ! Hash::check((string) $data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ]);
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'avatar_color' => $data['avatar_color'] ?? $user->avatar_color,
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }

            $user->profile_photo_path = $request->file('profile_photo')->store('users/profile-photos', 'public');
        } elseif ($request->boolean('remove_profile_photo') && $user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->profile_photo_path = null;
        }

        $user->save();

        return back()->with('status', 'Profil mis à jour.');
    }

    private function isOwner(Tenant $tenant, mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        $tenantUser = $tenant->users()->whereKey($user->id)->first();

        return (string) ($tenantUser?->pivot?->role ?? '') === 'owner';
    }
}
