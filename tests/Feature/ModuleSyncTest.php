<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\AppModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module states reaching the terminals.
 *
 * The back office has had module toggles for a long time and the app ignored
 * them completely — nothing about modules crossed the API — so switching a
 * module off changed the web and left every till exactly as it was. The
 * settings sync now carries the whole map.
 */
class ModuleSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::first();
        $this->user = User::first();
    }

    private function settings()
    {
        return $this->withToken($this->user->createToken('t')->plainTextToken)
            ->getJson('/api/v1/sync/settings');
    }

    private function setModule(string $key, bool $enabled): void
    {
        $settings = $this->tenant->settings ?? [];
        data_set($settings, "modules.enabled.$key", $enabled);
        $this->tenant->update(['settings' => $settings]);
    }

    public function test_the_settings_payload_carries_every_module(): void
    {
        $response = $this->settings()->assertOk();

        $modules = $response->json('modules');
        $this->assertIsArray($modules);

        foreach (array_keys(AppModules::all()) as $key) {
            $this->assertArrayHasKey($key, $modules, "module $key is missing");
            $this->assertIsBool($modules[$key]);
        }
    }

    /**
     * The terminal gates its navigation on these keys, spelled exactly like
     * this in `lib/core/modules/app_module.dart`. Nothing links the two
     * languages, so a rename here would silently switch a destination off on
     * every till — the same shape of drift that cost us a 422 on the printer
     * connection types.
     */
    public function test_the_payload_keeps_the_keys_the_terminal_gates_on(): void
    {
        $gated = [
            'sales', 'catalog', 'stock', 'customers', 'suppliers', 'invoices',
            'online_orders', 'cash_register', 'reports', 'purchases', 'users',
            'loans', 'kds', 'local_sync',
        ];

        $modules = $this->settings()->assertOk()->json('modules');

        foreach ($gated as $key) {
            $this->assertArrayHasKey($key, $modules, "the app gates on '$key' and the payload no longer carries it");
        }
    }

    public function test_the_kitchen_module_exists_and_is_off_by_default(): void
    {
        // Nobody should discover a kitchen screen they did not ask for.
        $this->settings()->assertOk()->assertJsonPath('modules.kds', false);
    }

    public function test_local_sync_exists_and_is_off_by_default(): void
    {
        $this->settings()->assertOk()->assertJsonPath('modules.local_sync', false);
    }

    public function test_switching_the_kitchen_on_reaches_the_terminal(): void
    {
        $this->setModule('kds', true);

        $this->settings()->assertOk()->assertJsonPath('modules.kds', true);
    }

    public function test_switching_a_module_off_reaches_the_terminal(): void
    {
        // The case that was broken: the toggle changed the web and nothing
        // else.
        $this->setModule('sales', false);

        $this->settings()->assertOk()->assertJsonPath('modules.sales', false);
    }

    public function test_a_locked_module_is_always_reported_on(): void
    {
        // Settings and the dashboard cannot be switched off; reporting them
        // as off would black out the app.
        $this->setModule('settings', false);

        $this->settings()->assertOk()
            ->assertJsonPath('modules.settings', true)
            ->assertJsonPath('modules.dashboard', true);
    }

    public function test_core_modules_default_on(): void
    {
        $response = $this->settings()->assertOk();

        foreach (['sales', 'catalog', 'stock', 'customers'] as $key) {
            $this->assertTrue(
                $response->json("modules.$key"),
                "$key should default on",
            );
        }
    }
}
