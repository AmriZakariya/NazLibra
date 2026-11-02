<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Models\User;
use App\Notifications\TenantProvisionedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * Spaces created before the owner's password was kept had nothing to resend.
 * The platform admin sets a new one in the space and it goes out by email.
 */
class OwnerPasswordResetTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
        tearDown as actsAsMasterTearDown;
    }
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        Notification::fake();
        $this->root = sys_get_temp_dir().'/castlit-owner-'.uniqid();
        File::makeDirectory($this->root, 0755, true);
        config(['castlit.provision.php_bin' => PHP_BINARY]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        $this->actsAsMasterTearDown();
    }

    /** A client folder whose stand-in `artisan` records the call and answers [$status]. */
    private function space(string $status = 'success'): TenantInstall
    {
        $docroot = $this->root.'/spaparis.castlitpos.com';
        File::makeDirectory($docroot, 0755, true);
        File::put($docroot.'/artisan', <<<PHP
<?php
file_put_contents(__DIR__.'/calls.log', json_encode([
    'args' => array_slice(\$argv, 1),
    'password' => getenv('CASTLIT_SUPPORT_PASSWORD'),
])."\\n", FILE_APPEND);
echo json_encode(['status' => '{$status}']), "\\n";
exit(0);
PHP);
        $s = Subscription::create([
            'business_name' => 'Spa Paris', 'contact_name' => 'Majda', 'email' => 'majda@example.com',
            'desired_subdomain' => 'spaparis', 'currency' => 'EUR', 'status' => Subscription::STATUS_APPROVED,
        ]);

        return TenantInstall::create([
            'subscription_id' => $s->id, 'subdomain' => 'spaparis', 'domain' => 'spaparis.castlitpos.com',
            'docroot' => $docroot, 'status' => TenantInstall::STATUS_LIVE, 'access_code' => 'KNS32K',
            'owner_email' => 'majda@example.com',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    public function test_a_new_owner_password_is_set_in_the_space_kept_and_mailed(): void
    {
        $install = $this->space();

        $this->actingAs($this->admin())
            ->post(route('castlit.admin.owner-password', $install->subscription_id))
            ->assertSessionHas('success');

        $call = json_decode(trim(File::get($install->docroot.'/calls.log')), true);
        $this->assertSame(['castlit:support-password', 'majda@example.com'], $call['args']);
        $password = $install->fresh()->owner_password;
        $this->assertSame($call['password'], $password, 'the space and the master agree');
        $this->assertSame(12, strlen($password));

        Notification::assertSentOnDemand(
            TenantProvisionedNotification::class,
            fn (TenantProvisionedNotification $n): bool => $n->details()['password'] === $password
                && $n->details()['isResend'] === false,
        );
    }

    public function test_nothing_is_kept_or_mailed_when_the_space_has_no_such_login(): void
    {
        $install = $this->space('absent');

        $this->actingAs($this->admin())
            ->post(route('castlit.admin.owner-password', $install->subscription_id))
            ->assertSessionHas('error');

        $this->assertNull($install->fresh()->owner_password);
        Notification::assertNothingSent();
    }

    public function test_the_button_is_on_the_clients_page(): void
    {
        $install = $this->space();

        $this->actingAs($this->admin())
            ->get('/castlit-admin/'.$install->subscription_id)
            ->assertOk()
            ->assertSee('data-owner-password', false);
    }
}
