<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * How a shop writes an amount: its currency, where the symbol goes, how many
 * decimals — the three things Paramètres → Société sets.
 *
 * Every amount used to be formatted with a hard-coded " DH", so a shop in
 * Paris that signed up in euros saw dirhams on every page, ticket and PDF.
 * The placement and decimals were saved and never read. Nothing is converted:
 * a price is the number the shop typed, in the shop's currency.
 *
 * The browser gets the same rules through toJs() (window.appCurrency), so the
 * POS and the form calculators print exactly what the server prints.
 */
final class Money
{
    public const DEFAULT_CURRENCY = 'MAD';

    /** How shops in these markets write the currency; anything else shows its ISO code. */
    public const SYMBOLS = [
        'MAD' => 'DH',
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'CHF' => 'CHF',
        'CAD' => '$ CA',
        'XOF' => 'FCFA',
        'XAF' => 'FCFA',
        'DZD' => 'DA',
        'TND' => 'DT',
        'AED' => 'AED',
        'SAR' => 'SAR',
        'EGP' => 'E£',
        'MRU' => 'UM',
    ];

    /** What the settings list offers, by name. */
    public const NAMES = [
        'MAD' => 'Dirham marocain',
        'EUR' => 'Euro',
        'USD' => 'Dollar américain',
        'XOF' => 'Franc CFA (UEMOA)',
        'XAF' => 'Franc CFA (CEMAC)',
        'DZD' => 'Dinar algérien',
        'TND' => 'Dinar tunisien',
        'GBP' => 'Livre sterling',
        'CHF' => 'Franc suisse',
        'CAD' => 'Dollar canadien',
        'AED' => 'Dirham des Émirats',
        'SAR' => 'Riyal saoudien',
        'EGP' => 'Livre égyptienne',
        'MRU' => 'Ouguiya mauritanien',
    ];

    /** @var array<string, self> per tenant, for the length of a request */
    private static array $memo = [];

    public function __construct(
        public readonly string $code,
        public readonly string $placement = 'Right',
        public readonly int $decimals = 2,
    ) {}

    public static function for(?Tenant $tenant): self
    {
        if ($tenant === null) {
            return new self(self::DEFAULT_CURRENCY);
        }

        $key = $tenant->getKey().'|'.$tenant->currency.'|'.json_encode(data_get($tenant->settings, 'number_format'));

        return self::$memo[$key] ??= new self(
            self::normalizeCode($tenant->currency),
            data_get($tenant->settings, 'number_format.currency_placement') === 'Left' ? 'Left' : 'Right',
            max(0, min(4, (int) data_get($tenant->settings, 'number_format.decimals', 2))),
        );
    }

    /** A record's own currency (an invoice keeps the one it was issued in), laid out the shop's way. */
    public function withCode(?string $code): self
    {
        $code = self::normalizeCode($code ?: $this->code);

        return $code === $this->code ? $this : new self($code, $this->placement, $this->decimals);
    }

    public static function normalizeCode(?string $code): string
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : self::DEFAULT_CURRENCY;
    }

    public static function symbolFor(?string $code): string
    {
        $code = self::normalizeCode($code);

        return self::SYMBOLS[$code] ?? $code;
    }

    public function name(): string
    {
        return self::NAMES[$this->code] ?? $this->code;
    }

    public function symbol(): string
    {
        return self::symbolFor($this->code);
    }

    /** "12,00 €", or "€ 12,00" when the symbol goes first. */
    public function format(float|int|string|null $amount): string
    {
        $number = $this->number($amount);

        return $this->placement === 'Left'
            ? $this->symbol().' '.$number
            : $number.' '.$this->symbol();
    }

    /** The amount alone, as the shop writes it: "1 250,00". */
    public function number(float|int|string|null $amount): string
    {
        return number_format((float) $amount, $this->decimals, ',', ' ');
    }

    /** "Prix (€)" — for a field label. */
    public function label(string $text): string
    {
        return $text.' ('.$this->symbol().')';
    }

    /** @return array{code: string, symbol: string, placement: string, decimals: int} */
    public function toJs(): array
    {
        return [
            'code' => $this->code,
            'symbol' => $this->symbol(),
            'placement' => $this->placement,
            'decimals' => $this->decimals,
        ];
    }

    /** For tests: a tenant's settings changed within one request. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
