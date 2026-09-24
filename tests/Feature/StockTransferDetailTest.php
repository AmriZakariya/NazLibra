<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\StockTransfer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a shop is shown right after moving stock.
 *
 * Creating a transfer redirects straight into this dialog, so it is the
 * receipt for the movement — and it opened on a cancellation form under two
 * bare line names, with no date, no total, no status and nobody's name.
 */
class StockTransferDetailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $from;

    private Location $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::firstOrFail();
        $this->from = Location::where('tenant_id', $this->tenant->id)->where('is_default', true)->firstOrFail();
        $this->to = Location::where('tenant_id', $this->tenant->id)->whereKeyNot($this->from->id)->firstOrFail();
    }

    /** A brouillon: written down, nothing moved. */
    private function create(string $note = 'Réassort rentrée'): StockTransfer
    {
        $items = Item::where('tenant_id', $this->tenant->id)->where('type', '!=', 'service')->take(2)->get();

        $this->post('/catalogue/stock/transferts', [
            'source_location_id' => $this->from->id,
            'destination_location_id' => $this->to->id,
            'note' => $note,
            'items' => [
                ['item_id' => $items[0]->id, 'quantity' => 3, 'note' => 'Carton A'],
                ['item_id' => $items[1]->id, 'quantity' => 1],
            ],
        ])->assertRedirect();

        return StockTransfer::latest('id')->firstOrFail();
    }

    private function send(StockTransfer $transfer): StockTransfer
    {
        $this->post('/catalogue/stock/transferts/'.$transfer->id.'/envoyer')->assertRedirect();

        return $transfer->refresh();
    }

    /** All the way through: written, sent, received. */
    private function completed(string $note = 'Réassort rentrée'): StockTransfer
    {
        $transfer = $this->send($this->create($note));
        $this->post('/catalogue/stock/transferts/'.$transfer->id.'/receptionner')->assertRedirect();

        return $transfer->refresh();
    }

    private function dialog(StockTransfer $transfer): string
    {
        $content = $this->get('/stock?panel=stock-transfers&detail_transfer='.$transfer->id)
            ->assertOk()
            ->getContent();

        return Str::between($content, '<dialog id="transfer-detail-'.$transfer->id.'"', '</dialog>');
    }

    public function test_the_receipt_says_what_moved_where_and_when(): void
    {
        $transfer = $this->completed();
        $dialog = $this->dialog($transfer);

        $this->assertStringContainsString($transfer->number, $dialog);
        $this->assertStringContainsString($this->from->name, $dialog);
        $this->assertStringContainsString($this->to->name, $dialog);
        // None of these were on it: a stock movement with no date, no total
        // and nobody's name against it answers nothing a week later.
        $this->assertStringContainsString($transfer->transferred_at->format('d/m/Y H:i'), $dialog);
        $this->assertStringContainsString('Quantité totale', $dialog);
        $this->assertStringContainsString('4 unité(s)', $dialog);
        $this->assertStringContainsString('Créé par', $dialog);
        $this->assertStringContainsString('Amina El Idrissi', $dialog);
    }

    public function test_the_receipt_names_the_stage_the_goods_are_at(): void
    {
        $this->assertStringContainsString('Brouillon', $this->dialog($this->create()));
        $this->assertStringContainsString('En transit', $this->dialog($this->send($this->create())));
        $this->assertStringContainsString('Reçu', $this->dialog($this->completed()));
    }

    public function test_each_line_carries_its_reference_and_its_note(): void
    {
        $transfer = $this->completed();
        $dialog = $this->dialog($transfer);
        $line = $transfer->lines[0];

        $this->assertStringContainsString($line['name'], $dialog);
        $this->assertStringContainsString($line['item_code'], $dialog);
        // Typed on the line when the transfer was made, and never shown back.
        $this->assertStringContainsString('Carton A', $dialog);
    }

    public function test_the_global_note_is_shown_back(): void
    {
        $this->assertStringContainsString(
            'Réassort rentrée',
            $this->dialog($this->create('Réassort rentrée')),
        );
    }

    public function test_cancelling_is_folded_away_not_the_first_thing_offered(): void
    {
        $dialog = $this->dialog($this->completed());

        // The dialog opens on its own right after a transfer is created. It
        // should read as a receipt, not lead with how to undo the thing that
        // was just done.
        $this->assertStringContainsString('<details', $dialog);
        $summary = Str::between($dialog, '<details', '</summary>');
        $this->assertStringContainsString('Annuler ce transfert', $summary);
    }

    public function test_a_cancelled_transfer_says_who_cancelled_it_and_why(): void
    {
        $transfer = $this->completed();

        $this->post('/catalogue/stock/transferts/'.$transfer->id.'/annuler', [
            'reason' => 'Erreur de destination',
        ])->assertRedirect();

        $dialog = $this->dialog($transfer->refresh());

        $this->assertStringContainsString('Annulé', $dialog);
        // In the cancellation banner, not merely on the dialog: the same name
        // sits under "Créé par", so an unscoped assertion passes on a banner
        // that names nobody.
        $banner = Str::between($dialog, 'Annulé le', '</div>');
        $this->assertStringContainsString('Erreur de destination', $dialog);
        $this->assertStringContainsString('par Amina El Idrissi', $banner);
        // And it stops offering to cancel what is already cancelled.
        $this->assertStringNotContainsString('Annuler ce transfert', $dialog);
    }

    public function test_the_dialog_is_not_nested_inside_the_list_table(): void
    {
        $transfer = $this->completed();
        $content = $this->get('/stock?panel=stock-transfers&detail_transfer='.$transfer->id)
            ->assertOk()
            ->getContent();

        // A <dialog> between two <tr> is invalid HTML: the parser lifts it out
        // of the table and takes its contents with it. The dialog rendered
        // empty the day it grew a table of its own.
        // Str::between() takes the LAST needle, which here would swallow the
        // dialogs and their own tables. The list table ends at the FIRST one.
        $list = Str::before(Str::after($content, 'min-w-[1040px]'), '</table>');
        $this->assertStringNotContainsString('<dialog', $list);
        $this->assertStringContainsString('<dialog id="transfer-detail-', $content);
    }

    public function test_opening_one_transfer_does_not_hide_the_others(): void
    {
        $first = $this->create('Premier');
        $second = $this->create('Second');

        $content = $this->get('/stock?panel=stock-transfers&detail_transfer='.$second->id)
            ->assertOk()
            ->getContent();

        // `detail_transfer` opens a dialog. It also narrowed the list to that
        // one row, so creating a transfer left the shop looking at a list of
        // one and wondering where the rest had gone.
        $this->assertStringContainsString($first->number, $content);
        $this->assertStringContainsString($second->number, $content);
    }
}
