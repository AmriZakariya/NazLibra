<?php

namespace App\Services\Documents;

use Illuminate\Validation\ValidationException;

class CommercialDocumentCalculator
{
    public function calculate(array $payload): array
    {
        $lines = collect($payload['lines'] ?? []);
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Ajoutez au moins une ligne.']);
        }

        $calculatedLines = [];
        $grossSubtotal = 0;
        $lineDiscountTotal = 0;
        $subtotal = 0;
        $taxTotal = 0;
        $taxBreakdown = [];

        foreach ($lines->values() as $index => $line) {
            $quantityMilli = $this->quantityMilli($line['quantity'] ?? 0, "lines.$index.quantity");
            $unitPriceCents = $this->moneyCents($line['unit_price'] ?? 0, "lines.$index.unit_price");
            $taxRateBps = $this->rateBps($line['tax_rate'] ?? 0, "lines.$index.tax_rate");
            $taxInclusive = (bool) ($line['tax_inclusive'] ?? false);
            $lineGross = $this->mulQuantity($unitPriceCents, $quantityMilli);
            $discount = $this->discountCents(
                (string) ($line['discount_type'] ?? 'fixed'),
                $line['discount_value'] ?? 0,
                $lineGross,
                "lines.$index.discount_value"
            );
            $taxable = max(0, $lineGross - $discount);

            if ($taxInclusive) {
                $lineTax = $taxRateBps > 0 ? $this->roundDiv($taxable * $taxRateBps, 10000 + $taxRateBps) : 0;
                $lineSubtotal = $taxable - $lineTax;
                $lineTotal = $taxable;
            } else {
                $lineTax = $this->roundDiv($taxable * $taxRateBps, 10000);
                $lineSubtotal = $taxable;
                $lineTotal = $taxable + $lineTax;
            }

            $grossSubtotal += $lineGross;
            $lineDiscountTotal += $discount;
            $subtotal += $lineSubtotal;
            $taxTotal += $lineTax;

            $taxKey = number_format($taxRateBps / 100, 2, '.', '');
            $taxBreakdown[$taxKey] ??= ['rate' => $taxRateBps / 100, 'taxable' => 0, 'tax' => 0];
            $taxBreakdown[$taxKey]['taxable'] += $lineSubtotal;
            $taxBreakdown[$taxKey]['tax'] += $lineTax;

            $calculatedLines[] = array_merge($line, [
                'display_order' => (int) ($line['display_order'] ?? $index + 1),
                'quantity' => number_format($quantityMilli / 1000, 3, '.', ''),
                'unit_price' => $this->decimal($unitPriceCents),
                'discount_amount' => $this->decimal($discount),
                'tax_rate' => number_format($taxRateBps / 100, 4, '.', ''),
                'tax_amount' => $this->decimal($lineTax),
                'subtotal' => $this->decimal($lineSubtotal),
                'total' => $this->decimal($lineTotal),
            ]);
        }

        $documentDiscount = $this->discountCents(
            (string) ($payload['document_discount_type'] ?? 'fixed'),
            $payload['document_discount_value'] ?? 0,
            $subtotal,
            'document_discount_value'
        );
        $subtotalAfterDocumentDiscount = max(0, $subtotal - $documentDiscount);
        $feeTotal = $this->moneyCents($payload['fee_total'] ?? 0, 'fee_total');
        $roundingTotal = $this->moneyCents($payload['rounding_total'] ?? 0, 'rounding_total');

        $ratioNumerator = $subtotal > 0 ? $subtotalAfterDocumentDiscount : 0;
        $adjustedTaxTotal = 0;
        foreach ($taxBreakdown as $key => $group) {
            $adjustedTaxable = $subtotal > 0 ? $this->roundDiv($group['taxable'] * $ratioNumerator, $subtotal) : 0;
            $rateBps = (int) round($group['rate'] * 100);
            $adjustedTax = $this->roundDiv($adjustedTaxable * $rateBps, 10000);
            $taxBreakdown[$key]['taxable'] = $this->decimal($adjustedTaxable);
            $taxBreakdown[$key]['tax'] = $this->decimal($adjustedTax);
            $adjustedTaxTotal += $adjustedTax;
        }

        $grandTotal = max(0, $subtotalAfterDocumentDiscount + $adjustedTaxTotal + $feeTotal + $roundingTotal);

        return [
            'lines' => $calculatedLines,
            'gross_subtotal' => $this->decimal($grossSubtotal),
            'line_discount_total' => $this->decimal($lineDiscountTotal),
            'document_discount_type' => $payload['document_discount_type'] ?? 'fixed',
            'document_discount_value' => $this->decimal($this->moneyCents($payload['document_discount_value'] ?? 0, 'document_discount_value')),
            'document_discount_total' => $this->decimal($documentDiscount),
            'subtotal' => $this->decimal($subtotalAfterDocumentDiscount),
            'tax_total' => $this->decimal($adjustedTaxTotal),
            'fee_total' => $this->decimal($feeTotal),
            'rounding_total' => $this->decimal($roundingTotal),
            'total' => $this->decimal($grandTotal),
            'tax_breakdown' => array_values($taxBreakdown),
        ];
    }

    private function quantityMilli(mixed $value, string $field): int
    {
        $quantity = round((float) $value, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages([$field => 'La quantité doit être supérieure à zéro.']);
        }

        return (int) round($quantity * 1000);
    }

    private function moneyCents(mixed $value, string $field): int
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '' || ! is_numeric($normalized)) {
            throw ValidationException::withMessages([$field => 'Montant invalide.']);
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');
        $decimal = str_pad(substr($decimal, 0, 3), 3, '0');
        $cents = ((int) $whole * 100) + (int) substr($decimal, 0, 2);
        if ((int) $decimal[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    private function rateBps(mixed $value, string $field): int
    {
        $rate = round((float) $value, 4);
        if ($rate < 0 || $rate > 100) {
            throw ValidationException::withMessages([$field => 'Taux de taxe invalide.']);
        }

        return (int) round($rate * 100);
    }

    private function discountCents(string $type, mixed $value, int $baseCents, string $field): int
    {
        $type = strtolower($type ?: 'fixed');
        if (! in_array($type, ['fixed', 'percentage'], true)) {
            throw ValidationException::withMessages([$field => 'Type de remise invalide.']);
        }

        if ($type === 'percentage') {
            $percentBps = $this->rateBps($value, $field);
            return min($baseCents, $this->roundDiv($baseCents * $percentBps, 10000));
        }

        $discount = $this->moneyCents($value, $field);
        if ($discount < 0) {
            throw ValidationException::withMessages([$field => 'La remise ne peut pas être négative.']);
        }

        return min($baseCents, $discount);
    }

    private function mulQuantity(int $unitCents, int $quantityMilli): int
    {
        return $this->roundDiv($unitCents * $quantityMilli, 1000);
    }

    private function roundDiv(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            return 0;
        }

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    private function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
