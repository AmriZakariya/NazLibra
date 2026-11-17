<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Appointments;
use App\Support\OnlineBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A massage is 30 minutes or an hour: the variant that was chosen sets how
 * long the chair is held, on every road into the agenda — the back office,
 * the app and the public booking page.
 */
class VariantDurationTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Africa/Casablanca';

    private Tenant $tenant;

    private User $owner;

    private User $therapist;

    private Item $massage;

    private ItemVariant $short;

    private ItemVariant $long;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $settings = $this->tenant->settings ?? [];
        $settings['company_profile']['business_mode'] = 'beauty';
        $this->tenant->update(['settings' => $settings, 'timezone' => self::TZ]);
        $this->owner = User::first();
        $this->therapist = User::create(['name' => 'Lina Soin', 'email' => 'lina@example.test', 'password' => Hash::make('password')]);
        $this->tenant->users()->attach($this->therapist->id, ['role' => 'cashier']);

        $this->massage = $this->service('Massage', 100, 45);
        $this->short = $this->variant($this->massage, '30 min', 200, 30);
        $this->long = $this->variant($this->massage, '1 h', 400, 60);

        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', self::TZ));
        RateLimiter::clear('booking:'.$this->tenant->id.':127.0.0.1');
    }

    private function service(string $title, float $price, ?int $minutes = null): Item
    {
        return Item::create([
            'tenant_id' => $this->tenant->id, 'title' => $title, 'type' => 'service', 'status' => 'active',
            'sale_price' => $price, 'purchase_price' => 0, 'stock_quantity' => 0, 'duration_minutes' => $minutes,
        ]);
    }

    private function variant(Item $item, string $name, float $price, ?int $minutes): ItemVariant
    {
        $variant = ItemVariant::create([
            'tenant_id' => $this->tenant->id, 'item_id' => $item->id, 'name' => $name, 'attributes' => [],
            'sale_price' => $price, 'sale_price_override' => $price, 'purchase_price' => 0, 'is_active' => true,
            'duration_minutes' => $minutes, 'sort_order' => $item->variants()->count(),
        ]);
        $item->update(['variant_count' => $item->variants()->where('is_active', true)->count()]);

        return $variant;
    }

    private function openBooking(): void
    {
        $hours = array_fill(1, 6, ['open' => '09:00', 'close' => '11:00']) + [7 => ['closed' => true]];
        OnlineBooking::save($this->tenant->refresh(), [
            'enabled' => true, 'duration' => 30, 'hours' => $hours, 'staff_ids' => [$this->therapist->id],
        ]);
        $this->tenant->refresh();
    }

    private function ask(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('booking.store'), $data + [
            'item_id' => $this->massage->id, 'variant_id' => $this->long->id, 'staff_user_id' => '',
            'date' => '2026-11-02', 'time' => '09:00', 'customer_name' => 'Nadia', 'customer_phone' => '0612345678',
        ]);
    }

    public function test_the_length_is_typed_then_the_variants_then_the_services_then_the_default(): void
    {
        $plain = $this->service('Soin', 80);
        $variantWithout = $this->variant($this->massage, '90 min', 500, null);

        $this->assertSame(15, Appointments::minutesFor(15, $this->long, $this->massage));
        $this->assertSame(60, Appointments::minutesFor(null, $this->long, $this->massage));
        $this->assertSame(45, Appointments::minutesFor(null, $variantWithout, $this->massage), 'a variant with no length of its own inherits the service');
        $this->assertSame(45, Appointments::minutesFor(null, null, $this->massage));
        $this->assertSame(30, Appointments::minutesFor(null, null, $plain));
        $this->assertSame(20, Appointments::minutesFor(null, null, $plain, 20));

        $this->assertSame(60, $this->long->minutes());
        $this->assertSame(45, $variantWithout->minutes());
    }

    public function test_the_import_stores_each_variants_duration_and_updates_it(): void
    {
        $csv = fn (string $cell) => "Nom de l'article,Variantes\nGommage,\"{$cell}\"\n";
        $import = function (string $content): void {
            $path = tempnam(sys_get_temp_dir(), 'dur-').'.csv';
            file_put_contents($path, $content);
            $this->actingAs($this->owner)->post(route('catalog.import'), [
                'kind' => 'services', 'catalog_file' => new UploadedFile($path, 'p.csv', null, null, true),
            ])->assertRedirect();
        };

        $import($csv('30 min=200@30; 1 h=400@1h; 90 min=550@2'));
        $variants = Item::where('title', 'Gommage')->sole()->variants()->orderBy('sort_order')->get();
        $this->assertSame([30, 60, null], $variants->pluck('duration_minutes')->all(), 'a duration under 5 minutes is dropped');

        $import($csv('30 min=200@40; 1 h=400@1h; 90 min=550@90'));
        $variants = Item::where('title', 'Gommage')->sole()->variants()->orderBy('sort_order')->get();
        $this->assertSame([40, 60, 90], $variants->pluck('duration_minutes')->all());
        $this->assertSame(3, $variants->count());
    }

    public function test_the_article_page_edits_a_variants_length_and_price(): void
    {
        $page = $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'services', 'edit' => $this->massage->id]));
        $page->assertOk()
            ->assertSee(route('catalog.variants.update', $this->long), false)
            ->assertSee('name="duration_minutes"', false);

        $this->actingAs($this->owner)->put(route('catalog.variants.update', $this->long), [
            'name' => '1 h', 'sale_price' => 450, 'duration_minutes' => 75,
        ])->assertSessionHasNoErrors();

        $this->long->refresh();
        $this->assertSame(75, $this->long->duration_minutes);
        $this->assertEquals(450.0, $this->long->price(), 'the price typed is what the till sells');

        $this->actingAs($this->owner)->put(route('catalog.variants.update', $this->long), [
            'name' => '1 h', 'sale_price' => 450, 'duration_minutes' => 2,
        ])->assertSessionHasErrors('duration_minutes');

        $this->actingAs($this->owner)->put(route('catalog.variants.update', $this->long), [
            'name' => '1 h', 'sale_price' => 450, 'duration_minutes' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->long->fresh()->duration_minutes, 'emptied: the service\'s length applies again');
    }

    public function test_a_variant_added_in_the_back_office_carries_its_length_and_price(): void
    {
        $soin = $this->service('Soin visage', 250, 45);

        $this->actingAs($this->owner)->post(route('catalog.variants.store'), [
            'item_id' => $soin->id, 'name' => 'Express', 'purchase_price' => 0, 'sale_price' => 150,
            'stock_quantity' => 0, 'duration_minutes' => 20,
        ])->assertSessionHasNoErrors();

        $variant = $soin->variants()->sole();
        $this->assertSame(20, $variant->duration_minutes);
        $this->assertEquals(150.0, $variant->price(), 'not the article\'s 250');
        $this->assertSame(1, $soin->fresh()->variant_count, 'the till asks which one');
    }

    public function test_another_shops_variant_cannot_be_edited(): void
    {
        $other = Tenant::create(['name' => 'Autre salon', 'slug' => 'autre-salon', 'timezone' => 'UTC']);
        $item = Item::create(['tenant_id' => $other->id, 'title' => 'Coupe', 'type' => 'service', 'status' => 'active', 'sale_price' => 10, 'purchase_price' => 0, 'stock_quantity' => 0]);
        $foreign = ItemVariant::create(['tenant_id' => $other->id, 'item_id' => $item->id, 'name' => 'X', 'attributes' => [], 'sale_price' => 10, 'purchase_price' => 0]);

        $this->actingAs($this->owner)->put(route('catalog.variants.update', $foreign), [
            'name' => 'Y', 'sale_price' => 1, 'duration_minutes' => 30,
        ])->assertNotFound();
    }

    public function test_the_sync_sends_the_variants_duration(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $location = \App\Models\Location::where('tenant_id', $this->tenant->id)->first();
        ItemVariant::query()->update(['updated_at' => now()->subMinute()]);

        $variants = collect($this->withToken($token)
            ->withHeaders(['X-Tenant-Slug' => $this->tenant->slug, 'X-Location-Id' => (string) $location->id])
            ->getJson('/api/v1/sync/meta')->assertOk()->json('variants'));

        $this->assertSame(60, $variants->firstWhere('id', $this->long->id)['duration_minutes']);
        $this->assertSame(30, $variants->firstWhere('id', $this->short->id)['duration_minutes']);
    }

    public function test_an_appointment_for_a_variant_lasts_as_long_as_the_variant(): void
    {
        $base = ['starts_at' => '2026-11-03', 'time' => '10:00', 'customer_name' => 'Nadia', 'item_id' => $this->massage->id, 'duration_minutes' => ''];
        $this->actingAs($this->owner)->post(route('appointments.store'), $base + ['variant_id' => $this->long->id])
            ->assertSessionHasNoErrors();
        $a = Appointment::latest('id')->first();
        $this->assertSame(60, $a->duration_minutes);
        $this->assertSame($this->long->id, $a->variant_id);
        $this->assertSame('Massage — 1 h', $a->service_name);

        $this->actingAs($this->owner)->post(route('appointments.store'), $base + ['time' => '12:00'])
            ->assertSessionHasNoErrors();
        $this->assertSame(45, Appointment::latest('id')->first()->duration_minutes, 'no variant: the service\'s length');
    }

    public function test_a_variant_of_another_service_is_refused(): void
    {
        $other = $this->service('Soin', 80, 20);
        $foreign = $this->variant($other, 'Court', 80, 20);

        $this->actingAs($this->owner)->post(route('appointments.store'), [
            'starts_at' => '2026-11-03', 'time' => '10:00', 'customer_name' => 'Nadia',
            'item_id' => $this->massage->id, 'variant_id' => $foreign->id,
        ])->assertSessionHasErrors('variant_id');
        $this->assertSame(0, Appointment::count());
    }

    public function test_the_app_books_a_variant_through_the_api(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');

        $response = $this->withToken($token)->postJson('/api/v1/appointments', [
            'starts_at' => '2026-11-03 10:00', 'customer_name' => 'Nadia',
            'item_id' => $this->massage->id, 'variant_id' => $this->short->id,
        ])->assertCreated();

        $response->assertJsonPath('appointment.duration_minutes', 30)
            ->assertJsonPath('appointment.variant_id', $this->short->id);

        $id = $response->json('appointment.id');
        $this->withToken($token)->patchJson("/api/v1/appointments/{$id}", ['variant_id' => $this->long->id])
            ->assertOk()->assertJsonPath('appointment.duration_minutes', 60);
    }

    public function test_the_public_page_asks_which_variant_with_its_price_and_length(): void
    {
        $this->openBooking();

        $this->get(route('booking.show'))->assertOk()
            ->assertSee('name="variant_id"', false)
            ->assertSee('30 min')->assertSee('1 h')
            ->assertSee(__('booking.from').' ', false);
    }

    public function test_the_slots_follow_the_chosen_variants_length(): void
    {
        $this->openBooking();

        $slots = fn (ItemVariant $v) => $this->getJson(route('booking.slots', [
            'date' => '2026-11-02', 'item' => $this->massage->id, 'variant' => $v->id,
        ]))->assertOk()->json('slots');

        $this->assertSame(['09:00', '09:30', '10:00', '10:30'], $slots($this->short));
        $this->assertSame(['09:00', '09:30', '10:00'], $slots($this->long));
    }

    public function test_a_request_is_booked_for_the_variants_length_and_names_it(): void
    {
        $this->openBooking();

        $this->ask(['time' => '10:30'])->assertSessionHasErrors('time');

        $this->ask(['time' => '10:00'])->assertSessionHasNoErrors();
        $request = Appointment::sole();
        $this->assertSame(60, $request->duration_minutes);
        $this->assertSame($this->long->id, $request->variant_id);
        $this->assertSame('Massage — 1 h', $request->service_name);
        $this->assertSame('requested', $request->status);
    }

    public function test_a_service_with_variants_cannot_be_requested_without_choosing_one(): void
    {
        $this->openBooking();

        $this->ask(['variant_id' => ''])->assertSessionHasErrors('variant_id');
        $this->assertSame(0, Appointment::count());

        $foreign = $this->variant($this->service('Soin', 80, 20), 'Court', 80, 20);
        $this->ask(['variant_id' => $foreign->id])->assertSessionHasErrors('variant_id');
    }

    public function test_a_service_without_variants_books_as_before(): void
    {
        $this->openBooking();
        $soin = $this->service('Soin visage', 250, 45);

        $this->ask(['item_id' => $soin->id, 'variant_id' => ''])->assertSessionHasNoErrors();

        $this->assertSame(45, Appointment::sole()->duration_minutes);
        $this->assertNull(Appointment::sole()->variant_id);
        $this->assertSame('Soin visage', Appointment::sole()->service_name);
    }
}
