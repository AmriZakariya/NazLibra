<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutating_actions_are_recorded_in_audit_log(): void
    {
        $this->seed();

        $this->post(route('catalog.categories.store'), [
            'name' => 'Audit Test',
            'description' => 'Created from audit test',
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'catalog.categories.store',
        ]);

        $log = \DB::table('audit_logs')->where('action', 'catalog.categories.store')->first();
        $properties = json_decode($log->properties, true);

        $this->assertSame('POST', $properties['method']);
        $this->assertSame('catalogue/categories', $properties['path']);
        $this->assertSame('Audit Test', $properties['payload']['name']);
    }

    public function test_read_only_pages_are_not_recorded_in_audit_log(): void
    {
        $this->seed();
        $before = \DB::table('audit_logs')->count();

        $this->get(route('dashboard'))->assertOk();

        $this->assertSame($before, \DB::table('audit_logs')->count());
    }

    public function test_owner_can_filter_activity_log_by_user_and_date(): void
    {
        $this->seed();

        $owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $cashier = User::where('email', 'caisse@librairie-atlas.ma')->firstOrFail();
        $tenant = $owner->currentTenant;

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'action' => 'catalog.items.store',
            'properties' => ['method' => 'POST', 'path' => 'catalogue/articles', 'status_code' => 302],
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $cashier->id,
            'action' => 'pos.store',
            'properties' => ['method' => 'POST', 'path' => 'caisse', 'status_code' => 302],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('profile.activity', [
                'user_id' => $cashier->id,
                'from' => now()->toDateString(),
                'to' => now()->toDateString(),
                'method' => 'POST',
                'q' => 'pos',
            ]))
            ->assertOk()
            ->assertSee('Journal d', false)
            ->assertSee('Filtrer');

        $response = $this->actingAs($owner)
            ->getJson(route('profile.activity.data', [
                'user_id' => $cashier->id,
                'from' => now()->toDateString(),
                'to' => now()->toDateString(),
                'method' => 'POST',
                'q' => 'pos',
            ]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1);
    }

    public function test_only_owner_can_view_activity_log(): void
    {
        $this->seed();

        $cashier = User::where('email', 'caisse@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($cashier)
            ->get(route('profile.activity'))
            ->assertForbidden();
    }

    public function test_profile_exposes_activity_log_shortcut_only_for_owner(): void
    {
        $this->seed();

        $owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $cashier = User::where('email', 'caisse@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($owner)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee(route('profile.activity'), false)
            ->assertSee('Traçabilité propriétaire');

        $this->actingAs($cashier)
            ->get(route('profile'))
            ->assertOk()
            ->assertDontSee(route('profile.activity'), false)
            ->assertDontSee('Traçabilité propriétaire');
    }
}
