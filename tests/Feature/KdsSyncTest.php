<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\KdsFire;
use App\Models\KdsLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cloud mirror of the kitchen display.
 *
 * Several tills push the same service, batches arrive out of order and more
 * than once, and the same fire can be pushed twice. So the two rules the app
 * uses have to hold here too, or the report would show dishes that were never
 * cooked and prep times that never happened.
 */
class KdsSyncTest extends TestCase
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

    private function token(): string
    {
        return $this->user->createToken('test')->plainTextToken;
    }

    /** @param array<string, mixed> $payload */
    private function sync(array $payload)
    {
        return $this->withToken($this->token())
            ->postJson('/api/v1/kds/sync', $payload);
    }

    /** @return array<string, mixed> */
    private function fire(array $overrides = []): array
    {
        return array_merge([
            'id' => 'kdsf_one',
            'ticket_id' => 'ticket-1',
            'ticket_number' => 'TKT-1',
            'station_id' => 'hot',
            'station_name' => 'Cuisine chaude',
            'fire_seq' => 1,
            'fired_at' => '2026-09-09T19:00:00.000Z',
            'fired_by_user_name' => 'Amina',
            'table_label' => 'Table 4',
            'origin_device_id' => 'install-a',
            'revision' => 0,
            'updated_at' => '2026-09-09T19:00:00.000Z',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'id' => 'kdsl_one',
            'fire_id' => 'kdsf_one',
            'ticket_id' => 'ticket-1',
            'station_id' => 'hot',
            'cart_line_id' => 'cart-1',
            'name' => 'Tajine',
            'quantity' => 2,
            'note' => 'sans olives',
            'status' => 'pending',
            'fired_at' => '2026-09-09T19:00:00.000Z',
            'origin_device_id' => 'install-a',
            'revision' => 0,
            'updated_at' => '2026-09-09T19:00:00.000Z',
        ], $overrides);
    }

    public function test_a_fire_and_its_dishes_are_stored(): void
    {
        $this->sync(['fires' => [$this->fire()], 'lines' => [$this->line()]])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('fires', 1)
            ->assertJsonPath('lines', 1);

        $this->assertDatabaseHas('kds_fires', [
            'id' => 'kdsf_one',
            'tenant_id' => $this->tenant->id,
            'table_label' => 'Table 4',
        ]);
        $this->assertDatabaseHas('kds_lines', [
            'id' => 'kdsl_one',
            'name' => 'Tajine',
            'status' => 'pending',
        ]);
    }

    public function test_pushing_the_same_batch_twice_stores_one_copy(): void
    {
        $payload = ['fires' => [$this->fire()], 'lines' => [$this->line()]];

        $this->sync($payload)->assertOk();
        $this->sync($payload)->assertOk()
            ->assertJsonPath('fires', 0)
            ->assertJsonPath('lines', 0);

        $this->assertSame(1, KdsFire::count());
        $this->assertSame(1, KdsLine::count());
    }

    public function test_two_tills_pushing_the_same_fire_store_one_copy(): void
    {
        // Deterministic client ids mean the same fire from two devices is the
        // same row, which is what stops the report double-counting a dish.
        $this->sync([
            'fires' => [$this->fire(['origin_device_id' => 'install-a'])],
            'lines' => [$this->line(['origin_device_id' => 'install-a'])],
        ])->assertOk();

        $this->sync([
            'fires' => [$this->fire(['origin_device_id' => 'install-b'])],
            'lines' => [$this->line(['origin_device_id' => 'install-b'])],
        ])->assertOk();

        $this->assertSame(1, KdsFire::count());
        $this->assertSame(1, KdsLine::count());
    }

    public function test_a_status_advance_is_applied(): void
    {
        $this->sync(['lines' => [$this->line()]])->assertOk();

        $this->sync(['lines' => [$this->line([
            'status' => 'ready',
            'status_at' => '2026-09-09T19:08:00.000Z',
            'status_by_user_name' => 'Chef',
            'revision' => 2,
            'updated_at' => '2026-09-09T19:08:00.000Z',
        ])]])->assertOk()->assertJsonPath('lines', 1);

        $line = KdsLine::find('kdsl_one');
        $this->assertSame('ready', $line->status);
        $this->assertSame('Chef', $line->status_by_user_name);
    }

    public function test_a_late_packet_cannot_un_plate_a_ready_dish(): void
    {
        // The reason status is a join and not last-write-wins. This packet is
        // strictly NEWER and must still lose.
        $this->sync(['lines' => [$this->line([
            'status' => 'ready',
            'status_at' => '2026-09-09T19:08:00.000Z',
            'updated_at' => '2026-09-09T19:08:00.000Z',
        ])]])->assertOk();

        $this->sync(['lines' => [$this->line([
            'status' => 'cooking',
            'status_at' => '2026-09-09T19:20:00.000Z',
            'revision' => 9,
            'updated_at' => '2026-09-09T19:20:00.000Z',
        ])]])->assertOk()->assertJsonPath('lines', 0);

        $this->assertSame('ready', KdsLine::find('kdsl_one')->status);
    }

    public function test_a_cancellation_wins_over_a_ready_dish(): void
    {
        $this->sync(['lines' => [$this->line(['status' => 'ready'])]])->assertOk();

        $this->sync(['lines' => [$this->line([
            'status' => 'cancelled',
            'cancel_was_started' => true,
            'updated_at' => '2026-09-09T19:05:00.000Z',
        ])]])->assertOk();

        $line = KdsLine::find('kdsl_one');
        $this->assertSame('cancelled', $line->status);
        $this->assertTrue($line->cancel_was_started);
    }

    public function test_an_unknown_status_is_kept_visible_as_pending(): void
    {
        // Losing a dish out of the report is worse than reporting it unstarted.
        $this->sync(['lines' => [$this->line(['status' => 'something-new'])]])
            ->assertOk();

        $this->assertSame('pending', KdsLine::find('kdsl_one')->status);
    }

    public function test_a_row_missing_required_fields_is_refused(): void
    {
        $this->sync([
            'fires' => [['id' => 'kdsf_broken']],
            'lines' => [['id' => 'kdsl_broken', 'name' => 'Tajine']],
        ])->assertOk()
            ->assertJsonPath('fires', 0)
            ->assertJsonPath('lines', 0)
            ->assertJsonPath('skipped', 2);

        $this->assertSame(0, KdsFire::count());
        $this->assertSame(0, KdsLine::count());
    }

    public function test_an_item_from_another_tenant_is_not_linked(): void
    {
        $other = Tenant::create(['slug' => 'autre-boutique', 'name' => 'Autre']);
        $foreign = Item::create([
            'tenant_id' => $other->id,
            'title' => 'Plat ailleurs',
            'type' => 'supply',
            'sale_price' => 10,
        ]);

        $this->sync(['lines' => [$this->line(['item_id' => $foreign->id])]])
            ->assertOk();

        // Stored, because the dish still has to be reported — but not linked
        // to a catalogue row belonging to somebody else.
        $line = KdsLine::find('kdsl_one');
        $this->assertNotNull($line);
        $this->assertNull($line->item_id);
        $this->assertSame('Tajine', $line->name);
    }

    public function test_the_report_measures_prep_time_from_fire_to_ready(): void
    {
        $this->sync([
            'fires' => [$this->fire()],
            'lines' => [$this->line([
                'status' => 'ready',
                'status_at' => '2026-09-09T19:07:00.000Z',
            ])],
        ])->assertOk();

        $response = $this->withToken($this->token())->getJson(
            '/api/v1/kds/report?from=2026-09-09&to=2026-09-09',
        )->assertOk();

        $response->assertJsonPath('dishes.0.dish', 'Tajine')
            ->assertJsonPath('dishes.0.count', 1)
            ->assertJsonPath('dishes.0.average_seconds', 7 * 60);
    }

    public function test_the_report_excludes_unfinished_dishes_from_the_average(): void
    {
        // Counting a dish nobody touched as zero seconds would make a bad
        // service look fast.
        $this->sync([
            'fires' => [$this->fire()],
            'lines' => [
                $this->line([
                    'id' => 'kdsl_done',
                    'status' => 'ready',
                    'status_at' => '2026-09-09T19:10:00.000Z',
                ]),
                $this->line(['id' => 'kdsl_pending', 'status' => 'pending']),
            ],
        ])->assertOk();

        $this->withToken($this->token())->getJson(
            '/api/v1/kds/report?from=2026-09-09&to=2026-09-09',
        )->assertOk()
            ->assertJsonPath('dishes.0.count', 2)
            ->assertJsonPath('dishes.0.timed', 1)
            ->assertJsonPath('dishes.0.average_seconds', 10 * 60);
    }

    public function test_the_report_does_not_time_a_dish_still_cooking(): void
    {
        // A dish moved to "cooking" HAS a status_at, so a null check alone
        // does not exclude it. Timing it would report the moment the cook
        // picked it up as the moment it was finished, understating prep time
        // for exactly the dishes that are running late.
        $this->sync([
            'fires' => [$this->fire()],
            'lines' => [
                $this->line([
                    'id' => 'kdsl_done',
                    'status' => 'ready',
                    'status_at' => '2026-09-09T19:10:00.000Z',
                ]),
                $this->line([
                    'id' => 'kdsl_cooking',
                    'status' => 'cooking',
                    'status_at' => '2026-09-09T19:01:00.000Z',
                ]),
            ],
        ])->assertOk();

        $this->withToken($this->token())->getJson(
            '/api/v1/kds/report?from=2026-09-09&to=2026-09-09',
        )->assertOk()
            ->assertJsonPath('dishes.0.count', 2)
            ->assertJsonPath('dishes.0.timed', 1)
            ->assertJsonPath('dishes.0.average_seconds', 10 * 60);
    }

    public function test_the_report_counts_cancellations_separately(): void
    {
        $this->sync([
            'fires' => [$this->fire()],
            'lines' => [
                $this->line(['id' => 'kdsl_quiet', 'status' => 'cancelled']),
                $this->line([
                    'id' => 'kdsl_loud',
                    'status' => 'cancelled',
                    'cancel_was_started' => true,
                ]),
            ],
        ])->assertOk();

        $this->withToken($this->token())->getJson(
            '/api/v1/kds/report?from=2026-09-09&to=2026-09-09',
        )->assertOk()
            ->assertJsonPath('cancelled', 2)
            ->assertJsonPath('cancelled_after_start', 1)
            ->assertJsonPath('dishes', []);
    }

    public function test_the_report_ignores_a_negative_duration_from_clock_skew(): void
    {
        // status_at BEFORE fired_at: the devices disagreed. Counting it would
        // drag the average below anything a kitchen can do.
        $this->sync([
            'fires' => [$this->fire()],
            'lines' => [$this->line([
                'status' => 'ready',
                'status_at' => '2026-09-09T18:50:00.000Z',
            ])],
        ])->assertOk();

        $this->withToken($this->token())->getJson(
            '/api/v1/kds/report?from=2026-09-09&to=2026-09-09',
        )->assertOk()
            ->assertJsonPath('dishes.0.count', 1)
            ->assertJsonPath('dishes.0.timed', 0)
            ->assertJsonPath('dishes.0.average_seconds', null);
    }

    public function test_another_tenants_kitchen_is_not_reported(): void
    {
        $other = Tenant::create(['slug' => 'autre-cuisine', 'name' => 'Autre']);
        KdsLine::create([
            'id' => 'kdsl_foreign',
            'tenant_id' => $other->id,
            'fire_id' => 'kdsf_foreign',
            'ticket_id' => 'ticket-x',
            'cart_line_id' => 'cart-x',
            'name' => 'Plat ailleurs',
            'quantity' => 1,
            'status' => 'ready',
            'fired_at' => '2026-09-09T19:00:00',
            'status_at' => '2026-09-09T19:05:00',
        ]);

        $this->withToken($this->token())->getJson(
            '/api/v1/kds/report?from=2026-09-09&to=2026-09-09',
        )->assertOk()->assertJsonPath('dishes', []);
    }
}
