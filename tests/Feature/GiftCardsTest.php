<?php

namespace Tests\Feature;

use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Item;
use App\Models\Location;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\GiftCards\GiftCardService;
use App\Support\AppModules;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gift cards: sold as a sale line that is not revenue, spent as a payment that
 * is revenue but not cash, refunded back onto the card, never overdrawn.
 */
class GiftCardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Location $location;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $this->owner = User::first();
        $this->location = Location::where('tenant_id', $this->tenant->id)->first();
        $this->setModules(['gift_cards' => true, 'packages' => true]);
        $this->token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
    }

    private function setModules(array $enabled): void
    {
        $settings = $this->tenant->settings ?? [];
        $settings['modules']['enabled'] = $enabled + (array) data_get($settings, 'modules.enabled', []);
        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();
        // The signed-in user (and the shop behind it) is cached by the guard
        // for the length of a test; a real request starts clean.
        app('auth')->forgetGuards();
    }

    private function service(string $title = 'Coupe', float $price = 100): Item
    {
        return Item::create([
            'tenant_id' => $this->tenant->id, 'title' => $title, 'type' => 'service', 'status' => 'active',
            'sale_price' => $price, 'purchase_price' => 0, 'stock_quantity' => 9999,
            'unit_id' => Unit::where('tenant_id', $this->tenant->id)->value('id'),
            'tax_id' => Tax::where('tenant_id', $this->tenant->id)->value('id'),
        ]);
    }

    private function sell(array $items, array $payments, array $extra = [], string $version = '1.0.17'): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->withHeaders(['X-App-Version' => $version])->postJson('/api/v1/pos/sales', $extra + [
            'idempotency_key' => Str::uuid()->toString(),
            'location_id' => $this->location->id,
            'items' => $items,
            'payments' => $payments,
        ]);
    }

    /** Sells a card of $amount for cash and returns its model. */
    private function issueCard(float $amount, ?string $code = null): GiftCard
    {
        $line = ['kind' => 'gift_card', 'quantity' => 1, 'unit_price' => $amount] + ($code ? ['gift_card_code' => $code] : []);
        $this->sell([$line], ['cash' => $amount])->assertCreated();

        return GiftCard::latest('id')->firstOrFail();
    }

    public function test_a_code_is_short_readable_and_without_ambiguous_characters(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = GiftCardService::randomCode();
            $this->assertSame(8, strlen($code));
            $this->assertDoesNotMatchRegularExpression('/[ILO01]/', $code);
            $this->assertTrue(GiftCardService::isValidFormat($code));
        }
        $this->assertSame('ABCD-EFGH', GiftCardService::display('abcd efgh'));
        $this->assertSame('ABCDEFGH', GiftCardService::normalize('abcd-efgh'));
    }

    public function test_selling_a_card_creates_it_once_and_is_not_revenue(): void
    {
        $key = Str::uuid()->toString();
        $items = [['kind' => 'gift_card', 'quantity' => 1, 'unit_price' => 200, 'recipient_name' => 'Amie']];
        $first = $this->sell($items, ['cash' => 200], ['idempotency_key' => $key])->assertCreated();
        $again = $this->sell($items, ['cash' => 200], ['idempotency_key' => $key])->assertOk();

        $this->assertSame(1, GiftCard::count(), 'a replayed sale never issues a second card');
        $card = GiftCard::first();
        $this->assertEquals(200, $card->initial_amount);
        $this->assertEquals(200, $card->balance);
        $this->assertSame('active', $card->status);
        $this->assertSame('Amie', $card->recipient_name);
        $this->assertSame($card->code, $first->json('gift_cards.0.code'));
        $this->assertSame($card->code, $again->json('gift_cards.0.code'), 'the replay answers with the same card');
        $this->assertSame(1, GiftCardTransaction::where('type', 'sale')->count());

        $sale = Sale::latest('id')->first();
        $this->assertEquals(200, $sale->total_amount);
        $this->assertEquals(200, $sale->non_revenue_amount);
        $this->assertEquals(0, $sale->tax_amount, 'no VAT on a card');
        $this->assertSame('gift_card', SaleItem::where('sale_id', $sale->id)->value('kind'));
    }

    public function test_the_code_the_offline_till_printed_is_kept_unless_it_is_taken_or_malformed(): void
    {
        $mine = $this->issueCard(50, 'ABCDEFGH');
        $this->assertSame('ABCDEFGH', $mine->code);

        $clash = $this->issueCard(50, 'ABCDEFGH');
        $this->assertNotSame('ABCDEFGH', $clash->code, 'a code already taken is replaced, and the till is told the real one');
        $this->assertTrue(GiftCardService::isValidFormat($clash->code));

        $bad = $this->issueCard(50, 'O0I1lll!');
        $this->assertTrue(GiftCardService::isValidFormat($bad->code));
    }

    public function test_the_default_expiry_is_twelve_months_and_follows_the_shop_setting(): void
    {
        $tz = $this->tenant->timezone ?: 'Africa/Casablanca';
        $card = $this->issueCard(50);
        $this->assertSame(now($tz)->startOfDay()->addMonthsNoOverflow(12)->toDateString(), $card->expires_at->toDateString());

        $settings = $this->tenant->settings;
        $settings['gift_cards']['validity_months'] = 0;
        $this->tenant->update(['settings' => $settings]);
        app('auth')->forgetGuards();
        $this->assertNull($this->issueCard(50)->expires_at, '0 means it never expires');

        $settings['gift_cards']['validity_months'] = 3;
        $this->tenant->update(['settings' => $settings]);
        app('auth')->forgetGuards();
        $this->assertSame(now($tz)->startOfDay()->addMonthsNoOverflow(3)->toDateString(), $this->issueCard(50)->expires_at->toDateString());
    }

    public function test_paying_with_a_card_is_revenue_but_not_cash_and_leaves_a_balance_when_partial(): void
    {
        $card = $this->issueCard(100);
        $svc = $this->service('Coupe', 60);

        $this->sell([['item_id' => $svc->id, 'quantity' => 1]], ['gift_card' => 60], ['gift_card_code' => $card->displayCode()])
            ->assertCreated()
            ->assertJsonPath('gift_card_redeemed.0.balance_after', 40);

        $card->refresh();
        $this->assertEquals(40, $card->balance);
        $this->assertSame('active', $card->status);
        $sale = Sale::latest('id')->first();
        $this->assertEquals(60, $sale->total_amount);
        $this->assertEquals(0, $sale->non_revenue_amount);
        $this->assertEquals(60, $sale->payments()->where('method', 'gift_card')->sum('amount'));
        $this->assertEquals(0, data_get($sale->metadata, 'cash_register.cash_drawer_in'), 'nothing went in the drawer');
        $this->assertSame('redeem', GiftCardTransaction::latest('id')->first()->type);

        // The rest in cash, the card spent down to zero → used.
        $svc2 = $this->service('Brushing', 70);
        $this->sell([['item_id' => $svc2->id, 'quantity' => 1]], ['gift_card' => 40, 'cash' => 30], ['gift_card_code' => $card->code])->assertCreated();
        $card->refresh();
        $this->assertEquals(0, $card->balance);
        $this->assertSame('used', $card->status);
    }

    public function test_a_card_cannot_pay_more_than_its_balance_nor_more_than_the_total(): void
    {
        $card = $this->issueCard(50);
        $svc = $this->service('Coupe', 100);

        $this->sell([['item_id' => $svc->id, 'quantity' => 1]], ['gift_card' => 80], ['gift_card_code' => $card->code])
            ->assertStatus(422)->assertJsonPath('error', 'gift_card_insufficient_balance');
        $cheap = $this->service('Barbe', 20);
        $this->sell([['item_id' => $cheap->id, 'quantity' => 1]], ['gift_card' => 50], ['gift_card_code' => $card->code])
            ->assertStatus(422)->assertJsonPath('error', 'gift_card_over_total');
        $this->sell([['item_id' => $cheap->id, 'quantity' => 1]], ['gift_card' => 10], ['gift_card_code' => 'ZZZZZZZZ'])
            ->assertStatus(422)->assertJsonPath('error', 'gift_card_not_found');
        $this->assertEquals(50, $card->fresh()->balance);
    }

    public function test_the_balance_cannot_go_negative_when_two_tills_spend_it_at_once(): void
    {
        $card = $this->issueCard(100);
        $service = app(GiftCardService::class);
        // Two tills both read the card at 100 before either spent.
        $stale = GiftCard::find($card->id);
        $sale = Sale::latest('id')->first();

        $service->redeem($this->tenant, $card->code, 70, $sale);
        $this->assertStringContainsString('Solde insuffisant', $this->refusalOf(fn () => $service->redeem($this->tenant, $stale->code, 70, $sale)));

        $card->refresh();
        $this->assertEquals(30, $card->balance);
        $this->assertSame(1, GiftCardTransaction::where('type', 'redeem')->count());
        // The guard is in the UPDATE itself: a debit that skipped the checks still cannot win.
        $debited = GiftCard::whereKey($card->id)->where('balance', '>=', 70)->update(['balance' => \DB::raw('balance - 70')]);
        $this->assertSame(0, $debited);
    }

    private function refusalOf(callable $fn): string
    {
        try {
            $fn();
        } catch (\App\Exceptions\GiftCardException $e) {
            return $e->getMessage();
        }
        $this->fail('expected a refusal');
    }

    public function test_an_expired_cancelled_or_spent_card_is_refused(): void
    {
        $svc = $this->service('Coupe', 10);
        $expired = $this->issueCard(50);
        $expired->update(['expires_at' => now()->subDays(2)->toDateString()]);
        $this->sell([['item_id' => $svc->id, 'quantity' => 1]], ['gift_card' => 10], ['gift_card_code' => $expired->code])
            ->assertStatus(422)->assertJsonPath('error', 'gift_card_expired');
        $this->assertSame('expired', $expired->fresh()->status);

        $cancelled = $this->issueCard(50);
        app(GiftCardService::class)->cancel($this->tenant, $cancelled, 'perdue', $this->owner->id);
        $this->sell([['item_id' => $svc->id, 'quantity' => 1]], ['gift_card' => 10], ['gift_card_code' => $cancelled->code])
            ->assertStatus(422)->assertJsonPath('error', 'gift_card_cancelled');

        $spent = $this->issueCard(10);
        $this->sell([['item_id' => $svc->id, 'quantity' => 1]], ['gift_card' => 10], ['gift_card_code' => $spent->code])->assertCreated();
        $this->sell([['item_id' => $svc->id, 'quantity' => 1]], ['gift_card' => 10], ['gift_card_code' => $spent->code])
            ->assertStatus(422)->assertJsonPath('error', 'gift_card_used');
    }

    public function test_a_failed_sale_gives_the_card_its_money_back(): void
    {
        $card = $this->issueCard(100);
        $svc = $this->service('Coupe', 60);
        // A package use that cannot succeed → the whole sale is refused, the card untouched.
        $this->sell([
            ['item_id' => $svc->id, 'quantity' => 1],
            ['item_id' => $svc->id, 'quantity' => 1, 'kind' => 'package_use', 'customer_package_id' => 999],
        ], ['gift_card' => 60], ['gift_card_code' => $card->code])->assertStatus(422);
        $this->assertEquals(100, $card->fresh()->balance);
    }

    public function test_a_gift_card_cannot_buy_a_gift_card_nor_take_a_discount(): void
    {
        $card = $this->issueCard(100);
        $this->sell([['kind' => 'gift_card', 'quantity' => 1, 'unit_price' => 20]], ['gift_card' => 20], ['gift_card_code' => $card->code])
            ->assertStatus(422)->assertJsonPath('error', 'gift_card_for_gift_card');
        $svc = $this->service('Coupe', 100);
        $this->sell([['kind' => 'gift_card', 'quantity' => 1, 'unit_price' => 20], ['item_id' => $svc->id, 'quantity' => 1]], ['cash' => 500], ['discount' => ['type' => 'fixed', 'value' => 10]])
            ->assertStatus(422);
    }

    public function test_the_module_switch_turns_selling_and_spending_off(): void
    {
        $this->setModules(['gift_cards' => false]);
        $this->sell([['kind' => 'gift_card', 'quantity' => 1, 'unit_price' => 20]], ['cash' => 20])->assertStatus(422);
        $this->assertSame(0, GiftCard::count());
        $this->assertFalse(AppModules::enabled($this->tenant->refresh(), 'gift_cards'));
    }

    public function test_the_module_is_on_by_default_for_beauty_and_barber_only(): void
    {
        $fresh = Tenant::first();
        $settings = $fresh->settings ?? [];
        unset($settings['modules']);
        foreach (['barber' => true, 'beauty' => true, 'retail' => false] as $mode => $expected) {
            $settings['company_profile']['business_mode'] = $mode;
            $fresh->update(['settings' => $settings]);
            $this->assertSame($expected, AppModules::enabled($fresh->refresh(), 'gift_cards'), $mode);
            $this->assertSame($expected, AppModules::enabled($fresh, 'packages'), $mode);
        }
    }

    public function test_refunding_a_sale_paid_by_card_puts_the_money_back_on_the_card_not_in_the_drawer(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/cash-register/open', ['opening_amount' => 0])->assertSuccessful();
        $card = $this->issueCard(100);
        $svc = $this->service('Coupe', 100);
        $sale = Sale::find($this->sell([['item_id' => $svc->id, 'quantity' => 1]], ['gift_card' => 60, 'cash' => 40], ['gift_card_code' => $card->code])->json('sale.id'));
        $this->assertEquals(40, $card->fresh()->balance);

        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash', 'refund_reason' => 'test',
        ])->assertCreated();

        $card->refresh();
        $this->assertEquals(100, $card->balance, 'the 60 paid by card is back on the card');
        $refund = GiftCardTransaction::where('type', 'refund')->first();
        $this->assertEquals(60, $refund->amount);
        $drawerOut = \DB::table('cash_register_movements')->where('type', 'sale_refund_cash')->sum('amount');
        $this->assertEquals(40, $drawerOut, 'only the cash part leaves the drawer');
    }

    public function test_a_partial_refund_restores_its_share_and_a_spent_card_is_reopened(): void
    {
        $card = $this->issueCard(60);
        $svc = $this->service('Coupe', 120);
        $sale = Sale::find($this->sell([['item_id' => $svc->id, 'quantity' => 2, 'unit_price' => 60]], ['gift_card' => 60, 'cash' => 60], ['gift_card_code' => $card->code])->json('sale.id'));
        $this->assertSame('used', $card->fresh()->status);
        $line = $sale->items()->first();

        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
            'return_lines' => [['sale_item_id' => $line->id, 'quantity' => 1]],
        ])->assertCreated();

        $card->refresh();
        $this->assertEquals(30, $card->balance, 'half the sale, half of the 60 paid by card');
        $this->assertSame('active', $card->status);
    }

    public function test_a_card_that_was_sold_can_be_refunded_only_while_unused(): void
    {
        $card = $this->issueCard(100);
        $sale = Sale::latest('id')->first();
        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
        ])->assertCreated();
        $this->assertSame('cancelled', $card->fresh()->status);
        $this->assertEquals(0, $card->fresh()->balance);
        $this->assertEquals(100, \App\Models\SaleReturn::first()->non_revenue_amount, 'not a revenue deduction');

        $used = $this->issueCard(100);
        $svc = $this->service('Coupe', 30);
        $this->sell([['item_id' => $svc->id, 'quantity' => 1]], ['gift_card' => 30], ['gift_card_code' => $used->code])->assertCreated();
        $cardSale = $used->sale;
        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$cardSale->id}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
        ])->assertStatus(422);
        $this->assertSame('active', $used->fresh()->status);
    }

    public function test_the_reports_keep_cards_sold_and_cards_spent_apart(): void
    {
        $before = $this->withToken($this->token)->getJson('/api/v1/statistics/overview')->json('kpis.gross_revenue');
        $beforeDash = $this->withToken($this->token)->getJson('/api/v1/dashboard')->json('kpis.gross_revenue');
        $beforeMethods = collect($this->withToken($this->token)->getJson('/api/v1/statistics/by-payment')->json('breakdown'))->pluck('amount', 'method');
        $card = $this->issueCard(100);                // cash in 100, revenue 0
        $svc = $this->service('Coupe', 60);
        $this->sell([['item_id' => $svc->id, 'quantity' => 1]], ['gift_card' => 60], ['gift_card_code' => $card->code])->assertCreated(); // revenue 60, cash 0

        $kpis = $this->withToken($this->token)->getJson('/api/v1/statistics/overview')->assertOk()->json('kpis');
        $this->assertEquals($before + 60, $kpis['gross_revenue'], 'only the 60 spent is revenue, not the 100 card sold');
        $this->assertEquals(100, $kpis['gift_cards_sold']);

        $dash = $this->withToken($this->token)->getJson('/api/v1/dashboard')->assertOk()->json('kpis');
        $this->assertEquals($beforeDash + 60, $dash['gross_revenue']);
        $this->assertEquals(100, $dash['gift_cards_sold']);
        $this->assertEquals(60, $dash['gift_cards_redeemed']);

        $methods = collect($this->withToken($this->token)->getJson('/api/v1/statistics/by-payment')->json('breakdown'))->pluck('amount', 'method');
        $this->assertEquals(($beforeMethods['cash'] ?? 0) + 100, $methods['cash']);
        $this->assertEquals(60, $methods['gift_card']);

        $products = collect($this->withToken($this->token)->getJson('/api/v1/statistics/by-product')->json('products'))->pluck('name');
        $this->assertFalse($products->contains(fn ($n) => str_contains($n, 'Carte cadeau')), 'a card is not a product');

        // The back office dashboard.
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertSee('Cartes cadeaux vendues');
    }

    public function test_the_lookup_shows_the_balance_and_says_why_a_card_cannot_be_used(): void
    {
        $card = $this->issueCard(80);
        $this->withToken($this->token)->getJson('/api/v1/gift-cards/'.$card->displayCode())
            ->assertOk()->assertJsonPath('card.balance', 80)->assertJsonPath('card.usable', true);
        $this->withToken($this->token)->getJson('/api/v1/gift-cards/NOPENOPE')->assertStatus(404)->assertJsonPath('error', 'gift_card_not_found');
        $card->update(['expires_at' => now()->subDay()->toDateString()]);
        $this->withToken($this->token)->getJson('/api/v1/gift-cards/'.$card->code)->assertStatus(422)->assertJsonPath('error', 'gift_card_expired');
    }

    public function test_the_web_till_sells_and_spends_cards_the_same_way(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('pos.store'), ['cart' => json_encode([['kind' => 'gift_card', 'quantity' => 1, 'price' => 150]]), 'cash_amount' => 150])
            ->assertSessionHasNoErrors();
        $card = GiftCard::firstOrFail();
        $this->assertEquals(150, $card->balance);
        $this->assertEquals(150, Sale::latest('id')->first()->non_revenue_amount);

        $svc = $this->service('Coupe', 100);
        $this->post(route('pos.store'), ['cart' => json_encode([['id' => $svc->id, 'quantity' => 1]]), 'gift_card_amount' => 100, 'gift_card_code' => $card->displayCode()])
            ->assertSessionHasNoErrors();
        $this->assertEquals(50, $card->fresh()->balance);

        $this->post(route('pos.store'), ['cart' => json_encode([['id' => $svc->id, 'quantity' => 1]]), 'gift_card_amount' => 100, 'gift_card_code' => $card->code])
            ->assertSessionHasErrors();
        $this->assertEquals(50, $card->fresh()->balance);

        $this->getJson(route('gift-cards.lookup', ['code' => $card->code]))->assertOk()->assertJsonPath('card.balance', 50);
    }

    public function test_the_web_refund_restores_the_card_too(): void
    {
        $card = $this->issueCard(100);
        $svc = $this->service('Coupe', 100);
        $this->actingAs($this->owner);
        $this->post(route('pos.store'), ['cart' => json_encode([['id' => $svc->id, 'quantity' => 1]]), 'gift_card_amount' => 100, 'gift_card_code' => $card->code]);
        $sale = Sale::latest('id')->first();
        $this->assertEquals(0, $card->fresh()->balance);

        $this->post(route('sales.refund', $sale), ['refund_method' => 'cash', 'refund_reason' => 'erreur'])->assertSessionHasNoErrors();
        $this->assertEquals(100, $card->fresh()->balance);
        $this->assertSame('active', $card->fresh()->status);
    }

    public function test_back_office_lists_searches_shows_history_cancels_and_adjusts_with_a_reason(): void
    {
        $card = $this->issueCard(100);
        $this->actingAs($this->owner);

        $this->get(route('gift-cards'))->assertOk()->assertSee($card->displayCode());
        $this->get(route('gift-cards', ['q' => substr($card->code, 2, 4)]))->assertOk()->assertSee($card->displayCode());
        $this->get(route('gift-cards', ['q' => 'ZZZZ']))->assertOk()->assertDontSee($card->displayCode());
        $this->get(route('gift-cards.show', $card))->assertOk()->assertSee('Historique');

        $this->post(route('gift-cards.adjust', $card), ['amount' => -30, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('gift-cards.adjust', $card), ['amount' => -30, 'reason' => 'geste inverse'])->assertSessionHasNoErrors();
        $this->assertEquals(70, $card->fresh()->balance);
        $this->post(route('gift-cards.adjust', $card), ['amount' => -500, 'reason' => 'trop'])->assertSessionHasErrors('amount');
        $this->assertSame('adjust', GiftCardTransaction::latest('id')->first()->type);

        $this->post(route('gift-cards.cancel', $card), ['reason' => 'fraude'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $card->fresh()->status);
        $this->assertEquals(0, $card->fresh()->balance);

        $this->post(route('gift-cards.settings'), ['validity_months' => 6])->assertSessionHasNoErrors();
        $this->assertSame(6, GiftCardService::validityMonths($this->tenant->refresh()));
    }

    public function test_cancel_and_adjust_need_the_manage_right(): void
    {
        $this->assertSame('gift_cards.manage', Permissions::routeMap()['gift-cards.cancel']);
        $this->assertSame('gift_cards.manage', Permissions::routeMap()['gift-cards.adjust']);
        $this->assertSame('gift_cards.view', Permissions::routeMap()['gift-cards']);
        $this->assertTrue(Permissions::has('gift_cards.manage'));
        $this->assertTrue(Permissions::allows(['gift_cards.*'], 'gift_cards.manage'));
        $this->assertFalse(Permissions::allows(['gift_cards.view'], 'gift_cards.manage'));
    }

    public function test_a_sale_without_a_card_is_untouched(): void
    {
        $svc = $this->service('Coupe', 100);
        $before = $this->withToken($this->token)->getJson('/api/v1/statistics/overview')->json('kpis.gross_revenue');
        $this->sell([['item_id' => $svc->id, 'quantity' => 1]], ['cash' => 100])->assertCreated();
        $sale = Sale::latest('id')->first();
        $this->assertEquals(0, $sale->non_revenue_amount);
        $this->assertEquals($before + 100, $this->withToken($this->token)->getJson('/api/v1/statistics/overview')->json('kpis.gross_revenue'));
    }
}
