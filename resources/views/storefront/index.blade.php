@php
    $money = fn ($value): string => number_format((float) $value, 2, ',', ' ').' DH';
    $theme = data_get($tenant->settings, 'theme', []);
    $primary = $theme['primary'] ?? '#3157D5';
    $accent = $theme['accent'] ?? '#0F9F8A';
    $logo = data_get($tenant->settings, 'company.logo_path');
    $cartKey = 'librairepro-store-cart-'.$tenant->id;
    $profileKey = 'librairepro-store-profile-'.$tenant->id;
    $categorySlugs = collect($categorySlugs ?? ($categorySlug ? [$categorySlug] : []))->filter()->values();
    $selectedTags = collect($selectedTags ?? [])->filter()->values();
    $activeCategoryNames = $categories->whereIn('slug', $categorySlugs->all())->pluck('name');
    $resultCount = method_exists($items, 'total') ? $items->total() : $items->count();
    $hasFilters = $search || $categorySlugs->isNotEmpty() || $selectedTags->isNotEmpty() || $minPrice !== null || $maxPrice !== null || ! $includeOutOfStock;
    $locale = app()->getLocale();
    $dir = \App\Support\Locale::dir($locale);
    $storefrontMessages = [
        'emptyCart' => __('storefront.empty_cart'),
        'cartSelected' => __('storefront.cart_selected', ['count' => '__COUNT__']),
        'noCode' => __('storefront.no_code'),
        'itemAdded' => __('storefront.item_added'),
        'itemRemoved' => __('storefront.item_removed'),
        'cartCleared' => __('storefront.cart_cleared'),
        'cartAlreadyEmpty' => __('storefront.cart_already_empty'),
        'clearCartConfirm' => __('storefront.clear_cart_confirm'),
        'stockLimit' => __('storefront.stock_limit'),
        'addOneItem' => __('storefront.add_one_item'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('storefront.online_store') }} · {{ $tenant->name }}</title>
    @vite(['resources/css/app.css'])
    <style>
        :root {
            --brand-primary: {{ $primary }};
            --brand-accent: {{ $accent }};
            --app-bg: #f6f8fb;
            --surface: #ffffff;
            --surface-muted: #eef3f8;
            --text-main: #101828;
            --text-muted: #64748b;
            --border-soft: #d7dee9;
            --font-scale: 1;
            --brand-radius: 14px;
        }
    </style>
