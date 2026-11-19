@php
    $selectedProduct = $row['component_item_id'] ?? null;
    $selectedVariant = $row['variant_id'] ?? null;
@endphp
<tr class="border-t border-slate-100 dark:border-white/10">
    <td class="px-3 py-2">
        <select name="components[{{ $i }}][component_item_id]" data-component-product required class="{{ $fieldClass }}">
            <option value="">—</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" data-unit="{{ $product->hasCapacity() ? \App\Support\ContentUnits::label($product->content_unit, 2) : '' }}" @selected((int) $selectedProduct === (int) $product->id)>
                    {{ $product->title }}@if ($product->hasCapacity()) · {{ \App\Support\ContentUnits::format((float) $product->content_quantity, $product->content_unit) }}@endif
                </option>
            @endforeach
        </select>
    </td>
    <td class="px-3 py-2">
        <div class="flex items-center gap-2">
            <input name="components[{{ $i }}][quantity]" type="number" min="0" step="0.001" required inputmode="decimal" value="{{ $row['quantity'] ?? '' }}" class="{{ $fieldClass }} w-24">
            <span class="text-xs text-slate-500" data-component-unit></span>
        </div>
    </td>
    @if ($variants->isNotEmpty())
        <td class="px-3 py-2">
            <select name="components[{{ $i }}][variant_id]" class="{{ $fieldClass }}">
                <option value="">{{ $tr('Toutes les déclinaisons') }}</option>
                @foreach ($variants as $variant)
                    <option value="{{ $variant->id }}" @selected((int) $selectedVariant === (int) $variant->id)>{{ $variant->name }}</option>
                @endforeach
            </select>
        </td>
    @endif
    <td class="px-3 py-2"><input name="components[{{ $i }}][choice_group]" maxlength="80" value="{{ $row['choice_group'] ?? '' }}" placeholder="{{ $tr('Ex. : Masque') }}" class="{{ $fieldClass }}"></td>
    <td class="px-3 py-2"><input type="hidden" name="components[{{ $i }}][is_default]" value="0"><input type="checkbox" name="components[{{ $i }}][is_default]" value="1" @checked(! empty($row['is_default'])) class="h-4 w-4 rounded border-slate-300 accent-brand"></td>
    <td class="px-3 py-2 text-right"><button type="button" data-component-remove class="rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold text-rose-600 dark:border-white/10">{{ $tr('Retirer') }}</button></td>
</tr>
