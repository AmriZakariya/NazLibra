@php
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' '.$document->currency;
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : '—';
    $snapshot = $document->customer_snapshot ?? [];
    $title = $documentType === 'invoice' ? 'Facture' : 'Devis';
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: "DejaVu Sans", sans-serif; color: #0f172a; font-size: 12px; line-height: 1.45; }
        .header { display: table; width: 100%; margin-bottom: 26px; }
        .cell { display: table-cell; vertical-align: top; }
        .right { text-align: right; }
        h1 { margin: 0; font-size: 28px; }
        h2 { margin: 0 0 8px; font-size: 14px; text-transform: uppercase; color: #475569; letter-spacing: .04em; }
        .muted { color: #64748b; }
        .box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; color: #475569; font-size: 10px; text-transform: uppercase; text-align: left; padding: 9px; border-bottom: 1px solid #e2e8f0; }
        td { padding: 9px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .totals { width: 42%; margin-left: auto; margin-top: 16px; }
        .totals td { border: 0; padding: 5px 0; }
        .total-row td { border-top: 1px solid #cbd5e1; padding-top: 8px; font-size: 16px; font-weight: bold; }
        .badge { display: inline-block; border: 1px solid #cbd5e1; border-radius: 999px; padding: 4px 10px; color: #334155; }
        .footer { position: fixed; bottom: 18px; left: 0; right: 0; color: #94a3b8; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="cell">
            <h1>{{ $title }} {{ $document->number }}</h1>
            <p class="muted">Statut: <span class="badge">{{ $document->status }}</span></p>
        </div>
        <div class="cell right">
            <strong>{{ $tenant->name }}</strong><br>
            <span class="muted">{{ $tenant->address }}</span><br>
            <span class="muted">{{ $tenant->phone }} {{ $tenant->email ? '· '.$tenant->email : '' }}</span><br>
            @if($tenant->ice)<span class="muted">ICE: {{ $tenant->ice }}</span>@endif
        </div>
    </div>

    <div class="header">
        <div class="cell box" style="width: 52%;">
            <h2>Facturé à</h2>
            <strong>{{ $snapshot['name'] ?? 'Client comptoir' }}</strong><br>
            @if(!empty($snapshot['company_name']))<span>{{ $snapshot['company_name'] }}</span><br>@endif
            @if(!empty($snapshot['ice']))<span>ICE: {{ $snapshot['ice'] }}</span><br>@endif
            @if(!empty($snapshot['billing_address']))<span>{{ $snapshot['billing_address'] }}</span><br>@endif
            @if(!empty($snapshot['email']))<span>{{ $snapshot['email'] }}</span><br>@endif
            @if(!empty($snapshot['phone']))<span>{{ $snapshot['phone'] }}</span>@endif
        </div>
        <div class="cell" style="width: 4%;"></div>
        <div class="cell box">
            <table>
                <tr><td class="muted">Date</td><td class="right">{{ $date($document->issue_date) }}</td></tr>
                @if($documentType === 'invoice')
                    <tr><td class="muted">Échéance</td><td class="right">{{ $date($document->due_date) }}</td></tr>
                @else
                    <tr><td class="muted">Expiration</td><td class="right">{{ $date($document->expiration_date) }}</td></tr>
                @endif
                <tr><td class="muted">Devise</td><td class="right">{{ $document->currency }}</td></tr>
            </table>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Qté</th>
                <th class="right">PU HT</th>
                <th class="right">Remise</th>
                <th class="right">TVA</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($document->items as $line)
                <tr>
                    <td><strong>{{ $line->name }}</strong>@if($line->description)<br><span class="muted">{{ $line->description }}</span>@endif</td>
                    <td class="right">{{ number_format((float) $line->quantity, 3, ',', ' ') }}</td>
                    <td class="right">{{ $money($line->unit_price) }}</td>
                    <td class="right">{{ $money($line->discount_amount) }}</td>
                    <td class="right">{{ number_format((float) $line->tax_rate, 2, ',', ' ') }}%<br>{{ $money($line->tax_amount) }}</td>
                    <td class="right"><strong>{{ $money($line->total) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Sous-total brut</td><td class="right">{{ $money($document->gross_subtotal) }}</td></tr>
        <tr><td>Remises lignes</td><td class="right">{{ $money($document->line_discount_total) }}</td></tr>
        <tr><td>Remise document</td><td class="right">{{ $money($document->document_discount_total) }}</td></tr>
        <tr><td>Total HT</td><td class="right">{{ $money($document->subtotal) }}</td></tr>
        <tr><td>TVA</td><td class="right">{{ $money($document->tax_total) }}</td></tr>
        <tr><td>Frais</td><td class="right">{{ $money($document->fee_total) }}</td></tr>
        <tr class="total-row"><td>Total TTC</td><td class="right">{{ $money($document->total) }}</td></tr>
        @if($documentType === 'invoice')
            <tr><td>Payé</td><td class="right">{{ $money($document->amount_paid) }}</td></tr>
            <tr><td>Reste dû</td><td class="right">{{ $money($document->balance_due) }}</td></tr>
        @endif
    </table>

    @if($document->customer_message || $document->terms || $document->footer)
        <div class="box" style="margin-top: 24px;">
            @if($document->customer_message)<p><strong>Message:</strong> {{ $document->customer_message }}</p>@endif
            @if($document->terms)<p><strong>Conditions:</strong> {{ $document->terms }}</p>@endif
            @if($document->footer)<p>{{ $document->footer }}</p>@endif
        </div>
    @endif

    <div class="footer">{{ $tenant->name }} · {{ $title }} {{ $document->number }}</div>
</body>
</html>