</head>
<body class="min-h-screen bg-[#f6f8fb] text-slate-950 antialiased">
    <header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ route('storefront.index') }}" class="flex min-w-0 items-center gap-3 rounded-none">
                <div class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-2xl bg-brand text-sm font-black text-white shadow-sm">
                    @if ($logo)
                        <img src="{{ asset('storage/'.$logo) }}" alt="{{ $tenant->name }}" class="h-full w-full object-cover">
                    @else
                        {{ mb_substr($tenant->name, 0, 2) }}
                    @endif
                </div>
                <div class="min-w-0">
                    <p class="truncate text-xs font-bold uppercase tracking-wide text-brand">{{ __('storefront.online_store') }}</p>
                    <h1 class="truncate text-lg font-black sm:text-xl">{{ $tenant->name }}</h1>
                </div>
            </a>
            <div class="flex shrink-0 items-center gap-2">
                @if ($tenant->phone)
                    <a href="tel:{{ $tenant->phone }}" class="hidden rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm sm:inline-flex">{{ $tenant->phone }}</a>
                @endif
                <form action="{{ route('locale.switch', \App\Support\Locale::opposite($locale)) }}" method="POST">
                    @csrf
                    <button class="inline-flex h-10 items-center justify-center rounded-full border border-slate-200 bg-white px-3 text-sm font-black text-slate-700 shadow-sm transition hover:border-brand hover:text-brand" type="submit">
                        {{ $locale === 'ar' ? 'FR' : 'AR' }}
                    </button>
                </form>
                <button type="button" data-cart-jump class="inline-flex h-10 items-center gap-2 rounded-full bg-slate-950 px-4 text-sm font-black text-white shadow-sm">
                    <span>{{ __('storefront.cart') }}</span>
                    <span class="grid min-w-6 place-items-center rounded-full bg-white px-2 py-0.5 text-xs text-slate-950" data-cart-count>0</span>
                </button>
            </div>
        </div>
    </header>

    <main class="mx-auto grid max-w-7xl gap-5 px-3 pb-24 pt-4 sm:px-6 sm:py-6 lg:grid-cols-[minmax(0,1fr)_390px] lg:px-8 lg:pb-6">
        <section class="min-w-0 space-y-5">
            <div class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm">
                <div class="p-4 sm:p-5 lg:p-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div class="min-w-0">
                            <div class="inline-flex items-center gap-2 rounded-full bg-brand/10 px-3 py-1 text-xs font-black uppercase tracking-wide text-brand">
                                <span class="size-2 rounded-full bg-brand"></span>
                                {{ __('storefront.web_order') }}
                            </div>
                            <h2 class="mt-3 max-w-3xl text-2xl font-black leading-tight tracking-tight text-slate-950 sm:text-3xl lg:text-4xl">{{ __('storefront.hero_title') }}</h2>
                            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600 sm:text-base">{{ __('storefront.hero_text') }}</p>
                        </div>
                        <div class="hidden shrink-0 grid-cols-3 gap-2 rounded-3xl border border-slate-200 bg-slate-50 p-2 text-center sm:grid sm:min-w-[360px]">
                            <div class="rounded-2xl bg-white px-2 py-3 shadow-sm">
                                <p class="text-lg font-black text-brand">1</p>
                                <p class="text-[11px] font-bold text-slate-500">{{ __('storefront.choose') }}</p>
                            </div>
                            <div class="rounded-2xl bg-white px-2 py-3 shadow-sm">
                                <p class="text-lg font-black text-brand">2</p>
                                <p class="text-[11px] font-bold text-slate-500">{{ __('storefront.order') }}</p>
                            </div>
                            <div class="rounded-2xl bg-white px-2 py-3 shadow-sm">
                                <p class="text-lg font-black text-brand">3</p>
                                <p class="text-[11px] font-bold text-slate-500">{{ __('storefront.confirm') }}</p>
                            </div>
                        </div>
                    </div>

                    <form action="{{ route('storefront.index') }}" class="mt-5 rounded-3xl border border-slate-200 bg-slate-50 p-2 shadow-inner">
                        <div class="grid gap-2 lg:grid-cols-[minmax(0,1fr)_auto]">
                            <label class="sr-only" for="store-search">{{ __('storefront.search') }}</label>
                            <input id="store-search" name="q" value="{{ $search }}" class="h-12 min-w-0 rounded-2xl border border-slate-200 bg-white px-4 text-sm shadow-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15" placeholder="{{ __('storefront.search_placeholder') }}">
                            <button class="h-12 rounded-2xl bg-brand px-6 text-sm font-black text-white shadow-sm transition hover:opacity-95 active:scale-[0.98]">{{ __('storefront.search') }}</button>
                        </div>
                        <details open class="group mt-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 transition hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                                <span>
                                    <strong class="block text-sm font-black text-slate-800">{{ __('storefront.advanced_filters') }}</strong>
                                    <small class="mt-0.5 block font-semibold text-slate-500">{{ __('storefront.filters_hint') }}</small>
                                </span>
                                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-slate-100 text-lg font-black text-slate-500 transition group-open:rotate-180">⌄</span>
                            </summary>

                            <div class="border-t border-slate-200 p-3 sm:p-4">
                                <div class="grid gap-4 xl:grid-cols-[minmax(0,1.65fr)_minmax(250px,.75fr)]">
                                    <fieldset class="min-w-0">
                                        <legend class="text-xs font-black uppercase tracking-wide text-slate-500">{{ __('storefront.categories') }}</legend>
                                        <div class="mt-2 grid max-h-64 gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
                                            @foreach ($categories as $category)
                                                @php
                                                    $categoryParts = collect(explode(' / ', $category->name));
                                                    $categoryType = $categoryParts->count() > 1 ? trim((string) $categoryParts->pop()) : '';
                                                    $categoryName = trim($categoryParts->implode(' / '));
                                                    $categorySelected = $categorySlugs->contains($category->slug);
                                                @endphp
                                                <label class="group/category flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 transition {{ $categorySelected ? 'border-brand bg-brand/5 ring-1 ring-brand/20' : 'border-slate-200 bg-slate-50/70 hover:border-slate-300 hover:bg-white' }}">
                                                    <input name="categories[]" value="{{ $category->slug }}" type="checkbox" @checked($categorySelected) class="size-4 shrink-0 rounded border-slate-300 text-brand focus:ring-brand">
                                                    <span class="min-w-0">
                                                        <strong class="block text-sm leading-5 text-slate-800">{{ $categoryName }}</strong>
                                                        @if ($categoryType !== '')
                                                            <small class="block truncate text-[11px] font-bold uppercase tracking-wide text-slate-400">{{ $categoryType }}</small>
                                                        @endif
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>

                                    <div class="grid content-start gap-3">
                                        <fieldset>
                                            <legend class="text-xs font-black uppercase tracking-wide text-slate-500">{{ __('storefront.tags') }}</legend>
                                            @if (collect($availableTags)->isNotEmpty())
                                                <div class="mt-2 flex max-h-28 flex-wrap gap-2 overflow-y-auto">
                                                    @foreach ($availableTags as $tag)
                                                        <label class="cursor-pointer">
                                                            <input name="tags[]" value="{{ $tag }}" type="checkbox" @checked($selectedTags->contains($tag)) class="peer sr-only">
                                                            <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:border-slate-300 peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white">{{ $tag }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="mt-2 rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-3 text-xs font-semibold text-slate-400">{{ __('storefront.no_tags') }}</div>
                                            @endif
                                        </fieldset>

                                        <div class="grid grid-cols-2 gap-2">
                                            <label class="block">
                                                <span class="mb-1 block text-[11px] font-black uppercase tracking-wide text-slate-500">{{ __('storefront.min_price') }}</span>
                                                <div class="relative"><input name="min_price" type="number" step="0.01" min="0" value="{{ $minPrice !== null ? number_format($minPrice, 2, '.', '') : '' }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 pr-10 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15" placeholder="0"><span class="pointer-events-none absolute right-3 top-3 text-xs font-bold text-slate-400">DH</span></div>
                                            </label>
                                            <label class="block">
                                                <span class="mb-1 block text-[11px] font-black uppercase tracking-wide text-slate-500">{{ __('storefront.max_price') }}</span>
                                                <div class="relative"><input name="max_price" type="number" step="0.01" min="0" value="{{ $maxPrice !== null ? number_format($maxPrice, 2, '.', '') : '' }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 pr-10 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15" placeholder="999"><span class="pointer-events-none absolute right-3 top-3 text-xs font-bold text-slate-400">DH</span></div>
                                            </label>
                                        </div>

                                        <input type="hidden" name="include_out_of_stock" value="0">
                                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-700">
                                            <input name="include_out_of_stock" value="1" type="checkbox" @checked($includeOutOfStock) class="mt-1 rounded border-slate-300 text-brand focus:ring-brand">
                                            <span><span class="block">{{ __('storefront.include_out_of_stock') }}</span><small class="block font-semibold text-slate-500">{{ __('storefront.include_out_of_stock_hint') }}</small></span>
                                        </label>

                                        @if ($stores->count() > 1)
                                            <select name="pickup_store" data-pickup-store-select class="h-11 min-w-0 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15">
                                                @foreach ($stores as $store)<option value="{{ $store['key'] }}" @selected(($pickupStore['key'] ?? null) === $store['key'])>{{ __('storefront.pickup_store') }}: {{ $store['name'] }}</option>@endforeach
                                            </select>
                                        @else
                                            <input type="hidden" name="pickup_store" value="{{ $pickupStore['key'] ?? '' }}">
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end">
                                    @if ($hasFilters)<a href="{{ route('storefront.index') }}" class="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-600">{{ __('storefront.clear') }}</a>@endif
                                    <button class="h-11 rounded-xl bg-slate-950 px-5 text-sm font-black text-white shadow-sm transition hover:bg-brand">{{ __('storefront.apply_filters') }}</button>
                                </div>
                            </div>
                        </details>
                    </form>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-600">{{ __('storefront.store') }}: {{ $pickupStore['name'] ?? $tenant->name }}</span>
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-600">{{ __('storefront.stock_checked') }}</span>
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-600">{{ __('storefront.pickup_delivery') }}</span>
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-600">{{ __('storefront.pending_order') }}</span>
                    </div>
                </div>

                @if (session('storefront_status') || $successOrderNumber)
                    <div class="border-t border-emerald-100 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" data-order-success>
                        {{ session('storefront_status') ?? __('storefront.order_sent', ['number' => $successOrderNumber]) }}
                    </div>
                @endif
            </div>

            <div class="rounded-[24px] border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-black">{{ $activeCategoryNames->isNotEmpty() ? $activeCategoryNames->implode(' · ') : __('storefront.all_items') }}</h3>
                        <p class="text-sm text-slate-500">{{ number_format($resultCount, 0, ',', ' ') }} {{ __('storefront.results') }}{{ $search ? ' '.__('storefront.for_search', ['search' => $search]) : '' }}</p>
                    </div>
                    @if ($hasFilters)
                        <a href="{{ route('storefront.index') }}" class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-600">{{ __('storefront.clear') }}</a>
                    @endif
                </div>
                <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                    <a href="{{ route('storefront.index', ['q' => $search ?: null, 'pickup_store' => $pickupStore['key'] ?? null, 'include_out_of_stock' => $includeOutOfStock ? 1 : 0, 'min_price' => $minPrice, 'max_price' => $maxPrice, 'tags' => $selectedTags->all()]) }}" class="shrink-0 rounded-full px-4 py-2 text-sm font-bold {{ $categorySlugs->isEmpty() ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">{{ __('storefront.all') }}</a>
                    @foreach ($categories as $category)
                        <a href="{{ route('storefront.index', ['categories' => [$category->slug], 'q' => $search ?: null, 'pickup_store' => $pickupStore['key'] ?? null, 'include_out_of_stock' => $includeOutOfStock ? 1 : 0, 'min_price' => $minPrice, 'max_price' => $maxPrice, 'tags' => $selectedTags->all()]) }}" class="shrink-0 rounded-full px-4 py-2 text-sm font-bold {{ $categorySlugs->contains($category->slug) ? 'bg-brand text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">{{ $category->name }}</a>
                    @endforeach
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($items as $item)
                    @php
                        $image = collect($item->images)->first();
                        $available = $item->type === 'service' ? 999999 : max(0, (int) ($item->online_available_stock ?? 0));
                        $canOrder = $item->type === 'service' || $available > 0;
                        $code = $item->barcode ?: ($item->isbn ?: ($item->sku ?: $item->item_code));
                        $itemTags = collect($item->tags ?? [])->filter()->take(3);
                    @endphp
                    <article class="group flex min-h-[330px] flex-col overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md" data-detail-target="store-item-detail-{{ $item->id }}">
                        <div class="relative aspect-[4/3] overflow-hidden bg-slate-100">
                            @if ($image)
                                <img src="{{ asset('storage/'.$image) }}" alt="{{ $item->title }}" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
                            @else
                                <div class="grid h-full place-items-center bg-gradient-to-br from-slate-100 to-slate-200 text-4xl font-black text-slate-300">{{ mb_substr($item->title, 0, 2) }}</div>
                            @endif
                            <div class="absolute inset-x-3 top-3 flex items-start justify-between gap-2">
                                <span class="min-w-0 truncate rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-slate-700 shadow-sm">{{ $item->category?->name ?? __('storefront.uncategorized') }}</span>
                                <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold shadow-sm {{ $canOrder ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                    {{ $item->type === 'service' ? __('storefront.service') : ($canOrder ? __('storefront.available', ['count' => $available]) : __('storefront.out_of_stock')) }}
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col p-4">
                            <h3 class="line-clamp-2 text-base font-black leading-6">{{ $item->title }}</h3>
                            <p class="mt-1 truncate text-xs font-semibold uppercase text-slate-400">{{ $code ?: __('storefront.no_code') }}{{ $item->brand?->name ? ' · '.$item->brand->name : '' }}</p>
                            @if ($item->description)
                                <p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-500">{{ $item->description }}</p>
                            @else
                                <p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-400">{{ __('storefront.available_on_request') }}</p>
                            @endif
                            @if ($itemTags->isNotEmpty())
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    @foreach ($itemTags as $tag)
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-500">#{{ $tag }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <div class="mt-auto space-y-3 pt-4">
                                <div class="rounded-2xl bg-slate-50 px-3 py-2">
                                    <span class="text-xs font-black uppercase text-slate-400">{{ __('storefront.price') }}</span>
                                    <p class="break-words text-2xl font-black leading-tight tracking-tight text-slate-950">{{ $money($item->sale_price) }}</p>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <button type="button" onclick="document.getElementById('store-item-detail-{{ $item->id }}').showModal()" class="inline-flex h-11 items-center justify-center rounded-2xl border border-slate-200 px-3 text-sm font-black text-slate-700 transition hover:border-brand hover:text-brand">
                                        {{ __('storefront.details') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="store-add inline-flex h-11 items-center justify-center rounded-2xl px-4 text-sm font-black transition {{ $canOrder ? 'bg-brand text-white shadow-sm hover:opacity-95 active:scale-[0.98]' : 'cursor-not-allowed bg-slate-100 text-slate-400' }}"
                                        @disabled(! $canOrder)
                                        data-id="{{ $item->id }}"
                                        data-title="{{ e($item->title) }}"
                                        data-code="{{ e($code) }}"
                                        data-price="{{ (float) $item->sale_price }}"
                                        data-stock="{{ $available }}"
                                        data-service="{{ $item->type === 'service' ? '1' : '0' }}"
                                    >
                                        {{ $canOrder ? __('storefront.add') : __('storefront.unavailable') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </article>
                    <dialog id="store-item-detail-{{ $item->id }}" class="w-[min(720px,calc(100vw-1rem))] rounded-[28px] border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45">
                        <div class="grid max-h-[min(760px,calc(100vh-2rem))] overflow-hidden md:grid-cols-[260px_1fr]">
                            <div class="min-h-48 bg-slate-100">
                                @if ($image)
                                    <img src="{{ asset('storage/'.$image) }}" alt="{{ $item->title }}" class="h-full w-full object-cover">
                                @else
                                    <div class="grid h-full min-h-48 place-items-center bg-gradient-to-br from-slate-100 to-slate-200 text-5xl font-black text-slate-300">{{ mb_substr($item->title, 0, 2) }}</div>
                                @endif
                            </div>
                            <div class="flex min-h-0 flex-col">
                                <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5">
                                    <div class="min-w-0">
                                        <p class="text-xs font-black uppercase tracking-wide text-brand">{{ __('storefront.item_detail') }}</p>
                                        <h3 class="mt-1 text-2xl font-black leading-tight">{{ $item->title }}</h3>
                                        <p class="mt-1 text-sm font-semibold text-slate-500">{{ $code ?: __('storefront.no_code') }}</p>
                                    </div>
                                    <button class="dialog-close grid size-10 shrink-0 place-items-center rounded-2xl border border-slate-200 text-xl font-black text-slate-500" type="button">×</button>
                                </div>
                                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                                    <div class="grid grid-cols-2 gap-3 text-sm">
                                        <div class="rounded-2xl bg-slate-50 p-3 col-span-2"><span class="block text-xs font-bold uppercase text-slate-400">{{ __('storefront.price') }}</span><strong class="block break-words text-2xl leading-tight">{{ $money($item->sale_price) }}</strong></div>
                                        <div class="rounded-2xl bg-slate-50 p-3"><span class="block text-xs font-bold uppercase text-slate-400">{{ __('storefront.stock') }}</span><strong class="text-lg">{{ $item->type === 'service' ? __('storefront.service') : $available }}</strong></div>
                                        <div class="rounded-2xl bg-slate-50 p-3"><span class="block text-xs font-bold uppercase text-slate-400">{{ __('storefront.category') }}</span><strong>{{ $item->category?->name ?? __('storefront.uncategorized') }}</strong></div>
                                        <div class="rounded-2xl bg-slate-50 p-3 col-span-2"><span class="block text-xs font-bold uppercase text-slate-400">{{ __('storefront.brand') }}</span><strong>{{ $item->brand?->name ?? '—' }}</strong></div>
                                        @if ($itemTags->isNotEmpty())
                                            <div class="rounded-2xl bg-slate-50 p-3 col-span-2"><span class="block text-xs font-bold uppercase text-slate-400">{{ __('storefront.tags') }}</span><strong>{{ $itemTags->implode(' · ') }}</strong></div>
                                        @endif
                                    </div>
                                    <div>
                                        <h4 class="text-xs font-black uppercase tracking-wide text-slate-400">{{ __('storefront.description') }}</h4>
                                        <p class="mt-2 text-sm leading-7 text-slate-600">{{ $item->description ?: __('storefront.no_description') }}</p>
                                    </div>
                                </div>
                                <div class="space-y-3 border-t border-slate-200 bg-slate-50 p-5">
                                    <div class="grid gap-3 sm:grid-cols-[1fr_150px] sm:items-end">
                                        <div>
                                            <span class="block text-xs font-black uppercase text-slate-400">{{ __('storefront.price') }}</span>
                                            <strong class="block break-words text-2xl leading-tight">{{ $money($item->sale_price) }}</strong>
                                        </div>
                                        <label class="block">
                                            <span class="block text-xs font-black uppercase text-slate-400">{{ __('storefront.quantity') }}</span>
                                            <input type="number" min="1" max="{{ $item->type === 'service' ? 999 : max(1, $available) }}" value="1" data-detail-quantity class="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-white px-3 text-center text-sm font-black">
                                        </label>
                                    </div>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <button class="dialog-close h-12 rounded-2xl border border-slate-200 bg-white px-5 text-sm font-black text-slate-700" type="button">{{ __('storefront.close') }}</button>
                                        <button
                                            type="button"
                                            class="store-add dialog-close inline-flex h-12 items-center justify-center rounded-2xl px-5 text-sm font-black transition {{ $canOrder ? 'bg-brand text-white shadow-sm hover:opacity-95 active:scale-[0.98]' : 'cursor-not-allowed bg-slate-100 text-slate-400' }}"
                                            @disabled(! $canOrder)
                                            data-use-detail-quantity="1"
                                            data-id="{{ $item->id }}"
                                            data-title="{{ e($item->title) }}"
                                            data-code="{{ e($code) }}"
                                            data-price="{{ (float) $item->sale_price }}"
                                            data-stock="{{ $available }}"
                                            data-service="{{ $item->type === 'service' ? '1' : '0' }}"
                                        >
                                            {{ $canOrder ? __('storefront.add_quantity') : __('storefront.unavailable') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </dialog>
                @empty
                    <div class="rounded-[28px] border border-dashed border-slate-300 bg-white p-10 text-center sm:col-span-2 xl:col-span-3">
                        <div class="mx-auto grid size-14 place-items-center rounded-2xl bg-slate-100 text-xl font-black text-slate-400">0</div>
                        <h3 class="mt-4 text-xl font-black">{{ __('storefront.no_items') }}</h3>
                        <p class="mt-2 text-sm text-slate-500">{{ __('storefront.try_other_search') }}</p>
                    </div>
                @endforelse
            </div>

            @if ($items->hasPages())
                @php
                    $currentPage = $items->currentPage();
                    $lastPage = $items->lastPage();
                    $pageStart = max(1, $currentPage - 2);
                    $pageEnd = min($lastPage, $currentPage + 2);
                @endphp
                <nav class="rounded-[24px] border border-slate-200 bg-white p-3 shadow-sm" aria-label="{{ __('storefront.pagination_label') }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm font-semibold text-slate-500">
                            {{ __('storefront.range', ['from' => number_format($items->firstItem(), 0, ',', ' '), 'to' => number_format($items->lastItem(), 0, ',', ' '), 'total' => number_format($items->total(), 0, ',', ' ')]) }}
                        </p>
                        <div class="flex items-center justify-between gap-2 sm:justify-end">
                            @if ($items->onFirstPage())
                                <span class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-bold text-slate-300">{{ __('storefront.previous') }}</span>
                            @else
                                <a href="{{ $items->previousPageUrl() }}" class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold text-slate-700 transition hover:border-brand hover:text-brand">{{ __('storefront.previous') }}</a>
                            @endif

                            <div class="hidden items-center gap-1 md:flex">
                                @if ($pageStart > 1)
                                    <a href="{{ $items->url(1) }}" class="grid size-10 place-items-center rounded-xl text-sm font-bold text-slate-600 transition hover:bg-slate-100">1</a>
                                    @if ($pageStart > 2)
                                        <span class="grid size-10 place-items-center text-sm font-bold text-slate-400">...</span>
                                    @endif
                                @endif

                                @for ($page = $pageStart; $page <= $pageEnd; $page++)
                                    @if ($page === $currentPage)
                                        <span class="grid size-10 place-items-center rounded-xl bg-slate-950 text-sm font-black text-white">{{ $page }}</span>
                                    @else
                                        <a href="{{ $items->url($page) }}" class="grid size-10 place-items-center rounded-xl text-sm font-bold text-slate-600 transition hover:bg-slate-100">{{ $page }}</a>
                                    @endif
                                @endfor

                                @if ($pageEnd < $lastPage)
                                    @if ($pageEnd < $lastPage - 1)
                                        <span class="grid size-10 place-items-center text-sm font-bold text-slate-400">...</span>
                                    @endif
                                    <a href="{{ $items->url($lastPage) }}" class="grid size-10 place-items-center rounded-xl text-sm font-bold text-slate-600 transition hover:bg-slate-100">{{ $lastPage }}</a>
                                @endif
                            </div>

                            <span class="rounded-xl bg-slate-100 px-3 py-2 text-sm font-black text-slate-700 md:hidden">{{ $currentPage }} / {{ $lastPage }}</span>

                            @if ($items->hasMorePages())
                                <a href="{{ $items->nextPageUrl() }}" class="inline-flex h-10 items-center justify-center rounded-xl bg-brand px-3 text-sm font-black text-white shadow-sm transition hover:opacity-95">{{ __('storefront.next') }}</a>
                            @else
                                <span class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-bold text-slate-300">{{ __('storefront.next') }}</span>
                            @endif
                        </div>
                    </div>
                </nav>
            @endif
        </section>

        <aside class="lg:sticky lg:top-[88px] lg:max-h-[calc(100vh-104px)] lg:self-start lg:overflow-y-auto lg:overscroll-contain lg:pr-1" id="store-cart">
            <form action="{{ route('storefront.orders.store') }}" method="POST" class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm" data-store-order>
                @csrf
                <div class="border-b border-slate-200 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-brand">{{ __('storefront.order') }}</p>
                            <h2 class="mt-1 text-2xl font-black">{{ __('storefront.cart') }}</h2>
                            <p class="mt-1 text-sm text-slate-500" data-cart-selected-label>{{ __('storefront.cart_selected', ['count' => 0]) }}</p>
                        </div>
                        <button type="button" data-cart-clear class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-500 hover:bg-slate-50">{{ __('storefront.clear') }}</button>
                    </div>
                </div>

                <div class="max-h-[360px] space-y-3 overflow-auto p-5" data-cart-lines>
                    <div class="rounded-2xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">{{ __('storefront.empty_cart') }}</div>
                </div>

                <div class="border-y border-slate-200 bg-slate-50 p-5">
                    <div class="flex items-center justify-between text-sm text-slate-500">
                        <span>{{ __('storefront.subtotal') }}</span>
                        <strong class="text-2xl text-slate-950" data-cart-total>0,00 DH</strong>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-slate-500">{{ __('storefront.stock_not_reserved') }}</p>
                </div>

                <div class="space-y-3 p-5">
                    @if ($stores->count() > 1)
                        <label class="space-y-1.5">
                            <span class="text-xs font-black uppercase text-slate-500">{{ __('storefront.pickup_store') }}</span>
                            <select name="pickup_store" data-pickup-store-select class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                                @foreach ($stores as $store)
                                    <option value="{{ $store['key'] }}" @selected(($pickupStore['key'] ?? null) === $store['key'])>{{ $store['name'] }}</option>
                                @endforeach
                            </select>
                            <span class="block text-xs text-slate-500">{{ __('storefront.pickup_store_hint') }}</span>
                        </label>
                    @else
                        <input type="hidden" name="pickup_store" value="{{ $pickupStore['key'] ?? '' }}">
                    @endif
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                        <input name="customer_name" data-profile-field="customer_name" required class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm" placeholder="{{ __('storefront.full_name') }}">
                        <input name="customer_phone" data-profile-field="customer_phone" required type="tel" inputmode="tel" autocomplete="tel" dir="ltr" pattern="^\+?[0-9\s().-]{8,24}$" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm" placeholder="+212 6 00 00 00 00">
                    </div>
                    <p class="-mt-1 text-xs leading-5 text-slate-500">{{ __('storefront.phone_help') }}</p>
                    <input name="customer_email" data-profile-field="customer_email" type="email" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm" placeholder="{{ __('storefront.email') }}">
                    <textarea name="delivery_address" data-profile-field="delivery_address" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="{{ __('storefront.address') }}"></textarea>
                    <textarea name="customer_note" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="{{ __('storefront.note') }}"></textarea>
                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-700">
                        <input type="checkbox" data-save-profile class="mt-0.5 size-4 accent-[var(--brand-primary)]">
                        <span>{{ __('storefront.remember_info') }}</span>
                    </label>
                    <div data-cart-inputs></div>
                    @error('items')
                        <p class="rounded-xl bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ $message }}</p>
                    @enderror
                    @if ($errors->any() && ! $errors->has('items'))
                        <p class="rounded-xl bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ $errors->first() }}</p>
                    @endif
                    <button class="h-12 w-full rounded-2xl bg-slate-950 text-sm font-black text-white transition disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500" data-cart-submit disabled>{{ __('storefront.send_order') }}</button>
                </div>
            </form>
        </aside>
    </main>

    <button type="button" data-mobile-cart-jump class="fixed bottom-3 left-3 right-3 z-40 flex items-center justify-between rounded-2xl bg-slate-950 px-4 py-3 text-sm font-black text-white shadow-2xl shadow-slate-900/25 lg:hidden">
        <span class="inline-flex items-center gap-2">{{ __('storefront.cart') }} <span class="grid min-w-6 place-items-center rounded-full bg-white px-2 py-0.5 text-xs text-slate-950" data-cart-count>0</span></span>
        <span data-mobile-cart-total>0,00 DH</span>
    </button>

    <div class="fixed bottom-20 left-4 right-4 z-50 hidden rounded-2xl bg-slate-950 px-4 py-3 text-sm font-bold text-white shadow-xl sm:left-auto sm:w-[360px] lg:bottom-4" data-store-toast></div>

    <script>
        (() => {
            const key = @json($cartKey);
            const profileKey = @json($profileKey);
            const i18n = @json($storefrontMessages);
            const money = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'MAD' });
            const linesNode = document.querySelector('[data-cart-lines]');
            const inputsNode = document.querySelector('[data-cart-inputs]');
            const totalNode = document.querySelector('[data-cart-total]');
            const mobileTotalNode = document.querySelector('[data-mobile-cart-total]');
            const countNodes = document.querySelectorAll('[data-cart-count]');
            const selectedLabelNode = document.querySelector('[data-cart-selected-label]');
            const submitButton = document.querySelector('[data-cart-submit]');
            const toastNode = document.querySelector('[data-store-toast]');
            const form = document.querySelector('[data-store-order]');
            const saveProfileInput = document.querySelector('[data-save-profile]');
            let toastTimer;
            let cart = JSON.parse(localStorage.getItem(key) || '[]');
            const storedProfile = JSON.parse(localStorage.getItem(profileKey) || '{}');

            if (document.querySelector('[data-order-success]')) {
                cart = [];
                localStorage.removeItem(key);
            }

            const escapeHtml = (value) => String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');

            const formatMoney = (value) => money.format(value).replace('MAD', 'DH');

            const normalizePhone = (value) => {
                const digits = String(value || '').replace(/\D+/g, '');
                if (digits.startsWith('00212')) return `+${digits.slice(2)}`;
                if (digits.startsWith('212')) return `+${digits}`;
                if (digits.startsWith('0') && digits.length === 10) return `+212${digits.slice(1)}`;
                if (digits.length === 9 && ['5', '6', '7'].includes(digits[0])) return `+212${digits}`;
                return value;
            };

            const toast = (message) => {
                if (!toastNode) return;
                window.clearTimeout(toastTimer);
                toastNode.textContent = message;
                toastNode.classList.remove('hidden');
                toastTimer = window.setTimeout(() => toastNode.classList.add('hidden'), 2400);
            };

            const save = () => localStorage.setItem(key, JSON.stringify(cart));

            const render = () => {
                const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
                const count = cart.reduce((sum, item) => sum + item.quantity, 0);
                totalNode.textContent = formatMoney(total);
                if (mobileTotalNode) {
                    mobileTotalNode.textContent = formatMoney(total);
                }
                countNodes.forEach((node) => node.textContent = count);
                if (selectedLabelNode) {
                    selectedLabelNode.textContent = i18n.cartSelected.replace('__COUNT__', count);
                }
                submitButton.disabled = cart.length === 0;
                inputsNode.innerHTML = cart.map((item, index) => `
                    <input type="hidden" name="items[${index}][item_id]" value="${item.id}">
                    <input type="hidden" name="items[${index}][quantity]" value="${item.quantity}">
                `).join('');

                if (!cart.length) {
                    linesNode.innerHTML = `<div class="rounded-2xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">${escapeHtml(i18n.emptyCart)}</div>`;
                    return;
                }

                linesNode.innerHTML = cart.map((item) => `
                    <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="line-clamp-2 text-sm font-black">${escapeHtml(item.title)}</p>
                                <p class="mt-1 text-xs text-slate-500">${escapeHtml(item.code || i18n.noCode)} · ${formatMoney(item.price)}</p>
                            </div>
                            <button type="button" class="grid size-8 shrink-0 place-items-center rounded-lg bg-rose-50 text-sm font-black text-rose-600" data-remove="${item.id}">×</button>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-3">
                            <div class="inline-flex overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                                <button type="button" class="size-9 font-black" data-qty="${item.id}" data-delta="-1">-</button>
                                <span class="grid h-9 min-w-10 place-items-center border-x border-slate-200 bg-white text-sm font-bold">${item.quantity}</span>
                                <button type="button" class="size-9 font-black" data-qty="${item.id}" data-delta="1">+</button>
                            </div>
                            <strong>${formatMoney(item.price * item.quantity)}</strong>
                        </div>
                    </div>
                `).join('');
            };

            const addItemFromButton = (button) => {
                const quantityInput = button.dataset.useDetailQuantity === '1'
                    ? button.closest('dialog')?.querySelector('[data-detail-quantity]')
                    : null;
                const requestedQuantity = Math.max(1, Number(quantityInput?.value || 1));
                const item = {
                    id: Number(button.dataset.id),
                    title: button.dataset.title,
                    code: button.dataset.code,
                    price: Number(button.dataset.price || 0),
                    stock: Number(button.dataset.stock || 0),
                    service: button.dataset.service === '1',
                    quantity: requestedQuantity,
                };
                const existing = cart.find((line) => line.id === item.id);
                if (existing) {
                    const nextQuantity = existing.quantity + requestedQuantity;
                    if (!existing.service && nextQuantity > existing.stock) {
                        existing.quantity = existing.stock;
                        toast(i18n.stockLimit);
                    } else {
                        existing.quantity = nextQuantity;
                        toast(i18n.itemAdded);
                    }
                } else {
                    if (!item.service && item.quantity > item.stock) {
                        item.quantity = item.stock;
                        toast(i18n.stockLimit);
                    } else {
                        toast(i18n.itemAdded);
                    }
                    if (item.service || item.quantity > 0) {
                        cart.push(item);
                    }
                }
                save();
                render();
            };

            document.querySelectorAll('.store-add').forEach((button) => {
                button.addEventListener('click', () => {
                    addItemFromButton(button);
                });
            });

            document.querySelectorAll('[data-detail-target]').forEach((card) => {
                card.addEventListener('dblclick', (event) => {
                    if (event.target.closest('button, a, input, select, textarea')) {
                        return;
                    }
                    document.getElementById(card.dataset.detailTarget)?.showModal();
                });
            });

            linesNode.addEventListener('click', (event) => {
                const remove = event.target.closest('[data-remove]');
                const qty = event.target.closest('[data-qty]');
                if (remove) {
                    cart = cart.filter((item) => item.id !== Number(remove.dataset.remove));
                    toast(i18n.itemRemoved);
                }
                if (qty) {
                    const item = cart.find((line) => line.id === Number(qty.dataset.qty));
                    const next = (item?.quantity || 0) + Number(qty.dataset.delta);
                    if (item && next <= 0) {
                        cart = cart.filter((line) => line.id !== item.id);
                    } else if (item && (item.service || next <= item.stock)) {
                        item.quantity = next;
                    } else if (item) {
                        toast(i18n.stockLimit);
                    }
                }
                save();
                render();
            });

            document.querySelector('[data-cart-clear]')?.addEventListener('click', () => {
                if (!cart.length) {
                    toast(i18n.cartAlreadyEmpty);
                    return;
                }
                if (!window.confirm(i18n.clearCartConfirm)) {
                    return;
                }
                cart = [];
                save();
                render();
                toast(i18n.cartCleared);
            });

            document.querySelector('[data-cart-jump]')?.addEventListener('click', () => {
                document.getElementById('store-cart')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });

            document.querySelector('[data-mobile-cart-jump]')?.addEventListener('click', () => {
                document.getElementById('store-cart')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });

            document.querySelectorAll('[data-pickup-store-select]').forEach((select) => {
                select.addEventListener('change', () => {
                    const url = new URL(window.location.href);
                    url.searchParams.set('pickup_store', select.value);
                    url.searchParams.delete('page');
                    window.location.href = url.toString();
                });
            });

            form?.addEventListener('submit', (event) => {
                if (cart.length) return;
                event.preventDefault();
                toast(i18n.addOneItem);
                document.getElementById('store-cart')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });

            document.querySelectorAll('[data-profile-field]').forEach((field) => {
                if (storedProfile[field.name]) {
                    field.value = storedProfile[field.name];
                }
            });
            if (Object.keys(storedProfile).length && saveProfileInput) {
                saveProfileInput.checked = true;
            }

            const phoneInput = document.querySelector('input[name="customer_phone"]');
            const normalizePhoneInput = () => {
                if (phoneInput) {
                    phoneInput.value = normalizePhone(phoneInput.value);
                }
            };
            phoneInput?.addEventListener('blur', normalizePhoneInput);
            phoneInput?.addEventListener('change', normalizePhoneInput);
            phoneInput?.addEventListener('focusout', normalizePhoneInput);
            phoneInput?.addEventListener('input', () => {
                window.clearTimeout(phoneInput._normalizeTimer);
                phoneInput._normalizeTimer = window.setTimeout(() => {
                    if (String(phoneInput.value || '').replace(/\D+/g, '').length >= 9) {
                        normalizePhoneInput();
                    }
                }, 400);
            });

            form?.addEventListener('submit', () => {
                normalizePhoneInput();
                if (saveProfileInput?.checked) {
                    const profile = {};
                    form.querySelectorAll('[data-profile-field]').forEach((field) => {
                        profile[field.name] = field.value;
                    });
                    localStorage.setItem(profileKey, JSON.stringify(profile));
                } else {
                    localStorage.removeItem(profileKey);
                }
            });

            document.querySelectorAll('.dialog-close').forEach((button) => {
                button.addEventListener('click', () => button.closest('dialog')?.close());
            });

            render();
        })();
    </script>
</body>
</html>
