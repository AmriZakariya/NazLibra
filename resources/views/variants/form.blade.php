@php
    $tr = $tr ?? fn (string $text): string => \App\Support\Locale::t($text, $locale ?? null);
    $isEdit = isset($variant);
    $heading = $isEdit ? $tr('Modifier la variante') : $tr('Nouvelle variante');
    $subtitle = $isEdit ? $variant->name : $tr('Créez une variante d\'article avec ses propres identifiants, prix et stock.');
    $formAction = $isEdit ? route('variants.update', $variant) : route('variants.store');
    $formMethod = $isEdit ? 'PUT' : 'POST';
    $formatOptions = ['paperback' => 'Livre broché', 'hardcover' => 'Relié', 'ebook' => 'E-book', 'audiobook' => 'Livre audio'];
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · {{ $heading }}">
    <header class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <h1 class="text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white">{{ $heading }}</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('variants.index') }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-transparent dark:text-slate-200">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                    {{ $tr('Annuler') }}
                </a>
            </div>
        </div>
    </header>

    @if ($errors->any())
        <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm dark:border-rose-500/30 dark:bg-rose-500/10">
            <div class="flex items-center gap-2 text-rose-700 dark:text-rose-300">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                <span class="font-semibold">{{ $tr('Veuillez corriger les erreurs suivantes') }}</span>
            </div>
            <ul class="mt-2 ml-7 list-disc text-rose-600 dark:text-rose-400">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ $formAction }}" method="POST" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                {{-- Informations de base --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Informations de base') }}</h2>
                    <div class="mt-5 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="space-y-2">
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Article parent') }} <span class="text-rose-500">*</span></span>
                                <select name="item_id" required class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                                    <option value="">{{ $tr('Sélectionner un article') }}</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}" @selected(old('item_id', $variant?->item_id) == $product->id)>{{ $product->title }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="space-y-2">
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Nom de la variante') }} <span class="text-rose-500">*</span></span>
                                <input name="name" value="{{ old('name', $variant?->name) }}" required maxlength="255" placeholder="{{ $tr('Ex: Poche, Grand format, Arabe...') }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                            </label>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="space-y-2">
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Format') }}</span>
                                <select name="format" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                                    <option value="">{{ $tr('Sélectionner') }}</option>
                                    @foreach ($formatOptions as $key => $label)
                                        <option value="{{ $key }}" @selected(old('format', $variant?->format) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="space-y-2">
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Langue') }}</span>
                                <input name="language" value="{{ old('language', $variant?->language) }}" maxlength="50" placeholder="{{ $tr('Ex: Français, Arabe, Anglais') }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                            </label>
                            <label class="space-y-2">
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Édition') }}</span>
                                <input name="edition" value="{{ old('edition', $variant?->edition) }}" maxlength="50" placeholder="{{ $tr('Ex: 1ère, 2ème, Collector') }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                            </label>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="space-y-2">
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Éditeur') }}</span>
                                <input name="publisher" value="{{ old('publisher', $variant?->publisher) }}" maxlength="100" placeholder="{{ $tr('Ex: Hachette, Dar Al-Maarif') }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                            </label>
                            <label class="space-y-2">
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Auteur') }}</span>
                                <input name="author" value="{{ old('author', $variant?->author) }}" maxlength="100" placeholder="{{ $tr('Ex: Victor Hugo, Naguib Mahfouz') }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                            </label>
                        </div>
                        <label class="space-y-2">
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Description') }}</span>
                            <textarea name="description" rows="3" placeholder="{{ $tr('Description optionnelle de la variante...') }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">{{ old('description', $variant?->description) }}</textarea>
                        </label>
                    </div>
                </div>

                {{-- Identifiants --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Identifiants') }}</h2>
                    <div class="mt-5 grid gap-4 sm:grid-cols-3">
                        <label class="space-y-2">
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Code-barres') }}</span>
                            <input name="barcode" value="{{ old('barcode', $variant?->barcode) }}" maxlength="50" placeholder="9781234567890" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                            @if ($errors->has('barcode'))
                                <p class="text-xs text-rose-500">{{ $errors->first('barcode') }}</p>
                            @endif
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('SKU') }}</span>
                            <input name="sku" value="{{ old('sku', $variant?->sku) }}" maxlength="50" placeholder="{{ $tr('Référence interne') }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                            @if ($errors->has('sku'))
                                <p class="text-xs text-rose-500">{{ $errors->first('sku') }}</p>
                            @endif
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('ISBN') }}</span>
                            <input name="isbn" value="{{ old('isbn', $variant?->isbn) }}" maxlength="20" placeholder="978-2-1234-5678-9" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                            @if ($errors->has('isbn'))
                                <p class="text-xs text-rose-500">{{ $errors->first('isbn') }}</p>
                            @endif
                        </label>
                    </div>
                    <p class="mt-3 text-xs text-slate-400">{{ $tr('Le code-barres, SKU et ISBN doivent être uniques dans votre catalogue.') }}</p>
                </div>

                {{-- Prix et stock --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Prix et stock') }}</h2>
                    <div class="mt-5 grid gap-4 sm:grid-cols-3">
                        <label class="space-y-2">
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Prix d\'achat') }} <span class="text-rose-500">*</span></span>
                            <div class="relative">
                                <input name="purchase_price" value="{{ old('purchase_price', $variant?->purchase_price) }}" required min="0" step="0.01" type="number" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 pr-12 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                                <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-400">DH</span>
                            </div>
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Prix de vente') }} <span class="text-rose-500">*</span></span>
                            <div class="relative">
                                <input name="sale_price" value="{{ old('sale_price', $variant?->sale_price) }}" required min="0" step="0.01" type="number" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 pr-12 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                                <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-400">DH</span>
                            </div>
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Seuil d\'alerte') }} <span class="text-rose-500">*</span></span>
                            <input name="min_stock_threshold" value="{{ old('min_stock_threshold', $variant?->min_stock_threshold ?? 5) }}" required min="0" step="1" type="number" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        </label>
                    </div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <label class="space-y-2">
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Quantité en stock') }} <span class="text-rose-500">*</span></span>
                            <input name="stock_quantity" value="{{ old('stock_quantity', $variant?->stock_quantity ?? 0) }}" required min="0" step="1" type="number" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Ordre d\'affichage') }}</span>
                            <input name="sort_order" value="{{ old('sort_order', $variant?->sort_order ?? 0) }}" min="0" step="1" type="number" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        </label>
                    </div>
                </div>

                {{-- Notes --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Notes') }}</h2>
                    <div class="mt-5">
                        <label class="space-y-2">
                            <textarea name="notes" rows="4" placeholder="{{ $tr('Notes internes, remarques sur cette variante...') }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">{{ old('notes', $variant?->notes) }}</textarea>
                        </label>
                    </div>
                </div>
            </div>

            <aside class="space-y-6">
                {{-- Image --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Image') }}</h2>
                    <div class="mt-5">
                        @if ($isEdit && $variant?->image)
                            <div class="mb-3 rounded-xl border border-slate-200 p-2 dark:border-white/10">
                                <img src="{{ asset('storage/' . $variant->image) }}" class="w-full rounded-lg object-cover" alt="{{ $variant->name }}">
                            </div>
                        @endif
                        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-slate-200 p-6 transition hover:border-brand/40 hover:bg-brand/5 dark:border-white/10 dark:hover:bg-brand/5">
                            <svg class="size-8 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $tr('Cliquer pour télécharger une image') }}</span>
                            <span class="text-xs text-slate-400">{{ $tr('JPG, PNG, WebP, max 2MB') }}</span>
                            <input type="file" name="image" accept="image/*" class="hidden">
                        </label>
                    </div>
                </div>

                {{-- Statut --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Statut') }}</h2>
                    <div class="mt-5 space-y-3">
                        <label class="flex items-center gap-3">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $variant?->is_active ?? true)) class="size-4 rounded border-slate-300 text-brand focus:ring-brand dark:border-slate-600">
                            <span class="text-sm text-slate-700 dark:text-slate-300">{{ $tr('Actif en POS') }}</span>
                        </label>
                        <p class="text-xs text-slate-400">{{ $tr('Les variantes inactives ne sont pas visibles en caisse.') }}</p>
                    </div>
                </div>

                {{-- Submit --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="space-y-3">
                        <button type="submit" class="h-11 w-full rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm transition hover:brightness-110">
                            {{ $isEdit ? $tr('Mettre à jour') : $tr('Créer la variante') }}
                        </button>
                        <a href="{{ route('variants.index') }}" class="flex h-11 w-full items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:text-slate-200">
                            {{ $tr('Annuler') }}
                        </a>
                    </div>
                </div>
            </aside>
        </div>
    </form>
</x-layouts.app>
