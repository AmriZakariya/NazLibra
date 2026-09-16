<?php

namespace Tests\Feature;

use App\Console\Commands\MarkInvoicesOverdue;
use App\Models\Invoice;
use App\Models\Contact;
use App\Models\Sale;
use App\Models\SaleInvoice;
use App\Models\Tenant;
use App\Services\Documents\DocumentBranding;
use App\Services\Documents\DocumentNumberGenerator;
use App\Services\Documents\InvoiceService;
use App\Support\AmountInWords;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What has to hold before a shop can bill a real client with this: one legal
 * number series, a settlement date that does not move, a status that turns
 * late on its own, and a document that identifies the shop issuing it.
 */
class InvoiceProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(Tenant $tenant, array $overrides = []): Invoice
    {
        return app(InvoiceService::class)->create($tenant, array_merge([
            'customer_name' => 'Client test',
            'issue_date' => '2026-06-15',
            'due_date' => '2026-06-30',
            'lines' => [['name' => 'Ligne', 'quantity' => 1, 'unit_price' => '100.00', 'tax_rate' => '20']],
        ], $overrides));
    }

    public function test_both_invoice_tables_draw_from_one_number_series(): void
    {
        // A shop holding two different documents both stamped FAC00002 cannot
        // answer an auditor, and the module list and the sales list each show
        // one of them.
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $first = $this->invoice($tenant);
        $claimed = app(DocumentNumberGenerator::class)->nextInvoice($tenant)['number'];
        $third = $this->invoice($tenant);

        $this->assertNotSame($first->number, $claimed);
        $this->assertNotSame($claimed, $third->number);
        $this->assertSame([$first->number, $claimed, $third->number], array_unique([$first->number, $claimed, $third->number]));
    }

    public function test_a_number_already_used_by_a_sale_invoice_is_skipped(): void
    {
        // Imported history, or a series reset, must not take the module down
        // with a unique-key violation.
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();

        // Take the exact number the series is about to hand out, so the
        // collision is real rather than a hope about where the seeder left
        // the counter.
        $taken = app(DocumentNumberGenerator::class)->peek($tenant, 'invoice', 'FAC');
        SaleInvoice::create([
            'tenant_id' => $tenant->id,
            'sale_id' => $sale->id,
            'number' => $taken,
            'status' => 'issued',
            'issued_at' => now(),
            'total_amount' => 10,
        ]);

        $invoice = $this->invoice($tenant);

        $this->assertNotSame($taken, $invoice->number);
    }

    public function test_a_number_already_used_by_a_module_invoice_is_skipped(): void
    {
        // A sequence rewound by hand, or a restored backup, must not take the
        // module down with a unique-key violation on the next sale.
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $existing = $this->invoice($tenant);

        \App\Models\DocumentSequence::where('tenant_id', $tenant->id)
            ->where('document_type', 'invoice')
            ->update(['next_number' => $existing->serial_number]);

        $next = $this->invoice($tenant);

        $this->assertNotSame($existing->number, $next->number);
    }

    public function test_the_settlement_date_does_not_move_when_the_invoice_is_touched_again(): void
    {
        // paid_at is what a reminder, an ageing report and an auditor read.
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $service = app(InvoiceService::class);

        $invoice = $service->send($this->invoice($tenant));
        Carbon::setTestNow('2026-07-01 10:00:00');
        $service->recordPayment($invoice, ['amount' => 120, 'method' => 'cash']);
        $paidAt = $invoice->fresh()->paid_at;

        Carbon::setTestNow('2026-09-20 18:30:00');
        $service->refreshPaymentStatus($invoice->fresh());

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame($paidAt->toDateTimeString(), $invoice->fresh()->paid_at->toDateTimeString());
    }

    public function test_a_refresh_that_changes_nothing_does_not_bump_the_version(): void
    {
        // The version is what an open edit form checks; a daily sweep that
        // moved it would reject every form in the building.
        $this->seed();
        // Before the due date, so the refresh has genuinely nothing to say.
        Carbon::setTestNow('2026-06-20 09:00:00');
        $tenant = Tenant::firstOrFail();
        $invoice = app(InvoiceService::class)->send($this->invoice($tenant));
        $before = $invoice->fresh()->version;

        app(InvoiceService::class)->refreshPaymentStatus($invoice->fresh());

        $this->assertSame($before, $invoice->fresh()->version);
    }

    public function test_unpaid_invoices_past_due_are_marked_overdue_by_the_sweep(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $invoice = app(InvoiceService::class)->send($this->invoice($tenant));

        Carbon::setTestNow('2026-07-05 09:00:00');
        $this->artisan('invoices:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', $invoice->fresh()->status);
    }

    public function test_the_sweep_leaves_settled_and_draft_invoices_alone(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $service = app(InvoiceService::class);

        $draft = $this->invoice($tenant);
        $paid = $service->send($this->invoice($tenant));
        $service->recordPayment($paid, ['amount' => 120, 'method' => 'cash']);

        Carbon::setTestNow('2026-07-05 09:00:00');
        $this->artisan('invoices:mark-overdue')->assertSuccessful();

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('paid', $paid->fresh()->status);
    }

    public function test_the_sweep_is_idempotent(): void
    {
        // It runs every night for the life of the shop.
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $invoice = app(InvoiceService::class)->send($this->invoice($tenant));

        Carbon::setTestNow('2026-07-05 09:00:00');
        $this->artisan('invoices:mark-overdue')->assertSuccessful();
        $version = $invoice->fresh()->version;
        $this->artisan('invoices:mark-overdue')->assertSuccessful();

        $this->assertSame($version, $invoice->fresh()->version);
    }

    public function test_the_invoice_pdf_carries_the_shop_identity_and_the_legal_recap(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $tenant->forceFill([
            'ice' => '001234567000089',
            'settings' => array_merge($tenant->settings ?? [], [
                'company_profile' => array_merge($tenant->settings['company_profile'] ?? [], [
                    'rc' => 'RC-4521',
                    'cnss' => 'CNSS-99',
                    'bank_details' => 'Banque Populaire · RIB 123',
                ]),
            ]),
        ])->save();

        $service = app(InvoiceService::class);
        $invoice = $service->send($service->create($tenant, [
            'customer_name' => 'Librairie du Centre',
            'issue_date' => '2026-06-15',
            'due_date' => '2026-06-30',
            'lines' => [
                ['name' => 'Livre scolaire', 'quantity' => 2, 'unit_price' => '50.00', 'tax_rate' => '0'],
                ['name' => 'Cartouche encre', 'quantity' => 1, 'unit_price' => '100.00', 'tax_rate' => '20'],
            ],
        ]));
        $service->recordPayment($invoice, ['amount' => 20, 'method' => 'cash']);

        $html = $this->renderInvoice($tenant, $invoice->fresh(['items', 'payments', 'creator']));

        $this->assertStringContainsString('001234567000089', $html, 'the ICE identifies the issuer');
        $this->assertStringContainsString('RC-4521', $html);
        $this->assertStringContainsString('Récapitulatif TVA', $html);
        $this->assertStringContainsString('Banque Populaire', $html);
        $this->assertStringContainsString('Règlements', $html, 'a part-paid invoice shows what was already received');
        $this->assertStringContainsString(AmountInWords::money($invoice->fresh()->total), $html);
        // Two rates on one invoice means two lines in the recap, which is the
        // whole reason the recap exists.
        $this->assertStringContainsString('0,00 %', $html);
        $this->assertStringContainsString('20,00 %', $html);
    }

    public function test_a_draft_is_watermarked_and_a_sent_invoice_is_not(): void
    {
        // A draft holds a real number from the legal series. Printed clean it
        // is indistinguishable from a claim the client owes.
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $draft = $this->invoice($tenant);

        $this->assertStringContainsString('BROUILLON', $this->renderInvoice($tenant, $draft));
        $this->assertStringNotContainsString(
            'BROUILLON',
            $this->renderInvoice($tenant, app(InvoiceService::class)->send($draft)->fresh(['items', 'payments'])),
        );
    }

    public function test_a_cancelled_invoice_says_so_across_the_page(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $invoice = app(InvoiceService::class)->send($this->invoice($tenant));
        app(InvoiceService::class)->cancel($invoice, 'Erreur de saisie');

        $this->assertStringContainsString('ANNULÉE', $this->renderInvoice($tenant, $invoice->fresh(['items', 'payments'])));
    }

    public function test_the_status_is_shown_in_french_not_as_a_database_key(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $invoice = app(InvoiceService::class)->send($this->invoice($tenant));

        $html = $this->renderInvoice($tenant, $invoice->fresh(['items', 'payments']));

        $this->assertStringContainsString('Envoyée', $html);
    }

    public function test_an_unpaid_invoice_counts_as_money_the_client_owes(): void
    {
        // Purchases have always moved the supplier's balance. Invoices moved
        // nothing, so a client holding a large unpaid invoice read as owing 0
        // on the very screen used to chase debtors.
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $client = Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->firstOrFail();
        $client->forceFill(['outstanding_balance' => 0])->save();

        $service = app(InvoiceService::class);
        $service->send($service->create($tenant, [
            'customer_id' => $client->id,
            'issue_date' => '2026-06-15',
            'due_date' => '2026-06-30',
            'lines' => [['name' => 'Ligne', 'quantity' => 1, 'unit_price' => '1000.00']],
        ]));

        $due = Invoice::where('tenant_id', $tenant->id)->receivable()->sum('balance_due');

        $this->assertSame(1000.0, (float) $due);
        $this->assertSame(
            1000.0,
            (float) Contact::query()
                ->whereKey($client->id)
                ->withSum(['invoices as invoice_due_sum' => fn ($query) => $query->receivable()], 'balance_due')
                ->firstOrFail()
                ->invoice_due_sum,
        );
    }

    public function test_a_draft_or_cancelled_invoice_is_not_money_owed(): void
    {
        // Nobody has been asked to pay a draft, and a cancelled claim is not
        // a claim. Counting either would overstate what the shop is owed.
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $service = app(InvoiceService::class);

        $this->invoice($tenant); // stays a draft
        $cancelled = $service->send($this->invoice($tenant));
        $service->cancel($cancelled, 'Erreur de saisie');
        $archived = $service->send($this->invoice($tenant));
        $service->archive($archived);

        $this->assertSame(0.0, (float) Invoice::where('tenant_id', $tenant->id)->receivable()->sum('balance_due'));
    }

    public function test_an_invoice_with_nothing_left_to_pay_is_not_a_debt(): void
    {
        // The debtor list is built from this. A client whose only open
        // document owes nothing must not be chased for it.
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $client = Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->firstOrFail();
        $client->forceFill(['outstanding_balance' => 0])->save();

        $service = app(InvoiceService::class);
        $invoice = $service->send($service->create($tenant, [
            'customer_id' => $client->id,
            'issue_date' => '2026-06-15',
            'due_date' => '2026-06-30',
            'lines' => [['name' => 'Ligne', 'quantity' => 1, 'unit_price' => '100.00']],
        ]));
        // Status left alone deliberately: what makes this not a debt is the
        // balance, not the label on it.
        $invoice->forceFill(['balance_due' => '0.00'])->save();

        $this->assertFalse(
            Contact::query()->whereKey($client->id)
                ->whereHas('invoices', fn ($query) => $query->receivable())
                ->exists(),
        );
    }

    public function test_a_part_payment_leaves_only_the_remainder_owed(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $service = app(InvoiceService::class);
        $invoice = $service->send($this->invoice($tenant));

        $service->recordPayment($invoice, ['amount' => 40, 'method' => 'cash']);

        $this->assertSame(80.0, (float) Invoice::where('tenant_id', $tenant->id)->receivable()->sum('balance_due'));
    }

    public function test_the_invoice_route_returns_a_real_pdf(): void
    {
        // The blade assertions above prove the content; this proves dompdf
        // can actually lay it out — a CSS feature it does not support fails
        // here and nowhere else.
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $invoice = app(InvoiceService::class)->send($this->invoice($tenant));

        $response = $this->get(route('documents.invoices.pdf', $invoice));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->baseResponse->getContent());
    }

    private function renderInvoice(Tenant $tenant, Invoice $invoice): string
    {
        $branding = app(DocumentBranding::class);
        $company = $branding->companyProfile($tenant);
        $company['logo_src'] = $branding->assetSource($company['store_logo'] ?? null);
        $company['signature_src'] = $branding->assetSource($company['signature'] ?? null);

        return view('librairepro.pdf.commercial-document', [
            'documentType' => 'invoice',
            'document' => $invoice->loadMissing(['items', 'payments', 'creator']),
            'tenant' => $tenant,
            'company' => $company,
            'settings' => $branding->settings($tenant),
        ])->render();
    }
}
