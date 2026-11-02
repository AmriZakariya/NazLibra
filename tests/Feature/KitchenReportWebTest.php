<?php

namespace Tests\Feature;

use App\Models\KdsLine;
use App\Models\Tenant;
use App\Models\User;
use App\Support\KitchenReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The kitchen's preparation times had an API and an app screen, but nothing
 * on the web, where an owner reads the rest of their reports.
 */
class KitchenReportWebTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $this->owner = User::first();
    }

    private function kitchen(bool $on): void
    {
        $settings = $this->tenant->settings ?? [];
        $settings['modules']['enabled']['kds'] = $on;
        $this->tenant->update(['settings' => $settings]);
    }

    private function dish(string $id, string $name, string $status, string $fired, ?string $done, bool $startedThenCancelled = false): void
    {
        KdsLine::create([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'fire_id' => 'f-'.$id, 'ticket_id' => 't1',
            'cart_line_id' => 'c-'.$id, 'name' => $name, 'quantity' => 1, 'status' => $status,
            'fired_at' => $fired, 'status_at' => $done, 'cancel_was_started' => $startedThenCancelled,
        ]);
    }

    public function test_the_reports_show_how_long_each_dish_takes(): void
    {
        $this->kitchen(true);
        $this->dish('a', 'Tajine', 'ready', '2026-11-02 12:00:00', '2026-11-02 12:12:00');
        $this->dish('b', 'Tajine', 'served', '2026-11-02 12:30:00', '2026-11-02 12:48:00');
        $this->dish('c', 'Tajine', 'cooking', '2026-11-02 13:00:00', '2026-11-02 13:02:00');
        $this->dish('d', 'Harira', 'cancelled', '2026-11-02 13:00:00', '2026-11-02 13:03:00', true);

        $page = $this->actingAs($this->owner)
            ->get(route('module', ['module' => 'reports', 'section' => 'kitchen', 'from' => '2026-11-02', 'to' => '2026-11-02']))
            ->assertOk();

        $page->assertSee('Cuisine : temps de préparation')
            ->assertSee('data-kitchen-row', false)
            ->assertSee('Tajine')
            // (12 + 18) / 2 = 15 min; the dish still cooking is sent, not timed.
            ->assertSee('15 min')
            ->assertSee('1 annulé(s) sur la période, dont 1 après le début', false);
    }

    public function test_a_shop_without_a_kitchen_screen_has_no_such_tab(): void
    {
        $this->kitchen(false);

        $this->actingAs($this->owner)
            ->get(route('module', ['module' => 'reports']))
            ->assertOk()
            ->assertDontSee('Cuisine : temps de préparation');
    }

    public function test_a_duration_reads_as_minutes_and_seconds(): void
    {
        $this->assertSame('12 min 30 s', KitchenReport::duration(750));
        $this->assertSame('15 min', KitchenReport::duration(900));
        $this->assertSame('45 s', KitchenReport::duration(45));
        $this->assertSame('—', KitchenReport::duration(null));
    }
}
