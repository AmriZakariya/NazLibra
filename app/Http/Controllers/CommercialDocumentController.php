<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Models\Invoice;
use App\Services\Documents\EstimateService;
use App\Services\Documents\InvoiceService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class CommercialDocumentController extends Controller
{
    public function invoicesData(Request $request): JsonResponse
    {
        $tenant = TenantContext::require($request);
        $query = trim((string) $request->query('q'));

        $invoices = Invoice::query()
            ->with(['customer', 'creator', 'sourceSale'])
            ->where('tenant_id', $tenant->id)
            ->when($request->query('archived') !== 'with', fn ($builder) => $builder->whereNull('archived_at'))
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('status', 'like', "%{$query}%")
                        ->orWhere('customer_snapshot', 'like', "%{$query}%")
                        ->orWhereHas('customer', fn ($contact) => $contact->where('name', 'like', "%{$query}%"));
                });
            })
            ->when($request->filled('invoice_status'), fn ($builder) => $builder->where('status', $request->query('invoice_status')));

        $statusLabels = [
            'draft' => 'Brouillon',
            'sent' => 'Envoyée',
            'viewed' => 'Vue',
            'partially_paid' => 'Partiellement payée',
            'paid' => 'Payée',
            'overdue' => 'En retard',
            'cancelled' => 'Annulée',
            'archived' => 'Archivée',
        ];

        return DataTables::eloquent($invoices)
            ->addColumn('customer_display', function (Invoice $invoice): string {
                $name = data_get($invoice->customer_snapshot, 'name', $invoice->customer?->name ?? 'Client comptoir');
                $contact = data_get($invoice->customer_snapshot, 'phone') ?: data_get($invoice->customer_snapshot, 'email', '—');

                return '<strong>'.e($name).'</strong><p class="mt-1 text-xs text-slate-500">'.e($contact).'</p>';
            })
            ->editColumn('issue_date', fn (Invoice $invoice): string => $invoice->issue_date?->format('d/m/Y') ?? '—')
            ->editColumn('due_date', fn (Invoice $invoice): string => $invoice->due_date?->format('d/m/Y') ?? '—')
            ->editColumn('total', fn (Invoice $invoice): string => $this->money($invoice->total))
            ->editColumn('amount_paid', fn (Invoice $invoice): string => '<span class="text-emerald-600 dark:text-emerald-300">'.$this->money($invoice->amount_paid).'</span>')
            ->editColumn('balance_due', fn (Invoice $invoice): string => $this->money($invoice->balance_due))
            ->addColumn('status_badge', fn (Invoice $invoice): string => $this->invoiceStatusBadge($invoice, $statusLabels[$invoice->status] ?? $invoice->status))
            ->addColumn('creator_name', fn (Invoice $invoice): string => e($invoice->creator?->name ?? '—'))
            ->addColumn('action', fn (Invoice $invoice): string => $this->invoiceActionMenu($invoice))
            ->rawColumns(['customer_display', 'amount_paid', 'status_badge', 'action'])
            ->toJson();
    }

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
        return $request->validate(
            $this->documentRules($request, 'invoice', $partial),
            $this->documentMessages('invoice'),
        );
    }

    private function validateEstimatePayload(Request $request, bool $partial = false): array
    {
        return $request->validate(
            $this->documentRules($request, 'estimate', $partial),
            $this->documentMessages('estimate'),
        );
    }

    private function documentRules(Request $request, string $type, bool $partial): array
    {
        $tenant = TenantContext::require($request);
        $dateField = $type === 'invoice' ? 'due_date' : 'expiration_date';

        return [
            // Scope the linked customer to this tenant (clean message instead of a 404 later).
            'customer_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'customer_name' => ['nullable', 'string', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'ice' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:60'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],
            // Only user-settable states from the form. Computed states (paid,
            // partially_paid, overdue, viewed, cancelled, archived) are driven by
            // the service — never accepted from the request.
            'status' => ['nullable', Rule::in(['draft', 'sent'])],
            'issue_date' => [$partial ? 'nullable' : 'required', 'date'],
            'service_date' => ['nullable', 'date'],
            // Due/expiration must not precede the issue date (single clean rule
            // instead of a deep service exception).
            $dateField => [$type === 'invoice' ? 'required' : 'nullable', 'date', 'after_or_equal:issue_date'],
            'document_discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'document_discount_value' => ['nullable', 'numeric', 'min:0'],
            'fee_total' => ['nullable', 'numeric', 'min:0'],
            'rounding_total' => ['nullable', 'numeric'],
            'customer_message' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:4000'],
            'footer' => ['nullable', 'string', 'max:2000'],
            'customer_reference' => ['nullable', 'string', 'max:160'],
            // At least one line must carry a real article or a designation.
            'lines' => ['required', 'array', 'min:1', function (string $attr, $value, callable $fail): void {
                $meaningful = collect($value)->contains(
                    fn ($line) => ! empty($line['item_id']) || trim((string) ($line['name'] ?? '')) !== ''
                );
                if (! $meaningful) {
                    $fail('Ajoutez au moins une ligne avec un article ou une désignation.');
                }
            }],
            'lines.*.item_id' => ['nullable', 'integer'],
            // A custom line (no catalogue item) must be named.
            'lines.*.name' => ['nullable', 'string', 'max:255', 'required_without:lines.*.item_id'],
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

    private function documentMessages(string $type): array
    {
        $dateLabel = $type === 'invoice' ? 'La date d’échéance' : 'La date d’expiration';

        return [
            'customer_id.exists' => 'Le client sélectionné est introuvable.',
            'due_date.after_or_equal' => $dateLabel.' ne peut pas précéder la date d’émission.',
            'expiration_date.after_or_equal' => $dateLabel.' ne peut pas précéder la date d’émission.',
            'lines.required' => 'Ajoutez au moins une ligne au document.',
            'lines.min' => 'Ajoutez au moins une ligne au document.',
            'lines.*.name.required_without' => 'Donnez une désignation à cette ligne (ou choisissez un article).',
            'lines.*.quantity.required' => 'Indiquez la quantité.',
            'lines.*.quantity.gt' => 'La quantité doit être supérieure à 0.',
            'lines.*.unit_price.required' => 'Indiquez le prix unitaire.',
            'lines.*.unit_price.min' => 'Le prix unitaire ne peut pas être négatif.',
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

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ').' DH';
    }

    private function invoiceStatusBadge(Invoice $invoice, string $label): string
    {
        $classes = match ($invoice->status) {
            'paid' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/25 dark:bg-emerald-500/10 dark:text-emerald-200',
            'cancelled' => 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/25 dark:bg-rose-500/10 dark:text-rose-200',
            'overdue' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/25 dark:bg-red-500/10 dark:text-red-200',
            'partially_paid', 'draft' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-200',
            default => 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/25 dark:bg-sky-500/10 dark:text-sky-200',
        };

        return '<span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold '.$classes.'">'.e($label).'</span>';
    }

    private function invoiceActionMenu(Invoice $invoice): string
    {
        $canEdit = in_array($invoice->status, ['draft', 'sent'], true) && (float) $invoice->amount_paid <= 0;
        $canCreateSale = $invoice->archived_at === null && in_array($invoice->status, ['sent', 'viewed', 'partially_paid', 'paid', 'overdue'], true);
        $detailUrl = route('module', ['module' => 'invoices', 'section' => 'invoices', 'invoice' => $invoice->id]);
        $editUrl = route('module', ['module' => 'invoices', 'section' => 'invoice-edit', 'invoice' => $invoice->id]);
        $saleUrl = $invoice->sourceSale
            ? route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $invoice->sourceSale->id])
            : route('pos', ['source_invoice' => $invoice->id]);
        $duplicateUrl = route('documents.invoices.duplicate', $invoice);
        $pdfUrl = route('documents.invoices.pdf', $invoice);
        $csrf = csrf_field();

        $editAction = $canEdit
            ? '<a href="'.$editUrl.'"><span>ED</span> Modifier</a>'
            : '<button type="button" disabled><span>LK</span> Modification verrouillée</button>';
        $saleAction = ($invoice->sourceSale || $canCreateSale)
            ? '<a href="'.$saleUrl.'"><span>PV</span> '.($invoice->sourceSale ? 'Voir vente liée' : 'Encaisser en caisse').'</a>'
            : '<button type="button" disabled title="Envoyez la facture avant de l\'encaisser en caisse."><span>PV</span> Encaissement verrouillé</button>';

        return <<<HTML
<details class="sale-action-menu" data-sale-action-menu>
    <summary>Action</summary>
    <div class="sale-action-panel">
        <a href="{$detailUrl}"><span>VO</span> Voir détail</a>
        {$editAction}
        {$saleAction}
        <form action="{$duplicateUrl}" method="POST" onsubmit="return confirm('Dupliquer cette facture en nouveau brouillon ?')">
            {$csrf}
            <button type="submit"><span>CP</span> Dupliquer</button>
        </form>
        <a href="{$pdfUrl}"><span>PDF</span> Télécharger PDF</a>
    </div>
</details>
HTML;
    }
}
