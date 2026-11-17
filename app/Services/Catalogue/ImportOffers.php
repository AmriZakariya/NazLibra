<?php

namespace App\Services\Catalogue;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\OptionType;
use App\Models\OptionValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The « Variantes » and « Options » cells of the catalogue import: a price
 * list as a salon writes it — « Court=20; Mi-long=40; Long=60 » or
 * « 30 min=200@30; 1 h=400@60 » — turned into real variants and options.
 *
 * The parsing is pure (no database) so every malformed cell can be tested
 * and reported as a warning on its row, never as a failed import. Applying
 * is idempotent: a re-import updates by name and never duplicates.
 */
class ImportOffers
{
    /** The axis every imported variant sits on. */
    public const AXIS = 'Variante';

    /** Same bounds as the item's own duration. */
    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 600;

    public function __construct(private readonly VariantService $variants)
    {
    }

    /**
     * « Court=20; Mi-long=40@30 » → names, prices and optional durations.
     *
     * @return array{variants: list<array{name: string, price: float, duration: ?int}>, errors: list<string>}
     */
    public static function parseVariants(string $cell): array
    {
        $variants = [];
        $errors = [];
        $seen = [];

        foreach (self::pieces($cell) as $piece) {
            if (! preg_match('/^(.+?)\s*=\s*([^@]+?)\s*(?:@\s*(.+))?$/u', $piece, $m)) {
                $errors[] = "variante « {$piece} » ignorée (forme attendue : Nom=prix ou Nom=prix@durée)";
                continue;
            }

            $name = trim($m[1]);
            $price = self::amount($m[2]);

            if ($name === '' || mb_strlen($name) > 80) {
                $errors[] = "variante « {$piece} » ignorée (nom vide ou trop long)";
                continue;
            }
            if ($price === null || $price < 0) {
                $errors[] = "variante « {$name} » ignorée (prix « ".trim($m[2]).' » illisible)';
                continue;
            }
            if (isset($seen[mb_strtolower($name)])) {
                $errors[] = "variante « {$name} » en double, la première est gardée";
                continue;
            }

            $duration = null;
            if (isset($m[3]) && trim($m[3]) !== '') {
                $duration = self::duration($m[3]);
                if ($duration === null) {
                    $errors[] = "durée « ".trim($m[3])." » de la variante « {$name} » ignorée (entre ".self::MIN_MINUTES.' et '.self::MAX_MINUTES.' minutes)';
                }
            }

            $seen[mb_strtolower($name)] = true;
            $variants[] = ['name' => $name, 'price' => $price, 'duration' => $duration];
        }

        return ['variants' => $variants, 'errors' => $errors];
    }

    /**
     * « Paraffine=+50; Vernis=+20 » → names and price deltas.
     *
     * @return array{options: list<array{name: string, delta: float}>, errors: list<string>}
     */
    public static function parseOptions(string $cell): array
    {
        $options = [];
        $errors = [];
        $seen = [];

        foreach (self::pieces($cell) as $piece) {
            if (! preg_match('/^(.+?)\s*=\s*(.+)$/u', $piece, $m)) {
                $errors[] = "option « {$piece} » ignorée (forme attendue : Nom=+prix)";
                continue;
            }

            $name = trim($m[1]);
            $delta = self::amount($m[2]);

            if ($name === '' || mb_strlen($name) > 120) {
                $errors[] = "option « {$piece} » ignorée (nom vide ou trop long)";
                continue;
            }
            if ($delta === null) {
                $errors[] = "option « {$name} » ignorée (supplément « ".trim($m[2]).' » illisible)';
                continue;
            }
            if (isset($seen[mb_strtolower($name)])) {
                $errors[] = "option « {$name} » en double, la première est gardée";
                continue;
            }

            $seen[mb_strtolower($name)] = true;
            $options[] = ['name' => $name, 'delta' => $delta];
        }

        return ['options' => $options, 'errors' => $errors];
    }

