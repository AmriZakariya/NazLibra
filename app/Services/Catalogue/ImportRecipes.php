<?php

namespace App\Services\Catalogue;

use App\Enums\ItemType;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The « Produits utilisés » cell of a services import: a service's recipe in
 * one line.
 *
 *   Gel nettoyant visage=5; [Masque] Masque hydratant=10*; 1 h > Huile=30
 *
 * `Produit=quantité` is in the product's content unit; `[Groupe]` makes the
 * row a choice (one per sale line), `*` after the quantity marks the group's
 * default; `Variante > Produit=quantité` is the row for that variant (0 drops
 * the base product for it). Parsing is pure; applying goes through the same
 * ItemComponentService as the back-office editor and the API.
 */
class ImportRecipes
{
    public function __construct(private readonly ItemComponentService $components)
    {
    }

    /** One spelling for accents and case, so « Épilation » and « épilation » meet. */
    public static function key(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }

        return mb_strtolower($text);
    }

    /**
     * @return array{clear: bool, rows: list<array{variant: ?string, group: ?string, product: string, quantity: float, default: bool}>, errors: list<string>}
     */
    public static function parse(string $cell): array
    {
        $cell = trim($cell);
        if ($cell === '-') {
            return ['clear' => true, 'rows' => [], 'errors' => []];
        }

        $rows = [];
        $errors = [];
        $seen = [];
        $defaults = [];
        $pieces = array_values(array_filter(array_map('trim', preg_split('/[;\n\r]+/u', $cell) ?: []), fn (string $p) => $p !== ''));

        foreach ($pieces as $piece) {
            $eq = strrpos($piece, '=');
            if ($eq === false) {
                $errors[] = "« {$piece} » ignoré (écrivez Produit=quantité)";
                continue;
            }
            $left = trim(substr($piece, 0, $eq));
            $right = trim(substr($piece, $eq + 1));

            $default = false;
            if (str_ends_with($right, '*')) {
                $default = true;
                $right = trim(substr($right, 0, -1));
            }
            $quantity = self::number($right);
            if ($quantity === null) {
                $errors[] = "« {$piece} » ignoré (quantité « {$right} » illisible)";
                continue;
            }

            $variant = null;
            if (str_contains($left, '>')) {
                [$variant, $left] = array_map('trim', explode('>', $left, 2));
                if ($variant === '') {
                    $errors[] = "« {$piece} » ignoré (variante vide avant « > »)";
                    continue;
                }
            }
            $group = null;
            if (preg_match('/^\[([^\]]*)\]\s*(.*)$/us', $left, $m)) {
                $group = trim($m[1]) === '' ? null : mb_substr(trim($m[1]), 0, 80);
                $left = trim($m[2]);
            }
            if ($left === '') {
                $errors[] = "« {$piece} » ignoré (produit manquant)";
                continue;
            }
            if ($quantity > 999999 || $quantity < 0) {
                $errors[] = "« {$piece} » ignoré (quantité hors limites)";
                continue;
            }
            if ($quantity == 0.0 && $variant === null) {
                $errors[] = "« {$piece} » ignoré (la quantité doit être supérieure à zéro ; 0 n'a de sens que pour une variante)";
                continue;
            }

            $dupKey = self::key((string) $variant).'|'.self::key($left);
            if (isset($seen[$dupKey])) {
                $errors[] = "« {$piece} » ignoré (produit déjà indiqué)";
                continue;
            }
            $seen[$dupKey] = true;

            if ($default && $group === null) {
                $errors[] = "« * » ignoré pour « {$left} » (le produit par défaut se choisit dans un groupe [Groupe])";
                $default = false;
            } elseif ($default) {
                $scope = self::key((string) $variant).'|'.self::key($group);
                if (isset($defaults[$scope])) {
                    $errors[] = "« * » ignoré pour « {$left} » (le groupe « {$group} » a déjà un produit par défaut)";
                    $default = false;
                } else {
                    $defaults[$scope] = true;
                }
            }

            $rows[] = ['variant' => $variant, 'group' => $group, 'product' => $left, 'quantity' => round($quantity, 3), 'default' => $default];
        }

        return ['clear' => false, 'rows' => $rows, 'errors' => $errors];
    }

    /**
     * Replace the service's recipe with what the cell says. Returns the row
     * warnings; the recipe is left as it was when nothing in the cell is usable.
     *
     * @param  Collection<string, Item>  $products  products of the shop by key() of their title
     * @return list<string>
     */
    public function apply(Item $service, string $cell, Collection $products): array
    {
        if ($service->type !== ItemType::Service->value) {
            return [];
        }
        $parsed = self::parse($cell);
        if ($parsed['clear']) {
            $this->components->sync($service, []);

            return [];
        }

        $warnings = $parsed['errors'];
        $variants = $service->variants()->get()->keyBy(fn ($v) => self::key((string) $v->name));
        $rows = [];
        foreach ($parsed['rows'] as $r) {
            $product = $products->get(self::key($r['product']));
            if (! $product || $product->type === ItemType::Service->value) {
                $warnings[] = "produit « {$r['product']} » introuvable (importez d'abord les produits)";
                continue;
            }
            $variantId = null;
            if ($r['variant'] !== null) {
                $variant = $variants->get(self::key($r['variant']));
                if (! $variant) {
                    $warnings[] = "variante « {$r['variant']} » introuvable pour « {$service->title} »";
                    continue;
                }
                $variantId = (int) $variant->id;
            }
            $rows[] = [
                'component_item_id' => (int) $product->id,
                'quantity' => $r['quantity'],
                'variant_id' => $variantId,
                'choice_group' => $r['group'],
                'is_default' => $r['default'],
                'sort_order' => count($rows),
            ];
        }

        if ($rows === []) {
            $warnings[] = 'aucun produit utilisable : la recette reste inchangée';

            return $warnings;
        }

        try {
            $this->components->sync($service, $rows);
        } catch (ValidationException $e) {
            $warnings[] = 'recette inchangée : '.collect($e->errors())->flatten()->implode(' ');
        }

        return $warnings;
    }

    /** « 5 », « 2,5 », « 0.25 » → float; null when unreadable. */
    private static function number(string $text): ?float
    {
        $text = str_replace(["\xc2\xa0", ' '], '', trim($text));
        if (! preg_match('/^\d+([.,]\d+)?$/', $text)) {
            return null;
        }

        return (float) str_replace(',', '.', $text);
    }

    /** Parse a capacity number: « 200 », « 1,5 ». Public for the products import. */
    public static function amount(string $text): ?float
    {
        return self::number($text);
    }
}
