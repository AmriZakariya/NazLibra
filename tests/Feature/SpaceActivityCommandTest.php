<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ClientActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Inside a client space: `castlit:activity` tells the master what the shop
 * last did, leaving out the platform's own support login.
 */
class SpaceActivityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_space_reports_its_latest_action_and_ignores_our_support_login(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'UTC'));
        $tenant = DB::table('tenants')->insertGetId([
            'name' => 'Spa', 'slug' => 'spa', 'currency' => 'MAD', 'timezone' => 'Africa/Casablanca',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $owner = User::factory()->create(['name' => 'Ahmed', 'email' => 'ahmed@example.com']);
        $support = User::factory()->create(['name' => 'Support', 'email' => 'support@spa.castlitpos.com']);

        $token = fn (User $u, string $device, string $at) => DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class, 'tokenable_id' => $u->id, 'name' => $device,
            'token' => hash('sha256', $device.$u->id), 'abilities' => '["*"]', 'last_used_at' => $at,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $token($owner, 'PAX A920', '2026-11-09 11:00:00');
        $token($support, 'Support laptop', '2026-11-09 11:59:00');

        DB::table('sessions')->insert([
            'id' => 's1', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => Carbon::parse('2026-11-09 10:00:00', 'UTC')->timestamp,
        ]);

        $sale = fn (string $number, string $at, ?int $user = null) => DB::table('sales')->insert([
            'tenant_id' => $tenant, 'user_id' => $user, 'number' => $number, 'total_amount' => 120.5,
            'actor_name_snapshot' => $user ? 'Ahmed' : null, 'sold_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $sale('BL00042', '2026-11-09 11:30:00', $owner->id);
        // 00:30 in Casablanca (UTC+1): today for the shop, though yesterday in UTC.
        $sale('BL00041', '2026-11-08 23:30:00');
        $sale('BL00040', '2026-11-08 22:30:00');
        $sale('BL00001', '2026-10-20 10:00:00');

        $log = fn (User $u, string $label, string $at) => DB::table('audit_logs')->insert([
            'tenant_id' => $tenant, 'user_id' => $u->id, 'action' => 'items.update', 'friendly_action' => $label,
            'subject_reference_snapshot' => 'IT0001', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $log($owner, 'Article modifié', '2026-11-09 09:00:00');
        $log($support, 'Paramètres modifiés', '2026-11-09 11:58:00');

        Artisan::call('castlit:activity', ['--ignore' => ['Support@spa.castlitpos.com']]);
        $data = ClientActivity::parse(Artisan::output());

        $this->assertNotNull($data);
        $this->assertSame('2026-11-09T11:30:00Z', $data['at']);
        $this->assertSame('sale', $data['last']['kind']);
        $this->assertSame('BL00042', $data['last']['number']);
        $this->assertSame('MAD', $data['last']['currency']);
        $this->assertSame(['at' => '2026-11-09T11:00:00Z', 'user' => 'Ahmed', 'device' => 'PAX A920'], $data['app']);
        $this->assertSame('2026-11-09T10:00:00Z', $data['web']['at']);
        $this->assertSame('Article modifié IT0001', $data['change']['label']);
        $this->assertSame(2, $data['sales_today']);
        $this->assertSame(3, $data['sales_7d']);
        $this->assertSame('Vente BL00042 · 120,50 MAD · Ahmed', ClientActivity::describe($data));
    }

    public function test_an_empty_space_reports_no_activity(): void
    {
        Artisan::call('castlit:activity');
        $data = ClientActivity::parse(Artisan::output());

        $this->assertNotNull($data);
        $this->assertNull($data['at']);
        $this->assertNull($data['last']);
        $this->assertSame(0, $data['sales_today']);
    }
}
