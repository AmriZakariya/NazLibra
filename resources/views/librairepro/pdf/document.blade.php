<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px; }
        * { box-sizing: border-box; }
        body {
            color: #111827;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 12px;
            line-height: 1.45;
            margin: 0;
        }
        .muted { color: #64748b; }
        .header {
            border-bottom: 2px solid {{ $settings['primary_color'] }};
            display: table;
            padding-bottom: 18px;
            width: 100%;
        }
        .brand, .doc-meta { display: table-cell; vertical-align: top; }
        .brand { width: 58%; }
        .doc-meta { text-align: right; width: 42%; }
        .logo {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            display: inline-block;
            height: 62px;
            margin-right: 12px;
            object-fit: contain;
            padding: 6px;
            vertical-align: top;
            width: 86px;
        }
        .brand-copy { display: inline-block; max-width: 370px; vertical-align: top; }
        h1, h2, h3, p { margin: 0; }
        h1 { color: {{ $settings['primary_color'] }}; font-size: 25px; letter-spacing: .02em; text-transform: uppercase; }
        h2 { font-size: 18px; margin-bottom: 5px; }
        .badge {
            background: {{ $settings['primary_color'] }};
            border-radius: 999px;
            color: #fff;
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            margin-top: 8px;
            padding: 5px 10px;
            text-transform: uppercase;
        }
        .box-row { display: table; margin-top: 18px; width: 100%; }
        .box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            display: table-cell;
            padding: 13px;
            vertical-align: top;
            width: 50%;
        }
        .box + .box { border-left: 10px solid #fff; }
        .label {
            color: {{ $settings['primary_color'] }};
            display: block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        .note {
            border-left: 4px solid {{ $settings['accent_color'] }};
            margin-top: 16px;
            padding: 8px 12px;
        }
        table { border-collapse: collapse; margin-top: 18px; width: 100%; }
        th {
            background: #eef2ff;
            color: #334155;
            font-size: 10px;
            letter-spacing: .06em;
            padding: 9px 8px;
            text-align: left;
            text-transform: uppercase;
        }
        td {
            border-bottom: 1px solid #e5e7eb;
            padding: 9px 8px;
            vertical-align: top;
        }
        .right { text-align: right; }
        .center { text-align: center; }
        .item-name { font-weight: 700; }
        .totals {
            margin-left: auto;
            margin-top: 18px;
            width: 310px;
        }
        .totals-row {
            display: table;
            padding: 5px 0;
            width: 100%;
        }
        .totals-row span, .totals-row strong { display: table-cell; }
        .totals-row strong { text-align: right; }
        .grand {
            border-top: 2px solid #111827;
            font-size: 16px;
            margin-top: 5px;
            padding-top: 8px;
        }
        .footer-grid { display: table; margin-top: 30px; width: 100%; }
        .footer-cell { display: table-cell; vertical-align: bottom; width: 50%; }
        .signature {
            border-top: 1px solid #cbd5e1;
            margin-left: auto;
            min-height: 78px;
            padding-top: 8px;
            text-align: center;
            width: 230px;
        }
        .signature img { max-height: 58px; max-width: 180px; object-fit: contain; }
        .terms {
            border-top: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 10px;
            margin-top: 20px;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">
            @if ($settings['show_logo'] && $company['logo_src'])
                <img class="logo" src="{{ $company['logo_src'] }}" alt="">
            @endif
            <div class="brand-copy">
                <h2>{{ $company['store_name'] }}</h2>
                <p class="muted">{{ $company['address'] ?: $tenant->address }}</p>
                <p class="muted">
                    {{ $company['phone'] ?: $company['mobile'] ?: $tenant->phone }}
                    @if ($company['email']) · {{ $company['email'] }} @endif
                </p>
                @if ($company['gst_no'] || $company['rc'] || $company['vat_no'])
                    <p class="muted">ICE {{ $company['gst_no'] ?: '—' }} @if($company['rc']) · RC {{ $company['rc'] }} @endif @if($company['vat_no']) · TVA {{ $company['vat_no'] }} @endif</p>
                @endif
            </div>
        </div>
        <div class="doc-meta">
            <h1>{{ $document['title'] }}</h1>
            <p><strong>{{ $document['number'] }}</strong></p>
            <p class="muted">Date: {{ $formatDate($document['date']) }}</p>
            @if (! empty($document['due_date']))
                <p class="muted">Échéance / prévu: {{ $formatDate($document['due_date']) }}</p>
            @endif
            <span class="badge">{{ $document['status'] ?: 'document' }}</span>
        </div>
    </header>

    @if ($document['rendered_header'])
        <div class="note">{{ $document['rendered_header'] }}</div>
    @elseif ($document['rendered_note'])
        <div class="note">{{ $document['rendered_note'] }}</div>
    @endif

    <section class="box-row">
        <div class="box">
            <span class="label">{{ $document['partner_label'] }}</span>
            <p><strong>{{ $document['partner']?->name ?? ($document['partner_label'] === 'Client' ? 'Client Grand Public' : '—') }}</strong></p>
            @if ($document['partner']?->phone)<p class="muted">{{ $document['partner']->phone }}</p>@endif
            @if ($document['partner']?->email)<p class="muted">{{ $document['partner']->email }}</p>@endif
            @if ($document['partner']?->address)<p class="muted">{{ $document['partner']->address }}</p>@endif
            @if ($document['partner']?->ice)<p class="muted">ICE {{ $document['partner']->ice }}</p>@endif
        </div>
        <div class="box">
            <span class="label">Références</span>
            <p><strong>N° document:</strong> {{ $document['number'] }}</p>
            <p><strong>Référence:</strong> {{ $document['reference'] ?: '—' }}</p>
            @if ($document['payment_method'])
                <p><strong>Paiement:</strong> {{ $document['payment_method'] }}</p>
            @endif
            @if (! empty($document['created_by']))
                <p><strong>Créé par:</strong> {{ $document['created_by'] }}</p>
            @endif
            @if (! empty($document['updated_by']))
                <p><strong>Mis à jour par:</strong> {{ $document['updated_by'] }}</p>
            @endif
            @if ($document['note'])
                <p class="muted">{{ $document['note'] }}</p>
            @endif
        </div>
    </section>

    <table>
        <thead>
            <tr>
                <th>Article / service</th>
                <th class="center">Qté</th>
                @if ($document['type'] === 'purchase')
                    <th class="center">Reçu</th>
                @endif
                <th class="right">Prix unitaire</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document['lines'] as $line)
                <tr>
                    <td>
                        <span class="item-name">{{ $line['name'] }}</span>
                        @if ($line['code'])
                            <br><small class="muted">{{ $line['code'] }}</small>
                        @endif
                    </td>
                    <td class="center">{{ number_format($line['quantity'], 0, ',', ' ') }}</td>
                    @if ($document['type'] === 'purchase')
                        <td class="center">{{ number_format($line['received'] ?? 0, 0, ',', ' ') }}</td>
                    @endif
                    <td class="right">{{ $money($line['unit_price']) }}</td>
                    <td class="right"><strong>{{ $money($line['total']) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="totals">
        <div class="totals-row"><span>Sous-total</span><strong>{{ $money($document['totals']['subtotal']) }}</strong></div>
        @if (($document['totals']['discount'] ?? 0) > 0)
            <div class="totals-row"><span>Remise</span><strong>{{ $money($document['totals']['discount']) }}</strong></div>
        @endif
        @if (($document['totals']['tax'] ?? 0) > 0)
            <div class="totals-row"><span>TVA incluse</span><strong>{{ $money($document['totals']['tax']) }}</strong></div>
        @endif
        @if ($document['type'] !== 'purchase')
            <div class="totals-row"><span>Payé</span><strong>{{ $money($document['totals']['paid']) }}</strong></div>
            <div class="totals-row"><span>Reste</span><strong>{{ $money($document['totals']['due']) }}</strong></div>
        @else
            <div class="totals-row"><span>Qté commandée</span><strong>{{ number_format($document['totals']['ordered'] ?? 0, 0, ',', ' ') }}</strong></div>
            <div class="totals-row"><span>Qté reçue</span><strong>{{ number_format($document['totals']['received'] ?? 0, 0, ',', ' ') }}</strong></div>
        @endif
        <div class="totals-row grand"><span>Total</span><strong>{{ $money($document['totals']['total']) }}</strong></div>
    </section>

    <section class="footer-grid">
        <div class="footer-cell">
            @if ($settings['show_bank_details'] && $company['bank_details'])
                <span class="label">Banque</span>
                <p>{!! nl2br(e($company['bank_details'])) !!}</p>
            @endif
        </div>
        <div class="footer-cell">
            @if ($settings['show_signature'])
                <div class="signature">
                    @if ($company['signature_src'])
                        <img src="{{ $company['signature_src'] }}" alt="">
                    @endif
                    <p class="muted">Signature & cachet</p>
                </div>
            @endif
        </div>
    </section>

    @if ($document['rendered_footer'])
        <p class="terms">{{ $document['rendered_footer'] }}</p>
    @endif
    @if ($document['rendered_terms'])
        <p class="terms">{!! nl2br(e($document['rendered_terms'])) !!}</p>
    @endif
</body>
</html>
