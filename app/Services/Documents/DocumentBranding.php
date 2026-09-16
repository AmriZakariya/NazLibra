<?php

namespace App\Services\Documents;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * The shop's identity as it appears on paper: logo, legal identifiers, bank
 * details, colours.
 *
 * This lived as three private methods on the sales controller, which is why
 * the invoicing module's own PDF — the one document a client actually keeps —
 * carried none of it: no logo, no ICE, no RC. One definition now, so a
 * document cannot be branded in one place and anonymous in another.
 */
class DocumentBranding
{
    public function companyProfile(Tenant $tenant): array
    {
        return array_merge([
            'store_name' => $tenant->name,
            'store_code' => $tenant->slug,
            'mobile' => '',
            'phone' => $tenant->phone,
            'email' => $tenant->email,
            'gst_no' => $tenant->ice,
            'vat_no' => '',
            'rc' => '',
            'cnss' => '',
            'country' => 'Maroc',
            'state' => '',
            'city' => '',
            'postcode' => '',
            'address' => $tenant->address,
            'store_logo' => '',
            'signature' => '',
            'show_signature' => false,
            'bank_details' => '',
            'sales_invoice_footer_text' => '',
            'invoice_terms' => '',
        ], $tenant->settings['company_profile'] ?? []);
    }

    public function settings(Tenant $tenant): array
    {
        $company = $this->companyProfile($tenant);

        return array_merge([
            'sale_title' => 'Bon de vente',
            'invoice_title' => 'Facture',
            'purchase_title' => 'Bon d’achat',
            'primary_color' => data_get($tenant->settings, 'theme.primary', '#3157D5'),
            'accent_color' => data_get($tenant->settings, 'theme.accent', '#0F9F8A'),
            'header_text' => 'Document généré par {{store_name}} le {{today}}.',
            'sale_note_template' => 'Merci pour votre achat {{client_name}}. Ticket {{document_number}}.',
            'invoice_note_template' => 'Facture {{document_number}} liée à la vente {{sale_number}}. Total: {{total}}.',
            'purchase_note_template' => 'Commande fournisseur {{document_number}}. Référence: {{reference}}.',
            'footer_text' => $company['sales_invoice_footer_text'] ?: 'Merci pour votre confiance.',
            'terms' => $company['invoice_terms'] ?: 'Les marchandises restent la propriété du magasin jusqu’au paiement complet.',
            'show_logo' => true,
            'show_signature' => (bool) ($company['show_signature'] ?? false),
            'show_bank_details' => filled($company['bank_details'] ?? null),
        ], $tenant->settings['documents'] ?? []);
    }

    /**
     * A local filesystem path dompdf can read, or null.
     *
     * Deliberately a path and not a URL for anything we host: dompdf fetching
     * its own server over HTTP deadlocks a single-worker install, and that is
     * every shared host this ships to.
     */
    public function assetSource(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $path = Str::after($path, 'storage/');
        $publicStorage = public_path('storage/'.$path);
        if (is_file($publicStorage)) {
            return $publicStorage;
        }

        $publicPath = public_path($path);
        if (is_file($publicPath)) {
            return $publicPath;
        }

        return null;
    }
}
