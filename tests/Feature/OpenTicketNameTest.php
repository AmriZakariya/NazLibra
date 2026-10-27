<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PosTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An open ticket carries a name ("Table 4") and knows how long it has been
 * open. Before, it was only "ATT0012", and held_at moved on every re-park, so
 * a table that had been open an hour read as opened a minute ago.
 */
class OpenTicketNameTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();
    }

    private function hold(array $extra = []): PosTicket
    {
        $this->post(route('pos.tickets.store'), array_merge([
            'cart' => json_encode([['id' => $this->item->id, 'quantity' => 1]]),
        ], $extra))->assertRedirect();

        return PosTicket::latest('id')->firstOrFail();
    }

    public function test_a_ticket_can_be_parked_under_a_name(): void
    {
        $ticket = $this->hold(['ticket_name' => '  Table 4  ']);

        $this->assertSame('Table 4', $ticket->name);
        $this->assertSame('Table 4', $ticket->displayName());
        $this->assertNotNull($ticket->opened_at);
    }

    public function test_without_a_name_it_is_its_number(): void
    {
        $ticket = $this->hold(['ticket_name' => '']);

        $this->assertNull($ticket->name);
        $this->assertSame($ticket->number, $ticket->displayName());
    }

    public function test_parking_again_keeps_when_it_was_opened_and_can_rename_it(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $ticket = $this->hold(['ticket_name' => 'Table 4']);

        $this->travelTo(now()->setTime(12, 40));
        $this->hold(['ticket_id' => $ticket->id, 'ticket_name' => 'Table 4 — terrasse']);

        $ticket->refresh();
        $this->assertSame('Table 4 — terrasse', $ticket->name);
        $this->assertSame('12:00', $ticket->opened_at->format('H:i'), 're-parking must not restart the clock');
        $this->assertSame('12:40', $ticket->held_at->format('H:i'));
    }

    public function test_the_list_shows_the_name_and_how_long_it_has_been_open(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->hold(['ticket_name' => 'Table 4']);
        $this->hold(['ticket_name' => 'Mme Alami']);
        $anonymous = $this->hold();

        $this->travelTo(now()->setTime(12, 45));
        $html = $this->get(route('pos'))->assertOk()->getContent();

        $this->assertStringContainsString('title="Table 4">Table 4</p>', $html);
        $this->assertStringContainsString('Mme Alami', $html);
        $this->assertStringContainsString('title="'.$anonymous->number.'">'.$anonymous->number.'</p>', $html);
        $this->assertStringContainsString('data-age-label>45 min</span>', $html);
        $this->assertStringContainsString('pos-held-age is-waiting', $html, 'amber after 30 min');

        $this->travelTo(now()->setTime(13, 10));
        $html = $this->get(route('pos'))->assertOk()->getContent();
        $this->assertStringContainsString('data-age-label>1 h 10</span>', $html);
        $this->assertStringContainsString('pos-held-age is-late', $html, 'red after an hour');
    }

    public function test_a_resumed_ticket_offers_its_name_back(): void
    {
        $ticket = $this->hold(['ticket_name' => 'Table 4']);

        $this->get(route('pos', ['ticket' => $ticket->id]))
            ->assertOk()
            ->assertSee('name="ticket_name" value="Table 4"', false);
    }

    public function test_the_app_api_takes_the_name_and_keeps_the_opening_time(): void
    {
        $tenant = Tenant::firstOrFail();
        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $api = $this->withToken($user->createToken('t')->plainTextToken)->withHeader('X-Tenant-Slug', $tenant->slug);
        $cart = [['item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 10]];

        $created = $api->postJson('/api/v1/pos/tickets', [
            'cart' => $cart, 'name' => 'Table 9', 'opened_at' => '2026-10-27T11:00:00Z',
        ])->assertCreated()->json('ticket');

        $this->assertSame('Table 9', $created['name']);

        $api->postJson('/api/v1/pos/tickets', [
            'number' => $created['number'], 'cart' => $cart, 'name' => 'Table 9 bis',
        ])->assertCreated();

        $ticket = PosTicket::findOrFail($created['id']);
        $this->assertSame('Table 9 bis', $ticket->name);
        $this->assertSame('2026-10-27 11:00', $ticket->opened_at->utc()->format('Y-m-d H:i'));

        $listed = collect($api->getJson('/api/v1/pos/tickets')->assertOk()->json('tickets'))->firstWhere('id', $ticket->id);
        $this->assertSame('Table 9 bis', $listed['name']);
        $this->assertNotNull($listed['opened_at']);
    }
}
