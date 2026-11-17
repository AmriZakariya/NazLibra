<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * What a shop's legal identifiers are called, by its country.
 *
 * The fields stay the same five (gst_no, rc, vat_no, cnss, pan_no); only
 * their names change. They used to be Morocco's everywhere, so a shop in
 * Cairo printed « ICE » on its invoices, a number Egypt does not have,
 * instead of its tax registration number (رقم التسجيل الضريبي).
 *
 * Labels are French, the back office's source language; the page and the
 * PDFs translate them like any other text.
 */
final class BusinessIds
{
    /** country => field => label, as Paramètres › Société asks for them. */
    private const SETTINGS = [
        'MA' => ['cnss' => 'CNSS', 'rc' => 'RC', 'gst_no' => 'ICE / GST No', 'vat_no' => 'TVA / VAT No', 'pan_no' => 'PAN / identifiant fiscal'],
        'EG' => ['gst_no' => 'N° d’enregistrement fiscal', 'rc' => 'Registre de commerce', 'vat_no' => 'N° de carte fiscale', 'cnss' => 'Assurances sociales', 'pan_no' => 'Autre identifiant'],
        // Tunisia: the matricule fiscal carries the VAT code, and the RNE
        // replaced the registre de commerce in 2019.
        'TN' => ['gst_no' => 'Matricule fiscal (MF)', 'rc' => 'Identifiant RNE', 'cnss' => 'N° CNSS', 'vat_no' => 'Code en douane', 'pan_no' => 'Autre identifiant'],
    ];

    /** country => field => label, as a document prints them, in that order. */
    private const PRINTED = [
        'MA' => ['gst_no' => 'ICE', 'rc' => 'RC', 'vat_no' => 'IF', 'cnss' => 'CNSS'],
        'EG' => ['gst_no' => 'N° d’enregistrement fiscal', 'rc' => 'Registre de commerce', 'vat_no' => 'N° de carte fiscale'],
        'TN' => ['gst_no' => 'MF', 'rc' => 'RNE', 'cnss' => 'CNSS'],
    ];

    /** The identifier a customer or supplier gives, by the shop's country. */
    private const PARTNER = [
        'MA' => 'ICE',
        'EG' => 'N° d’enregistrement fiscal',
        'TN' => 'Matricule fiscal',
    ];

    private const DEFAULT_SETTINGS = ['gst_no' => 'Identifiant fiscal', 'vat_no' => 'N° TVA', 'rc' => 'Registre de commerce', 'cnss' => 'Sécurité sociale', 'pan_no' => 'Autre identifiant'];

    private const DEFAULT_PRINTED = ['gst_no' => 'Identifiant fiscal', 'vat_no' => 'N° TVA', 'rc' => 'Registre de commerce'];

    public static function country(?Tenant $tenant): string
    {
        return Phone::defaultCountry($tenant);
    }

    /** @return array<string, string> field => label */
    public static function settings(?Tenant $tenant): array
    {
        return self::SETTINGS[self::country($tenant)] ?? self::DEFAULT_SETTINGS;
    }

    /** @return array<string, string> field => label */
    public static function printed(?Tenant $tenant): array
    {
        return self::PRINTED[self::country($tenant)] ?? self::DEFAULT_PRINTED;
    }

    /** The shop's main identifier on paper: « ICE », « N° d’enregistrement fiscal »… */
    public static function main(?Tenant $tenant): string
    {
        return self::printed($tenant)['gst_no'];
    }

    public static function partner(?Tenant $tenant): string
    {
        return self::PARTNER[self::country($tenant)] ?? 'Identifiant fiscal';
    }

    /**
     * The shop's filled identifiers, labelled: ['ICE' => '0012…', 'RC' => '…'].
     *
     * @param  array<string, mixed>  $company
     * @return array<string, string>
     */
    public static function lines(?Tenant $tenant, array $company): array
    {
        $lines = [];
        foreach (self::printed($tenant) as $field => $label) {
            if (filled($company[$field] ?? null)) {
                $lines[$label] = (string) $company[$field];
            }
        }

        return $lines;
    }
}
