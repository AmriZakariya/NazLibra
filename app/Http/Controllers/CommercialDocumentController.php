<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Models\Invoice;
use App\Services\Documents\EstimateService;
use App\Services\Documents\InvoiceService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CommercialDocumentController extends Controller
{
    public function storeInvoice(Request $request, InvoiceService $service): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        $invoice = $service->create($tenant, $this->validateInvoicePayload($request));

        return redirect()
            ->route('module', ['module' => 'invoices', 'section' => 'invoices', 'invoice' => $invoice->id])
            ->with('status', 'Facture '.$invoice->number.' enregistrée.');
    }

    public function updateInvoice(Request $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($invoice->tenant_id === $tenant->id, 404);

        $invoice = $service->update($invoice, $this->validateInvoicePayload($request, true), $request->integer('version') ?: null);

        return redirect()
            ->route('module', ['module' => 'invoices', 'section' => 'invoices', 'invoice' => $invoice->id])
            ->with('status', 'Facture '.$invoice->number.' mise à jour.');
    }

    public function sendInvoice(Request $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $invoice = $service->send($invoice);

        return back()->with('status', 'Facture '.$invoice->number.' marquée envoyée.');
    }

    public function duplicateInvoice(Request $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $copy = $service->duplicate($invoice);

        return redirect()
            ->route('module', ['module' => 'invoices', 'section' => 'invoices', 'invoice' => $copy->id])
            ->with('status', 'Facture '.$invoice->number.' dupliquée en '.$copy->number.'.');
    }

    public function cancelInvoice(Request $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $service->cancel($invoice, $data['reason']);

        return back()->with('status', 'Facture annulée.');
    }

    public function archiveInvoice(Request $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $service->archive($invoice);

        return back()->with('status', 'Facture archivée.');
    }

    public function restoreInvoice(Request $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $service->restore($invoice);

        return back()->with('status', 'Facture restaurée.');
    }

    public function storeInvoicePayment(Request $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $data = $request->validate([
            'method' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:160'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $service->recordPayment($invoice, $data);

        return back()->with('status', 'Paiement facture enregistré.');
    }

    public function storeEstimate(Request $request, EstimateService $service): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        $estimate = $service->create($tenant, $this->validateEstimatePayload($request));

        return redirect()
            ->route('module', ['module' => 'invoices', 'section' => 'estimates', 'estimate' => $estimate->id])
            ->with('status', 'Devis '.$estimate->number.' enregistré.');
    }

    public function updateEstimate(Request $request, Estimate $estimate, EstimateService $service): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($estimate->tenant_id === $tenant->id, 404);
        $estimate = $service->update($estimate, $this->validateEstimatePayload($request, true), $request->integer('version') ?: null);

        return redirect()
            ->route('module', ['module' => 'invoices', 'section' => 'estimates', 'estimate' => $estimate->id])
            ->with('status', 'Devis '.$estimate->number.' mis à jour.');
    }

    public function transitionEstimate(Request $request, Estimate $estimate, EstimateService $service): RedirectResponse
    {
        $this->authorizeEstimate($request, $estimate);
        $data = $request->validate([
            'action' => ['required', Rule::in(['send', 'accept', 'decline', 'cancel', 'archive', 'restore'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        match ($data['action']) {
            'send' => $service->markSent($estimate),
            'accept' => $service->accept($estimate),
            'decline' => $service->decline($estimate, $data['reason'] ?? null),
            'cancel' => $service->cancel($estimate, $data['reason'] ?? 'Annulation'),
            'archive' => $service->archive($estimate),
            'restore' => $service->restore($estimate),
        };

        return back()->with('status', 'Devis mis à jour.');
    }

    public function duplicateEstimate(Request $request, Estimate $estimate, EstimateService $service): RedirectResponse
    {
        $this->authorizeEstimate($request, $estimate);
        $copy = $service->duplicate($estimate);

        return redirect()
            ->route('module', ['module' => 'invoices', 'section' => 'estimates', 'estimate' => $copy->id])
            ->with('status', 'Devis '.$estimate->number.' dupliqué en '.$copy->number.'.');
    }

    public function convertEstimate(Request $request, Estimate $estimate, EstimateService $service): RedirectResponse
    {
        $this->authorizeEstimate($request, $estimate);
        $data = $request->validate([
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['draft', 'sent'])],
        ]);
        $invoice = $service->convertToInvoice($estimate, $data);

        return redirect()
            ->route('module', ['module' => 'invoices', 'section' => 'invoices', 'invoice' => $invoice->id])
            ->with('status', 'Devis converti en facture '.$invoice->number.'.');
    }

    public function previewInvoicePdf(Request $request, Invoice $invoice): Response
    {
        $this->authorizeInvoice($request, $invoice);
        $invoice->loadMissing(['items', 'payments', 'customer', 'creator', 'tenant']);

        return Pdf::loadView('librairepro.pdf.commercial-document', [
            'documentType' => 'invoice',
            'document' => $invoice,
            'tenant' => $invoice->tenant,
        ])->setPaper('a4')->setOptions([
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ])->download('facture-'.$invoice->number.'.pdf');
    }

    public function previewEstimatePdf(Request $request, Estimate $estimate): Response
    {
        $this->authorizeEstimate($request, $estimate);
        $estimate->loadMissing(['items', 'customer', 'creator', 'tenant']);

        return Pdf::loadView('librairepro.pdf.commercial-document', [
            'documentType' => 'estimate',
            'document' => $estimate,
            'tenant' => $estimate->tenant,
        ])->setPaper('a4')->setOptions([
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ])->download('devis-'.$estimate->number.'.pdf');
    }

    private function validateInvoicePayload(Request $request, bool $partial = false): array
    {
        return $request->validate($this->documentRules('invoice', $partial));
    }

    private function validateEstimatePayload(Request $request, bool $partial = false): array
    {
        return $request->validate($this->documentRules('estimate', $partial));
    }

    private function documentRules(string $type, bool $partial): array
    {
        $dateField = $type === 'invoice' ? 'due_date' : 'expiration_date';

        return [
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'ice' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:60'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'string', 'max:32'],
            'issue_date' => [$partial ? 'nullable' : 'required', 'date'],
            'service_date' => ['nullable', 'date'],
            $dateField => ['nullable', 'date'],
            'document_discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'document_discount_value' => ['nullable', 'numeric', 'min:0'],
            'fee_total' => ['nullable', 'numeric', 'min:0'],
            'rounding_total' => ['nullable', 'numeric'],
            'customer_message' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:4000'],
            'footer' => ['nullable', 'string', 'max:2000'],
            'customer_reference' => ['nullable', 'string', 'max:160'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.name' => ['nullable', 'string', 'max:255'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['nullable', 'string', 'max:80'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_inclusive' => ['nullable', 'boolean'],
            'lines.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        $tenant = TenantContext::require($request);
        abort_unless($invoice->tenant_id === $tenant->id, 404);
    }

    private function authorizeEstimate(Request $request, Estimate $estimate): void
    {
        $tenant = TenantContext::require($request);
        abort_unless($estimate->tenant_id === $tenant->id, 404);
    }
}
