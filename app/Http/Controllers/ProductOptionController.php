<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Services\Catalogue\VariantService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The axes an article can vary on, and building its variants from them.
 *
 * Deliberately separate from VariantController: that one edits ONE variant
 * (its barcode, its price, its stock), this one decides which variants should
 * exist at all.
 */
class ProductOptionController extends Controller
{
    public function __construct(private readonly VariantService $variants)
    {
    }

    public function storeType(Request $request): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80',
                Rule::unique('option_types', 'name')->where('tenant_id', $tenant->id)],
            'presentation' => ['nullable', Rule::in(['list', 'swatch'])],
        ], [
            'name.unique' => 'Cette option existe déjà.',
        ]);

        OptionType::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'presentation' => $data['presentation'] ?? 'list',
            'sort_order' => (int) OptionType::where('tenant_id', $tenant->id)->max('sort_order') + 1,
        ]);

        return back()->with('status', 'Option « '.$data['name'].' » créée.');
    }

    public function updateType(Request $request, OptionType $optionType): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($optionType->tenant_id === $tenant->id, 404);

        $optionType->update($request->validate([
            'name' => ['required', 'string', 'max:80',
                Rule::unique('option_types', 'name')->where('tenant_id', $tenant->id)->ignore($optionType->id)],
            'presentation' => ['nullable', Rule::in(['list', 'swatch'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]));

        return back()->with('status', 'Option mise à jour.');
    }

    public function destroyType(Request $request, OptionType $optionType): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($optionType->tenant_id === $tenant->id, 404);

        // Retiring, not deleting, once anything is built on it: removing the
        // axis would take the meaning off every variant that used it and
        // leave the sales report unable to say what was sold.
        if ($optionType->values()->whereHas('optionType')->exists()
            && \App\Models\ItemVariantValue::where('option_type_id', $optionType->id)->exists()) {
            $optionType->update(['is_active' => false]);

            return back()->with('status', 'Option désactivée : des déclinaisons l’utilisent encore.');
        }

        $optionType->delete();

        return back()->with('status', 'Option supprimée.');
    }

    public function storeValue(Request $request, OptionType $optionType): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($optionType->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'value' => ['required', 'string', 'max:80',
                Rule::unique('option_values', 'value')->where('option_type_id', $optionType->id)],
            'short_label' => ['nullable', 'string', 'max:16'],
            'swatch' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'value.unique' => 'Cette valeur existe déjà pour cette option.',
            'swatch.regex' => 'La couleur doit être au format #RRGGBB.',
        ]);

        OptionValue::create([
            'tenant_id' => $tenant->id,
            'option_type_id' => $optionType->id,
            'value' => $data['value'],
            'short_label' => $data['short_label'] ?? null,
            'swatch' => $data['swatch'] ?? null,
            // Appended, never sorted alphabetically: S, M, L is the order a
            // shop means, and it is not the one a computer would choose.
            'sort_order' => (int) $optionType->values()->max('sort_order') + 1,
        ]);

        return back()->with('status', 'Valeur ajoutée.');
    }

    public function updateValue(Request $request, OptionValue $optionValue): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($optionValue->tenant_id === $tenant->id, 404);

        $optionValue->update($request->validate([
            'value' => ['required', 'string', 'max:80',
                Rule::unique('option_values', 'value')
                    ->where('option_type_id', $optionValue->option_type_id)
                    ->ignore($optionValue->id)],
            'short_label' => ['nullable', 'string', 'max:16'],
            'swatch' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]));

        return back()->with('status', 'Valeur mise à jour.');
    }

    public function destroyValue(Request $request, OptionValue $optionValue): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($optionValue->tenant_id === $tenant->id, 404);

        if (\App\Models\ItemVariantValue::where('option_value_id', $optionValue->id)->exists()) {
            $optionValue->update(['is_active' => false]);

            return back()->with('status', 'Valeur désactivée : des déclinaisons l’utilisent encore.');
        }

        $optionValue->delete();

        return back()->with('status', 'Valeur supprimée.');
    }

    /** Builds the article's variants from the axes and values ticked. */
    public function generate(Request $request, Item $item): RedirectResponse
    {
        $tenant = TenantContext::require($request);
        abort_unless($item->tenant_id === $tenant->id, 404);

        $request->validate([
            'options' => ['required', 'array', 'min:1'],
            'options.*' => ['array'],
            'options.*.*' => ['integer'],
        ], [
            'options.required' => 'Choisissez au moins une option et une valeur.',
        ]);

        // Keys arrive as strings from the form; the service looks option types
        // up by id.
        $selection = [];
        foreach ((array) $request->input('options', []) as $typeId => $valueIds) {
            $selection[(int) $typeId] = array_map('intval', array_filter((array) $valueIds));
        }

        $variants = $this->variants->generateMatrix($item, $selection);

        return back()->with('status', $variants->count().' déclinaison(s) pour « '.$item->title.' ».');
    }
}
