{{-- « Produits utilisés » : what a service consumes, in the product's content unit. --}}
@php
    $tr = $tr ?? fn (string $text): string => \App\Support\Locale::t($text);
    $consumption = app(\App\Services\Catalogue\ServiceConsumption::class);
    $componentRows = app(\App\Services\Catalogue\ItemComponentService::class)->rows($item);
    $componentProducts = \App\Models\Item::where('tenant_id', $item->tenant_id)->where('type', '!=', 'service')
        ->where('status', '!=', 'archived')->orderBy('title')->limit(800)
        ->get(['id', 'title', 'content_quantity', 'content_unit', 'purchase_price', 'stock_quantity']);
    $componentVariants = $item->variants->where('is_active', true)->values();
    $estimate = $consumption->estimate($item);
    $basePrice = (float) $item->sale_price;
    $marginOf = fn (float $price, float $cost): string => $price > 0 ? number_format(($price - $cost) / $price * 100, 0, ',', ' ').' %' : '—';
    $fieldClass = 'h-9 w-full rounded-lg border border-slate-200 px-2 text-sm dark:border-white/10 dark:bg-slate-900';
    $oldRows = old('components');
    $initialRows = is_array($oldRows)
        ? collect($oldRows)->values()
        : $componentRows->map(fn ($r) => [
            'component_item_id' => $r->component_item_id,
            'variant_id' => $r->variant_id,
            'quantity' => \App\Support\Stock::plain($r->quantity, '.'),
            'choice_group' => $r->choice_group,
            'is_default' => $r->is_default,
        ])->values();
@endphp

<div class="mt-6 border-t border-slate-200 pt-5 dark:border-white/10" data-components-editor>
    <h3 class="text-sm font-semibold">{{ $tr('Produits utilisés') }}</h3>
    <p class="mt-1 max-w-3xl text-xs leading-5 text-slate-500">{{ $tr('Les produits consommés à chaque vente de ce service : leur stock baisse de la quantité indiquée et leur coût entre dans la marge. Les quantités proposées au départ sont des estimations : ajustez-les à votre pratique. Le matériel (chauffe-cire…) n’est jamais consommé : ne l’ajoutez pas.') }}</p>

    @if ($errors->has('components'))
        <div class="app-validation-summary mt-3"><ul class="list-disc space-y-1 pl-5">@foreach ($errors->get('components') as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <form action="{{ route('catalog.components.sync', $item) }}" method="POST" class="mt-4">
        @csrf
        @method('PUT')
        <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-white/10">
            <table class="w-full min-w-[56rem] text-left text-sm">
                <thead class="bg-slate-50 text-[11px] font-semibold uppercase text-slate-500 dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2">{{ $tr('Produit') }}</th>
                        <th class="px-3 py-2">{{ $tr('Quantité') }}</th>
                        @if ($componentVariants->isNotEmpty())<th class="px-3 py-2">{{ $tr('Pour') }}</th>@endif
                        <th class="px-3 py-2">{{ $tr('Groupe de choix') }}</th>
                        <th class="px-3 py-2">{{ $tr('Par défaut') }}</th>
                        <th class="px-3 py-2"><span class="sr-only">{{ $tr('Retirer') }}</span></th>
                    </tr>
                </thead>
                <tbody data-components-rows>
                    @foreach ($initialRows as $i => $row)
                        @include('librairepro.partials.service-component-row', ['i' => $i, 'row' => $row, 'products' => $componentProducts, 'variants' => $componentVariants, 'fieldClass' => $fieldClass, 'tr' => $tr])
                    @endforeach
                </tbody>
            </table>
        </div>

        <template data-components-template>
            @include('librairepro.partials.service-component-row', ['i' => '__INDEX__', 'row' => [], 'products' => $componentProducts, 'variants' => $componentVariants, 'fieldClass' => $fieldClass, 'tr' => $tr])
        </template>

        <p class="mt-2 text-xs text-slate-500">{{ $tr('Groupe de choix : les produits qui portent le même nom de groupe sont des alternatives (un seul est utilisé par vente, au choix à la caisse, sinon celui par défaut).') }}
            @if ($componentVariants->isNotEmpty()) {{ $tr('Une ligne « Pour » une déclinaison remplace celle du service pour le même produit ; une quantité à 0 retire le produit pour cette déclinaison.') }} @endif
        </p>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <button type="button" data-components-add class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-white/10">{{ $tr('Ajouter un produit') }}</button>
            <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">{{ $tr('Enregistrer les produits utilisés') }}</button>
        </div>
    </form>

    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 p-3 text-sm dark:border-white/10"><p class="text-xs text-slate-500">{{ $tr('Prix') }}</p><p class="mt-1 font-semibold">{{ $money($basePrice) }}</p></div>
        <div class="rounded-xl border border-slate-200 p-3 text-sm dark:border-white/10"><p class="text-xs text-slate-500">{{ $tr('Coût estimé des produits') }}</p><p class="mt-1 font-semibold">{{ $money($estimate['cost']) }}</p></div>
        <div class="rounded-xl border border-slate-200 p-3 text-sm dark:border-white/10"><p class="text-xs text-slate-500">{{ $tr('Marge sur les produits') }}</p><p class="mt-1 font-semibold">{{ $money($basePrice - $estimate['cost']) }} · {{ $marginOf($basePrice, (float) $estimate['cost']) }}</p></div>
    </div>
    @foreach ($componentVariants as $componentVariant)
        @if ($componentRows->where('variant_id', $componentVariant->id)->isNotEmpty())
            @php $variantEstimate = $consumption->estimate($item, $componentVariant); @endphp
            <p class="mt-2 text-xs text-slate-500"><strong>{{ $componentVariant->name }}</strong> : {{ $tr('Coût estimé des produits') }} {{ $money($variantEstimate['cost']) }} · {{ $tr('Marge sur les produits') }} {{ $marginOf((float) $componentVariant->price(), (float) $variantEstimate['cost']) }}</p>
        @endif
    @endforeach
</div>

<script>
    (() => {
        const root = document.querySelector('[data-components-editor]');
        if (!root) return;
        const body = root.querySelector('[data-components-rows]');
        const template = root.querySelector('[data-components-template]');
        let next = body.children.length;
        const refreshUnit = (row) => {
            const option = row.querySelector('select[data-component-product]').selectedOptions[0];
            row.querySelector('[data-component-unit]').textContent = option ? (option.dataset.unit || '') : '';
        };
        const wire = (row) => {
            row.querySelector('select[data-component-product]').addEventListener('change', () => refreshUnit(row));
            row.querySelector('[data-component-remove]').addEventListener('click', () => row.remove());
            refreshUnit(row);
        };
        body.querySelectorAll('tr').forEach(wire);
        root.querySelector('[data-components-add]').addEventListener('click', () => {
            const holder = document.createElement('tbody');
            holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(next++));
            const row = holder.firstElementChild;
            body.appendChild(row);
            wire(row);
        });
    })();
</script>
