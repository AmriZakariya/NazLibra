@php
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH';
    $labelClass = [
        'small' => 'label-small',
        'medium' => 'label-medium',
        'large' => 'label-large',
    ][$template] ?? 'label-medium';
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · Étiquettes">
    <section class="no-print flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-sm font-medium text-brand">Étiquettes & code-barres</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-normal">Planche imprimable</h1>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Choisissez un format, imprimez depuis le navigateur ou enregistrez en PDF.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach (['small' => 'Petit', 'medium' => 'Moyen', 'large' => 'Grand'] as $key => $label)
                <a href="{{ route('catalog.labels', array_merge(request()->query(), ['template' => $key])) }}" class="rounded-lg px-4 py-2 text-sm font-semibold {{ $template === $key ? 'bg-brand text-white' : 'border border-slate-200 bg-white dark:border-white/10 dark:bg-white/5' }}">{{ $label }}</a>
            @endforeach
            <button onclick="window.print()" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-slate-950">Imprimer</button>
            <a href="{{ route('catalog') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Retour</a>
        </div>
    </section>

    <section class="mt-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03] print-area">
        <div class="label-sheet {{ $labelClass }}">
            @foreach ($items as $item)
                @for ($copy = 0; $copy < min(6, max(1, (int) request('copies', 1))); $copy++)
                    <article class="label-card">
                        <div class="label-title">{{ $item->title }}</div>
                        @if ($template !== 'small')
                            <div class="label-meta">{{ $item->category?->name ?? 'Catalogue' }} · {{ $item->location ?? 'Stock' }}</div>
                        @endif
                        <div class="label-barcode">{{ $item->barcode ?? $item->isbn ?? 'LP-'.$item->id }}</div>
                        <div class="label-code">{{ $item->barcode ?? $item->isbn ?? 'LP-'.$item->id }}</div>
                        @if ($template === 'large')
                            <div class="label-price">{{ $money($item->sale_price) }}</div>
                        @endif
                    </article>
                @endfor
            @endforeach
        </div>
    </section>
</x-layouts.app>
