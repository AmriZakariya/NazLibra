@php
    use App\Support\AmountInWords;

    $currency = $document->currency ?: 'MAD';
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' '.$currency;
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : '—';
    $snapshot = $document->customer_snapshot ?? [];
    $isInvoice = $documentType === 'invoice';
    $title = $isInvoice ? ($settings['invoice_title'] ?? 'Facture') : 'Devis';

    $statusLabels = [
        'draft' => 'Brouillon', 'sent' => 'Envoyée', 'viewed' => 'Vue',
        'partially_paid' => 'Partiellement payée', 'paid' => 'Payée',
        'overdue' => 'En retard', 'cancelled' => 'Annulée',
        'accepted' => 'Acceptée', 'declined' => 'Refusée', 'expired' => 'Expirée',
        'converted' => 'Convertie',
    ];
    $statusLabel = $statusLabels[$document->status] ?? $document->status;

    // A draft carries a real number from the legal series but is not yet a
    // document the client owes anything against, and a cancelled one must
    // never be mistaken for a live claim. Both say so across the page, not in
    // a badge someone can miss.
    $watermark = match ($document->status) {
        'draft' => 'BROUILLON',
        'cancelled' => 'ANNULÉE',
        default => null,
    };

    $legal = array_filter([
        'ICE' => $company['gst_no'] ?? null,
        'RC' => $company['rc'] ?? null,
        'IF' => $company['vat_no'] ?? null,
        'CNSS' => $company['cnss'] ?? null,
    ], fn ($value) => filled($value));

    $taxGroups = collect($document->tax_breakdown ?? [])
        ->filter(fn ($group) => (float) ($group['taxable'] ?? 0) != 0.0 || (float) ($group['tax'] ?? 0) != 0.0)
        ->sortBy(fn ($group) => (float) ($group['rate'] ?? 0))
        ->values();
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 26px 26px 58px; }
        * { box-sizing: border-box; }
        body { color: #111827; font-family: "DejaVu Sans", sans-serif; font-size: 11.5px; line-height: 1.45; margin: 0; }
        h1, h2, h3, p { margin: 0; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .center { text-align: center; }

        .header { border-bottom: 2px solid {{ $settings['primary_color'] }}; display: table; padding-bottom: 16px; width: 100%; }
        .brand, .doc-meta { display: table-cell; vertical-align: top; }
        .brand { width: 60%; }
        .doc-meta { text-align: right; width: 40%; }
        .logo { border: 1px solid #e2e8f0; border-radius: 10px; display: inline-block; height: 62px; margin-right: 12px; padding: 6px; vertical-align: top; width: 86px; }
        .brand-copy { display: inline-block; max-width: 340px; vertical-align: top; }
        h1 { color: {{ $settings['primary_color'] }}; font-size: 24px; letter-spacing: .02em; text-transform: uppercase; }
        h2 { font-size: 17px; margin-bottom: 4px; }
        .badge { background: {{ $settings['primary_color'] }}; border-radius: 999px; color: #fff; display: inline-block; font-size: 9.5px; font-weight: 700; letter-spacing: .08em; margin-top: 8px; padding: 4px 10px; text-transform: uppercase; }
        .legal { font-size: 9.5px; }

        .box-row { display: table; margin-top: 16px; width: 100%; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; display: table-cell; padding: 12px; vertical-align: top; width: 50%; }
        .box + .box { border-left: 10px solid #fff; }
        .label { color: {{ $settings['primary_color'] }}; display: block; font-size: 9.5px; font-weight: 700; letter-spacing: .08em; margin-bottom: 5px; text-transform: uppercase; }

        table.grid { border-collapse: collapse; margin-top: 16px; width: 100%; }
        table.grid th { background: #eef2ff; color: #334155; font-size: 9.5px; letter-spacing: .06em; padding: 8px 7px; text-align: left; text-transform: uppercase; }
        /* `table.grid th` outranks a bare `.right`, so a money column's
           heading sat left of the figures it labels. */
        table.grid th.right { text-align: right; }
        table.grid th.center { text-align: center; }
        table.grid td { border-bottom: 1px solid #e5e7eb; padding: 8px 7px; vertical-align: top; }
        /* Keep a recap with its heading: a table header stranded alone at the
           foot of a page looks like the figures were cut off. */
        .recap-block { page-break-inside: avoid; }
        .item-name { font-weight: 700; }

        /* Nothing on this page nests a table inside another table or inside
           a display:table-cell: dompdf cannot reflow that and throws
           "Frame not found in cellmap", which is a 500 on every download.
           The recap and the payments are full-width blocks instead, and the
           totals are a plain right-aligned box. */
        .totals { margin-left: auto; margin-top: 16px; width: 300px; }
        .band { display: table; margin-top: 22px; width: 100%; }
        .band-cell { display: table-cell; vertical-align: bottom; width: 50%; }

        .totals-row { display: table; padding: 4px 0; width: 100%; }
        .totals-row span, .totals-row strong { display: table-cell; }
        .totals-row strong { text-align: right; }
        .grand { border-top: 2px solid #111827; font-size: 15px; margin-top: 4px; padding-top: 7px; }
        .due { border-top: 1px solid #cbd5e1; margin-top: 4px; padding-top: 6px; }
        .balance span, .balance strong { font-weight: 700; }

        .words { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; margin-top: 16px; padding: 10px 12px; }
        .terms { border-top: 1px solid #e2e8f0; color: #64748b; font-size: 9.5px; margin-top: 18px; padding-top: 9px; }
        .signature { border-top: 1px solid #cbd5e1; margin-left: auto; min-height: 72px; padding-top: 7px; text-align: center; width: 220px; }
        .signature img { max-height: 56px; max-width: 170px; }

        .watermark { color: #ef4444; font-size: 96px; font-weight: 700; left: 0; letter-spacing: .1em; opacity: .12; position: fixed; right: 0; text-align: center; top: 320px; transform: rotate(-24deg); }
        /* counter(page) only. dompdf resolves counter(pages) to 0, and
           "page 1 / 0" on an invoice reads like a defect in the shop's
           software to the client holding it. */
        .page-number:after { content: "page " counter(page); }
        .page-footer { bottom: -34px; color: #94a3b8; font-size: 9px; left: 0; position: fixed; right: 0; text-align: center; }
    </style>
</head>
<body>
    @if ($watermark)
        <div class="watermark">{{ $watermark }}</div>
    @endif

    <header class="header">
        <div class="brand">
            @if (($settings['show_logo'] ?? true) && ! empty($company['logo_src']))
                <img class="logo" src="{{ $company['logo_src'] }}" alt="">
            @endif
            <div class="brand-copy">
                <h2>{{ $company['store_name'] ?: $tenant->name }}</h2>
                @if ($company['address'] ?: $tenant->address)
                    <p class="muted">{{ $company['address'] ?: $tenant->address }}</p>
                @endif
                <p class="muted">
                    {{ $company['phone'] ?: $company['mobile'] ?: $tenant->phone }}
                    @if ($company['email'] ?: $tenant->email) · {{ $company['email'] ?: $tenant->email }} @endif
                </p>
                @if ($legal)
                    <p class="muted legal">
                        @foreach ($legal as $key => $value){{ $key }} {{ $value }}@if (! $loop->last) · @endif @endforeach
                    </p>
                @endif
            </div>
        </div>
        <div class="doc-meta">
            <h1>{{ $title }}</h1>
            <p><strong>{{ $document->number }}</strong></p>
            <p class="muted">Date : {{ $date($document->issue_date) }}</p>
            @if ($isInvoice)
                <p class="muted">Échéance : {{ $date($document->due_date) }}</p>
            @else
                <p class="muted">Valable jusqu’au : {{ $date($document->expiration_date) }}</p>
            @endif
            <span class="badge">{{ $statusLabel }}</span>
        </div>
    </header>

    <section class="box-row">
        <div class="box">
            <span class="label">{{ $isInvoice ? 'Facturé à' : 'Devis pour' }}</span>
            <p><strong>{{ $snapshot['name'] ?? 'Client comptoir' }}</strong></p>
            {{-- Only when it adds something: a contact whose name IS the
                 company would otherwise be printed twice. --}}
            @if (! empty($snapshot['company_name']) && $snapshot['company_name'] !== ($snapshot['name'] ?? null))
                <p>{{ $snapshot['company_name'] }}</p>
            @endif
            @if (! empty($snapshot['billing_address']))<p class="muted">{{ $snapshot['billing_address'] }}</p>@endif
            @if (! empty($snapshot['phone']))<p class="muted">{{ $snapshot['phone'] }}</p>@endif
            @if (! empty($snapshot['email']))<p class="muted">{{ $snapshot['email'] }}</p>@endif
            @if (! empty($snapshot['ice']))<p class="muted">ICE {{ $snapshot['ice'] }}</p>@endif
        </div>
        <div class="box">
            <span class="label">Références</span>
            <p><strong>N° :</strong> {{ $document->number }}</p>
            @if ($document->customer_reference)<p><strong>Votre réf. :</strong> {{ $document->customer_reference }}</p>@endif
            @if ($document->service_date)<p><strong>Prestation :</strong> {{ $date($document->service_date) }}</p>@endif
            @if ($document->creator)<p><strong>Établi par :</strong> {{ $document->creator->name }}</p>@endif
            <p><strong>Devise :</strong> {{ $currency }}</p>
        </div>
    </section>

    <table class="grid">
        <thead>
            <tr>
                <th>Désignation</th>
                <th class="right">Qté</th>
                <th class="right">P.U. HT</th>
                <th class="right">Remise</th>
                <th class="right">TVA</th>
                <th class="right">Total {{ $document->items->contains(fn ($line) => $line->tax_inclusive) ? 'TTC' : 'HT' }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->items as $line)
                <tr>
                    <td>
                        <span class="item-name">{{ $line->name }}</span>
                        @if ($line->description)<br><span class="muted">{{ $line->description }}</span>@endif
                        @if ($line->sku || $line->barcode)<br><span class="muted">{{ $line->sku ?: $line->barcode }}</span>@endif
                    </td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, ',', ' '), '0'), ',') }}{{ $line->unit ? ' '.$line->unit : '' }}</td>
                    <td class="right">{{ $money($line->unit_price) }}</td>
                    <td class="right">{{ (float) $line->discount_amount > 0 ? $money($line->discount_amount) : '—' }}</td>
                    <td class="right">{{ number_format((float) $line->tax_rate, 2, ',', ' ') }} %</td>
                    <td class="right"><strong>{{ $money($line->total) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="totals">
        <div class="totals-row"><span>Sous-total brut</span><strong>{{ $money($document->gross_subtotal) }}</strong></div>
        @if ((float) $document->line_discount_total > 0)
            <div class="totals-row"><span>Remises lignes</span><strong>− {{ $money($document->line_discount_total) }}</strong></div>
        @endif
        @if ((float) $document->document_discount_total > 0)
            <div class="totals-row"><span>Remise globale</span><strong>− {{ $money($document->document_discount_total) }}</strong></div>
        @endif
        <div class="totals-row"><span>Total HT</span><strong>{{ $money($document->subtotal) }}</strong></div>
        <div class="totals-row"><span>TVA</span><strong>{{ $money($document->tax_total) }}</strong></div>
        @if ((float) $document->fee_total != 0.0)
            <div class="totals-row"><span>Frais</span><strong>{{ $money($document->fee_total) }}</strong></div>
        @endif
        @if ((float) $document->rounding_total != 0.0)
            <div class="totals-row"><span>Arrondi</span><strong>{{ $money($document->rounding_total) }}</strong></div>
        @endif
        <div class="totals-row grand"><span>Total TTC</span><strong>{{ $money($document->total) }}</strong></div>
        @if ($isInvoice)
            <div class="totals-row due"><span>Déjà réglé</span><strong>{{ $money($document->amount_paid) }}</strong></div>
            {{-- No <strong> inside the <span>: both are display:table-cell
                 here, and a cell nested in a cell is what dompdf reports as
                 "Frame not found in cellmap" — a 500 on every download. --}}
            <div class="totals-row balance"><span>Reste à payer</span><strong>{{ $money($document->balance_due) }}</strong></div>
        @endif
    </section>

    @if ($taxGroups->isNotEmpty())
        <div class="recap-block">
        <span class="label" style="margin-top: 18px;">Récapitulatif TVA</span>
        <table class="grid" style="margin-top: 4px;">
            <thead>
                <tr>
                    <th style="width: 20%;">Taux</th>
                    <th class="right" style="width: 40%;">Base HT</th>
                    <th class="right" style="width: 40%;">Montant TVA</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($taxGroups as $group)
                    <tr>
                        <td>{{ number_format((float) ($group['rate'] ?? 0), 2, ',', ' ') }} %</td>
                        <td class="right">{{ $money($group['taxable'] ?? 0) }}</td>
                        <td class="right">{{ $money($group['tax'] ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif

    @if ($isInvoice && $document->payments->isNotEmpty())
        <div class="recap-block">
        <span class="label" style="margin-top: 18px;">Règlements</span>
        <table class="grid" style="margin-top: 4px;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Mode</th>
                    <th>Référence</th>
                    <th class="right">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($document->payments as $payment)
                    <tr>
                        <td>{{ $date($payment->paid_at) }}</td>
                        <td>{{ $payment->method }}</td>
                        <td>{{ $payment->reference ?: $payment->number }}</td>
                        <td class="right">{{ $money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif

    <div class="words">
        {{ $isInvoice ? 'Arrêtée la présente facture' : 'Arrêté le présent devis' }} à la somme de
        <strong>{{ AmountInWords::money($document->total, $currency) }}</strong>.
    </div>

    @if ($document->customer_message)
        <p style="margin-top: 14px;"><strong>Message :</strong> {{ $document->customer_message }}</p>
    @endif

    <section class="band">
        <div class="band-cell">
            @if (($settings['show_bank_details'] ?? false) && ! empty($company['bank_details']))
                <span class="label">Coordonnées bancaires</span>
                <p>{!! nl2br(e($company['bank_details'])) !!}</p>
            @endif
        </div>
        <div class="band-cell">
            @if ($settings['show_signature'] ?? false)
                <div class="signature">
                    @if (! empty($company['signature_src']))<img src="{{ $company['signature_src'] }}" alt="">@endif
                    <p class="muted">Signature &amp; cachet</p>
                </div>
            @endif
        </div>
    </section>

    @if ($document->terms ?: ($settings['terms'] ?? null))
        <p class="terms">{!! nl2br(e($document->terms ?: $settings['terms'])) !!}</p>
    @endif
    @if ($document->footer ?: ($settings['footer_text'] ?? null))
        <p class="terms">{!! nl2br(e($document->footer ?: $settings['footer_text'])) !!}</p>
    @endif

    {{-- Page numbers through CSS counters rather than dompdf's inline-PHP
         hook: that hook needs isPhpEnabled, which turns every rendered
         document into a script host, and a page number is not worth it. --}}
    <div class="page-footer">
        {{ $company['store_name'] ?: $tenant->name }} · {{ $title }} {{ $document->number }} · <span class="page-number"></span>
    </div>
</body>
</html>
