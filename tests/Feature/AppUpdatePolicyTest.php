<?php

namespace Tests\Feature;

use App\Models\AppUpdatePolicy;
use App\Models\User;
use App\Support\AppVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * Soft and forced app updates: castlit-admin sets the latest and minimum
 * versions per platform, the app asks the master at launch.
 */
class AppUpdatePolicyTest extends TestCase
{
    use ActsAsMasterInstall;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    public function test_versions_compare_as_numbers_not_text(): void
    {
        $this->assertLessThan(0, AppVersions::compare('1.0.9', '1.0.10'));
        $this->assertSame(0, AppVersions::compare('1.0', '1.0.0'));
        $this->assertSame(0, AppVersions::compare('v1.0.10+10', '1.0.10'));
        $this->assertGreaterThan(0, AppVersions::compare('2.0.0', '1.99.99'));
        $this->assertFalse(AppVersions::valid('beta'));
    }

    public function test_the_app_is_told_ok_soft_or_force(): void
    {
        AppUpdatePolicy::create(['platform' => 'android', 'latest_version' => '1.0.12', 'min_version' => '1.0.10', 'message' => 'Tickets corrigés']);
        $ask = fn (string $v) => $this->getJson('/api/v1/public/app-version?platform=android&version='.$v)->assertOk()->json();

        $this->assertSame('force', $ask('1.0.9')['status']);
        $soft = $ask('1.0.10');
        $this->assertSame('soft', $soft['status']);
        $this->assertSame('Tickets corrigés', $soft['message']);
        $this->assertSame('https://play.google.com/store/apps/details?id=com.castlitpos.app', $soft['store_url']);
        $this->assertSame('ok', $ask('1.0.12')['status']);
        $this->assertSame('ok', $ask('1.1.0')['status']);
        // A version it cannot read is never blocked.
        $this->assertSame('ok', $ask('dev')['status']);
    }

    public function test_a_platform_without_rules_is_never_asked_to_update(): void
    {
        AppUpdatePolicy::create(['platform' => 'android', 'min_version' => '9.0.0']);

        $ios = $this->getJson('/api/v1/public/app-version?platform=ios&version=1.0.0')->assertOk()->json();
        $this->assertSame('ok', $ios['status']);
        $this->assertNull($ios['apk_url']);
        $this->getJson('/api/v1/public/app-version?platform=windows&version=1.0.0')->assertUnprocessable();
    }

    public function test_the_admin_sets_the_rules_and_an_apk_the_app_can_download(): void
    {
        Storage::fake(AppVersions::APK_DISK);

        $this->actingAs($this->admin())
            ->get(route('castlit.admin.app-updates'))
            ->assertOk()->assertSee('Mises à jour de l’app')->assertSee('data-platform="ios"', false);

        $this->actingAs($this->admin())->post(route('castlit.admin.app-updates.save'), [
            'platform' => 'android', 'latest_version' => '1.0.12', 'min_version' => '1.0.10',
            'apk' => UploadedFile::fake()->create('app-release.apk', 2048, 'application/vnd.android.package-archive'),
        ])->assertSessionHas('success');

        $policy = AppUpdatePolicy::where('platform', 'android')->sole();
        $this->assertSame('1.0.12', $policy->apk_version, 'the APK is taken to be the latest unless told');
        Storage::disk(AppVersions::APK_DISK)->assertExists($policy->apk_path);

        $answer = $this->getJson('/api/v1/public/app-version?platform=android&version=1.0.9')->json();
        $this->assertSame('force', $answer['status']);
        $this->assertStringContainsString('/app/castlitpos.apk?v=1.0.12', $answer['apk_url']);

        $download = $this->get('/app/castlitpos.apk?v=1.0.12')->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('castlitpos-1.0.12.apk', (string) $download->headers->get('Content-Disposition'));
    }

    public function test_bad_versions_are_refused_with_a_reason(): void
    {
        $this->actingAs($this->admin())->post(route('castlit.admin.app-updates.save'), [
            'platform' => 'ios', 'latest_version' => '1.0.10', 'min_version' => '1.1.0',
        ])->assertSessionHasErrors(['min_version' => 'La version minimale ne peut pas dépasser la dernière version.']);

        $this->actingAs($this->admin())->post(route('castlit.admin.app-updates.save'), [
            'platform' => 'ios', 'latest_version' => 'nouvelle',
        ])->assertSessionHasErrors('latest_version');

        $this->assertSame(0, AppUpdatePolicy::count());
    }

    public function test_no_apk_means_no_download(): void
    {
        $this->get('/app/castlitpos.apk')->assertNotFound();
    }
}
