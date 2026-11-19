<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Location;
use App\Models\Sale;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\FromPrice;
use App\Support\Money;
use App\Support\OnlineBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * « À partir de » prices: the base price is a floor. The till and both
 * server sale paths refuse a lower unit price; a discount is the way down.
 */
class FromPriceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $this->owner = User::first();
        $this->location = Location::where('tenant_id', $this->tenant->id)->first();
    }

    private function service(string $title, float $price, bool $from): Item
    {
        return Item::create([
            'tenant_id' => $this->tenant->id, 'title' => $title, 'type' => 'service', 'status' => 'active',
            'sale_price' => $price, 'purchase_price' => 0, 'stock_quantity' => 9999, 'price_is_from' => $from, 'bookable_online' => true,
            'unit_id' => Unit::where('tenant_id', $this->tenant->id)->value('id'),
            'tax_id' => Tax::where('tenant_id', $this->tenant->id)->value('id'),
        ]);
    }

    private function sell(array $line, array $extra = [], ?string $appVersion = '1.0.17'): \Illuminate\Testing\TestResponse
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');

        $headers = $appVersion === null ? [] : ['X-App-Version' => $appVersion];

        return $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/pos/sales', $extra + [
            'idempotency_key' => Str::uuid()->toString(),
            'location_id' => $this->location->id,
            'items' => [$line + ['quantity' => 1]],
            'payments' => ['cash' => 5000, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ]);
    }

    public function test_the_floor_is_the_item_price_or_the_variants(): void
    {
        $plain = $this->service('Shampoing', 20, false);
        $from = $this->service('Coloration', 300, true);

        $this->assertNull(FromPrice::minimum($plain));
        $this->assertSame(300.0, FromPrice::minimum($from));
        $this->assertFalse(FromPrice::isBelow($plain, null, 1));
        $this->assertTrue(FromPrice::isBelow($from, null, 299.99));
        $this->assertFalse(FromPrice::isBelow($from, null, 300));
        $this->assertFalse(FromPrice::isBelow($from, null, 450));

        $variant = ItemVariant::create([
            'tenant_id' => $this->tenant->id, 'item_id' => $from->id, 'name' => 'Long', 'attributes' => [],
            'sale_price' => 450, 'sale_price_override' => 450, 'purchase_price' => 0, 'is_active' => true,
        ]);
        $this->assertSame(450.0, FromPrice::minimum($from, $variant));
        $this->assertTrue(FromPrice::isBelow($from, $variant, 400));
    }

    public function test_the_mobile_sale_refuses_a_price_under_the_base_with_a_french_message(): void
    {
        $item = $this->service('Coloration', 300, true);

        $before = Sale::count();
        $response = $this->sell(['item_id' => $item->id, 'unit_price' => 250]);

        $response->assertStatus(422);
        $this->assertStringContainsString('à partir de', $response->json('errors.items.0'));
        $this->assertStringContainsString('remise', $response->json('errors.items.0'));
        $this->assertSame($before, Sale::count(), 'nothing was recorded');
    }

    public function test_the_mobile_sale_accepts_the_base_a_higher_price_and_a_discount(): void
    {
        $item = $this->service('Coloration', 300, true);
        $before = Sale::count();

        $this->sell(['item_id' => $item->id, 'unit_price' => 300])->assertSuccessful();
        $this->sell(['item_id' => $item->id, 'unit_price' => 380])->assertSuccessful();
        $this->sell(['item_id' => $item->id, 'unit_price' => 300], ['discount' => ['type' => 'percent', 'value' => 20]])
            ->assertSuccessful();

        $this->assertSame($before + 3, Sale::count());
        $this->assertEquals(240.0, (float) Sale::orderByDesc('id')->first()->total_amount, 'a discount goes under the base');
    }

    public function test_an_item_that_is_not_a_from_price_can_still_be_sold_lower(): void
    {
        $item = $this->service('Shampoing', 20, false);

        $this->sell(['item_id' => $item->id, 'unit_price' => 5])->assertSuccessful();
    }

    public function test_a_variant_of_a_from_price_item_has_its_own_floor(): void
    {
        $item = $this->service('Coloration', 300, true);
        $variant = ItemVariant::create([
            'tenant_id' => $this->tenant->id, 'item_id' => $item->id, 'name' => 'Long', 'attributes' => [],
            'sale_price' => 450, 'sale_price_override' => 450, 'purchase_price' => 0, 'is_active' => true,
        ]);
        $item->update(['variant_count' => 1]);

        $this->sell(['item_id' => $item->id, 'variant_id' => $variant->id, 'unit_price' => 320])->assertStatus(422);
        $this->sell(['item_id' => $item->id, 'variant_id' => $variant->id, 'unit_price' => 450])->assertSuccessful();
    }

    public function test_the_web_pos_refuses_the_same_line(): void
    {
        $item = $this->service('Coloration', 300, true);
        $cart = fn (float $price) => json_encode([['id' => $item->id, 'quantity' => 1, 'price' => $price]]);
        $before = Sale::count();

        $this->actingAs($this->owner)
            ->post(route('pos.store'), ['cart' => $cart(250), 'cash_amount' => 1000])
            ->assertSessionHasErrors('cart');
        $this->assertSame($before, Sale::count());

        $this->actingAs($this->owner)
            ->post(route('pos.store'), ['cart' => $cart(300), 'cash_amount' => 1000])
            ->assertSessionHasNoErrors();
        $this->assertSame($before + 1, Sale::count());
    }

    public function test_the_flag_is_saved_from_the_back_office_form_and_shown_on_it(): void
    {
        $unit = Unit::where('tenant_id', $this->tenant->id)->first();
        $tax = Tax::where('tenant_id', $this->tenant->id)->first();
        $base = ['unit_id' => $unit->id, 'tax_id' => $tax->id, 'purchase_price' => 0, 'sale_price' => 300,
            'stock_quantity' => 0, 'min_stock_threshold' => 0, 'type' => 'service'];

        $this->actingAs($this->owner)->post(route('catalog.items.store'), $base + ['title' => 'Coloration', 'price_is_from' => 1])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('catalog.items.store'), $base + ['title' => 'Shampoing', 'price_is_from' => 0])
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) Item::where('title', 'Coloration')->value('price_is_from'));
        $this->assertFalse((bool) Item::where('title', 'Shampoing')->value('price_is_from'));

        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'ajouter-service']))
            ->assertOk()->assertSee('name="price_is_from"', false);
    }

    public function test_the_api_and_the_sync_carry_the_flag(): void
    {
        $item = $this->service('Coloration', 300, true);
        // The sync cursor closes on the previous complete second.
        Item::whereKey($item->id)->update(['updated_at' => now()->subMinute()]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $headers = ['X-Tenant-Slug' => $this->tenant->slug, 'X-Location-Id' => (string) $this->location->id];

        $synced = collect($this->withToken($token)->withHeaders($headers)->getJson('/api/v1/sync/items')->assertOk()->json('items'))
            ->firstWhere('id', $item->id);
        $this->assertTrue((bool) $synced['price_is_from']);

        $created = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/items', [
            'local_id' => (string) Str::uuid(), 'title' => 'Henné', 'sale_price' => 100, 'type' => 'service',
            'unit_id' => $item->unit_id, 'tax_id' => $item->tax_id, 'price_is_from' => true,
        ])->assertSuccessful()->json('item');
        $this->assertTrue((bool) $created['price_is_from']);
    }

    public function test_the_import_reads_the_from_price_column(): void
    {
        $csv = "Nom de l'article,Prix de vente,Prix à partir de\nColoration,300,oui\nBrushing,80,non\nSoin,120,\n";
        $path = tempnam(sys_get_temp_dir(), 'from-').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->owner)->post(route('catalog.import'), [
            'kind' => 'services', 'catalog_file' => new UploadedFile($path, 'p.csv', null, null, true),
        ])->assertRedirect();

        $this->assertTrue((bool) Item::where('title', 'Coloration')->value('price_is_from'));
        $this->assertFalse((bool) Item::where('title', 'Brushing')->value('price_is_from'));
        $this->assertFalse((bool) Item::where('title', 'Soin')->value('price_is_from'));
    }

    public function test_prices_read_dès_in_the_back_office_list_the_pos_and_the_booking_page(): void
    {
        $this->service('Coloration', 300, true);
        $this->service('Brushing', 80, false);
        $money = Money::for($this->tenant);

        $this->assertSame('dès '.$money->format(300), FromPrice::label($money, 300, true));
        $this->assertSame($money->format(80), FromPrice::label($money, 80, false));

        $list = $this->actingAs($this->owner)->getJson(route('catalog.data', ['panel' => 'services', 'draw' => 1, 'start' => 0, 'length' => 50]))->assertOk();
        $this->assertStringContainsString('catalog-from', $list->getContent());
        $this->assertSame(1, substr_count($list->getContent(), 'catalog-from'), 'only the from-price service');
        $pos = $this->actingAs($this->owner)->get(route('pos'))->assertOk();
        $pos->assertSee('dès');

        $settings = $this->tenant->settings ?? [];
        $settings['company_profile']['business_mode'] = 'barber';
        $this->tenant->update(['settings' => $settings]);
        OnlineBooking::save($this->tenant->refresh(), ['enabled' => true, 'duration' => 30, 'staff_ids' => []]);
        $this->get(route('booking.show'))->assertOk()
            ->assertSee(__('booking.from').' '.$money->format(300), false)
            ->assertDontSee(__('booking.from').' '.$money->format(80), false);
    }

    public function test_the_web_pos_asks_for_the_price_even_where_prices_are_locked_and_flags_the_tile(): void
    {
        $settings = $this->tenant->settings ?? [];
        $settings['pos']['editable_price'] = false;
        $this->tenant->update(['settings' => $settings]);
        $from = $this->service('Coloration', 300, true);
        $plain = $this->service('Shampoing', 20, false);

        // The tile tells the till script to ask for the price.
        $html = $this->actingAs($this->owner)->get(route('pos'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-id="'.$from->id.'"[^>]*data-price-is-from="1"/s', $html);
        $this->assertMatchesRegularExpression('/data-id="'.$plain->id.'"[^>]*data-price-is-from="0"/s', $html);
        $this->assertStringContainsString('pos-dialog-error', $html);

        // Locked prices: a plain item keeps its catalogue price, a from-price
        // item takes the typed one (never under the floor).
        $before = Sale::count();
        $cart = json_encode([
            ['id' => $from->id, 'quantity' => 1, 'price' => 420],
            ['id' => $plain->id, 'quantity' => 1, 'price' => 1],
        ]);
        $this->actingAs($this->owner)->post(route('pos.store'), ['cart' => $cart, 'cash_amount' => 1000])->assertSessionHasNoErrors();
        $this->assertSame($before + 1, Sale::count());
        $sale = Sale::latest('id')->first();
        $this->assertEqualsWithDelta(440.0, (float) $sale->total_amount, 0.001);
    }

    public function test_an_app_older_than_the_flag_is_accepted_below_the_floor_and_logged(): void
    {
        $item = $this->service('Coloration', 300, true);

        foreach (['1.0.16' => '1.0.16', 'none' => null, 'garbage' => 'abc'] as $label => $version) {
            $response = $this->sell(['item_id' => $item->id, 'unit_price' => 250], [], $version)->assertCreated();
            $sale = Sale::where('number', $response->json('sale.number'))->firstOrFail();
            $marker = $sale->metadata['below_from_price'][0] ?? null;
            $this->assertNotNull($marker, $label);
            $this->assertEquals(250, $marker['unit_price']);
            $this->assertEquals(300, $marker['minimum']);
            $this->assertEquals($item->id, $marker['item_id']);
            $log = \DB::table('audit_logs')->where('action', 'api.price_below_from')->where('subject_id', $sale->id)->first();
            $this->assertNotNull($log, $label);
            $this->assertStringContainsString('trop ancienne', $log->outcome_message);
        }
        $this->assertSame(3, \DB::table('audit_logs')->where('action', 'api.price_below_from')->count());
    }

    public function test_an_app_that_knows_the_flag_is_still_refused_and_the_user_agent_counts(): void
    {
        $item = $this->service('Coloration', 300, true);

        $this->sell(['item_id' => $item->id, 'unit_price' => 250], [], '1.0.17')->assertStatus(422);
        $this->sell(['item_id' => $item->id, 'unit_price' => 250], [], '1.1.0+3')->assertStatus(422);
        $this->assertSame(0, \DB::table('audit_logs')->where('action', 'api.price_below_from')->count());

        $this->flushHeaders();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => 'password', 'device_name' => 'ua-'.Str::uuid()])->json('token');
        $this->withToken($token)->withHeaders(['User-Agent' => 'CastlitPOS/1.0.16 (Android 8.1)'])->postJson('/api/v1/pos/sales', [
            'idempotency_key' => Str::uuid()->toString(), 'location_id' => $this->location->id,
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 100]],
            'payments' => ['cash' => 5000],
        ])->assertCreated();
        $this->assertTrue(FromPrice::FIRST_APP_VERSION_KNOWING_THE_FLAG === '1.0.17');
    }
}