    /** oui / non, yes / no, 1 / 0, x, vrai / faux. Null when empty or unreadable. */
    public static function flag(mixed $value): ?bool
    {
        $text = Str::lower(Str::ascii(trim((string) $value)));

        if ($text === '') {
            return null;
        }
        if (in_array($text, ['oui', 'o', 'yes', 'y', '1', 'x', 'vrai', 'true', 'si'], true)) {
            return true;
        }
        if (in_array($text, ['non', 'n', 'no', '0', 'faux', 'false'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Minutes from « 45 », « 45 min », « 1 h », « 1h30 », « 1,5 h ». Null when
     * unreadable or outside what an appointment accepts.
     */
    public static function duration(mixed $value): ?int
    {
        $text = Str::lower(Str::ascii(trim((string) $value)));
        if ($text === '') {
            return null;
        }

        $minutes = null;
        if (preg_match('/^(\d+)\s*h(?:eures?|rs?)?\s*(\d{1,2})?\s*(?:min\w*|mn)?$/', $text, $m)) {
            $minutes = (int) $m[1] * 60 + (int) ($m[2] ?? 0);
        } elseif (preg_match('/^(\d+(?:[.,]\d+)?)\s*h(?:eures?|rs?)?$/', $text, $m)) {
            $minutes = (int) round((float) str_replace(',', '.', $m[1]) * 60);
        } elseif (preg_match('/^(\d+)(?:\s*(?:min\w*|mn))?$/', $text, $m)) {
            $minutes = (int) $m[1];
        }

        if ($minutes === null || $minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
            return null;
        }

        return $minutes;
    }

    /**
     * Creates or updates the item's variants from the parsed cell.
     *
     * Matched by name (case-insensitive), so importing the same file twice
     * changes nothing. New ones go through VariantService, which keeps the
     * combination keys and the item's variant count true. Variants missing
     * from the cell are left alone: a price list is not a delete order.
     *
     * @param  list<array{name: string, price: float, duration: ?int}>  $wanted
     * @return list<string> warnings
     */
    public function applyVariants(Item $item, array $wanted): array
    {
        if ($wanted === []) {
            return [];
        }

        return DB::transaction(function () use ($item, $wanted): array {
            $warnings = [];
            $existing = $item->variants()->get();
            $byName = $existing->keyBy(fn (ItemVariant $v) => mb_strtolower(trim($v->name)));

            $axis = OptionType::firstOrCreate(
                ['tenant_id' => $item->tenant_id, 'name' => self::AXIS],
                ['presentation' => 'list', 'is_active' => true],
            );
            $axisValueIds = $axis->values()->pluck('id')->map(fn ($id) => (string) $id)->all();

            // A variant on another axis cannot be told apart by name from the
            // ones we are about to create, and VariantService would rewrite
            // the item's axes under it. Say so rather than half-do it.
            $foreign = $existing->contains(fn (ItemVariant $v) => $v->combination_key !== null
                && collect(explode('-', $v->combination_key))->diff($axisValueIds)->isNotEmpty());

            $missing = [];
            foreach ($wanted as $entry) {
                if (! $byName->has(mb_strtolower($entry['name']))) {
                    $missing[] = $entry;
                }
            }

            if ($missing !== [] && $foreign) {
                $warnings[] = 'l’article a déjà des variantes d’une autre nature (taille, couleur…) : '
                    .count($missing).' nouvelle(s) variante(s) non créée(s)';
                $missing = [];
            }

            if ($missing !== []) {
                $nextOrder = (int) $axis->values()->max('sort_order') + 1;
                $valueIds = [];
                foreach ($missing as $entry) {
                    $value = OptionValue::firstOrCreate(
                        ['option_type_id' => $axis->id, 'value' => $entry['name']],
                        ['tenant_id' => $item->tenant_id, 'sort_order' => $nextOrder++, 'is_active' => true],
                    );
                    if (! $value->is_active) {
                        $value->update(['is_active' => true]);
                    }
                    $valueIds[] = $value->id;
                }

                // Only the missing values: generateMatrix keeps what exists,
                // but would also (re)create any other value of the axis.
                $this->variants->generateMatrix($item, [$axis->id => $valueIds]);
                $byName = $item->variants()->get()->keyBy(fn (ItemVariant $v) => mb_strtolower(trim($v->name)));
            }

            $hasDuration = Schema::hasColumn('item_variants', 'duration_minutes');
            $order = 0;
            foreach ($wanted as $entry) {
                $variant = $byName->get(mb_strtolower($entry['name']));
                if (! $variant) {
                    continue;
                }

                $changes = [
                    'sale_price' => $entry['price'],
                    'sale_price_override' => $entry['price'],
                    'sort_order' => $order++,
                    'is_active' => true,
                    'status' => 'active',
                ];
                if ($hasDuration && $entry['duration'] !== null) {
                    $changes['duration_minutes'] = $entry['duration'];
                }
                $variant->forceFill($changes)->save();
            }

            $this->variants->refreshCount($item);

            return $warnings;
        });
    }

    /** The lowest price of a list, for the article's own price. */
    public static function lowestPrice(array $variants): ?float
    {
        return $variants === [] ? null : (float) min(array_column($variants, 'price'));
    }

    /**
     * One group of options per article (« Options — Pédicure »), attached to
     * it. Matched by name inside the group, so a re-import updates a price
     * rather than adding a second « Paraffine ».
     *
     * @param  list<array{name: string, delta: float}>  $wanted
     */
    public function applyOptions(Item $item, array $wanted): void
    {
        if ($wanted === []) {
            return;
        }

        DB::transaction(function () use ($item, $wanted): void {
            $group = ModifierGroup::firstOrCreate(
                ['tenant_id' => $item->tenant_id, 'name' => Str::limit('Options — '.$item->title, 120, '')],
                ['min_select' => 0, 'max_select' => null, 'sort_order' => 0, 'is_active' => true],
            );
            if (! $group->is_active) {
                $group->update(['is_active' => true]);
            }

            // Attached directly: Item::modifierGroups() only sees active groups.
            $already = DB::table('item_modifier_groups')
                ->where('item_id', $item->id)->where('modifier_group_id', $group->id)->exists();
            if (! $already) {
                DB::table('item_modifier_groups')->insert([
                    'tenant_id' => $item->tenant_id,
                    'item_id' => $item->id,
                    'modifier_group_id' => $group->id,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $existing = Modifier::where('modifier_group_id', $group->id)->get()
                ->keyBy(fn (Modifier $m) => mb_strtolower(trim($m->name)));
            $order = 0;
            foreach ($wanted as $entry) {
                $modifier = $existing->get(mb_strtolower($entry['name']));
                if ($modifier) {
                    $modifier->update(['price_delta' => $entry['delta'], 'sort_order' => $order++, 'is_active' => true]);
                } else {
                    Modifier::create([
                        'tenant_id' => $item->tenant_id,
                        'modifier_group_id' => $group->id,
                        'name' => $entry['name'],
                        'price_delta' => $entry['delta'],
                        'sort_order' => $order++,
                        'is_active' => true,
                    ]);
                }
            }
        });
    }

    /** @return list<string> */
    private static function pieces(string $cell): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[;\n\r]+/u', $cell) ?: []),
            fn (string $p) => $p !== '',
        ));
    }

    /** A price from « 20 », « +50 », « 20,5 », « 1 200.00 » or « 50 DH ». Null when unreadable. */
    private static function amount(string $text): ?float
    {
        $text = trim(str_replace(["\xc2\xa0", ' '], '', $text));
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');
        $text = preg_replace('/[^\d.,]/u', '', $text) ?? '';

        if ($text === '' || ! preg_match('/\d/', $text)) {
            return null;
        }

        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $text = str_replace($thousands, '', $text);
            $text = str_replace($decimal, '.', $text);
        } elseif ($lastComma !== false) {
            $text = str_replace(',', '.', $text);
        }
        if (substr_count($text, '.') > 1) {
            return null;
        }

        $value = round((float) $text, 2);

        return $negative ? -$value : $value;
    }
}
