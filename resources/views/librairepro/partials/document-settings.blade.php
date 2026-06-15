@php
    $documentPlaceholders = [
        '{{store_name}}' => 'Nom du magasin',
        '{{store_phone}}' => 'Téléphone magasin',
        '{{store_address}}' => 'Adresse magasin',
        '{{ice}}' => 'ICE',
        '{{document_title}}' => 'Type de document',
        '{{document_number}}' => 'Numéro',
        '{{document_date}}' => 'Date',
        '{{due_date}}' => 'Échéance / date prévue',
        '{{client_name}}' => 'Client',
        '{{supplier_name}}' => 'Fournisseur',
        '{{partner_name}}' => 'Client ou fournisseur',
        '{{reference}}' => 'Référence',
        '{{sale_number}}' => 'N° vente liée',
        '{{payment_method}}' => 'Méthode paiement',
        '{{status}}' => 'Statut',
        '{{created_by}}' => 'Créé par',
        '{{updated_by}}' => 'Mis à jour par',
        '{{total}}' => 'Total formaté',
        '{{today}}' => 'Date du jour',
    ];
@endphp

<section class="space-y-5">
    <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="border-b border-slate-200 bg-slate-50/70 p-5 dark:border-white/10 dark:bg-white/[0.04]">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-start gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand text-lg font-semibold text-white">PDF</span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand">Paramètres · documents</p>
                        <h2 class="mt-1 text-xl font-semibold">Modèles PDF commerciaux</h2>
                        <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">Personnalisez les PDF de ventes, factures et achats avec les données réelles du magasin, logo, signature, banque et placeholders.</p>
                    </div>
                </div>
                <div class="app-action-row">
                    <x-status-pill tone="primary">Ventes</x-status-pill>
                    <x-status-pill tone="info">Factures</x-status-pill>
                    <x-status-pill tone="warning">Achats</x-status-pill>
                </div>
            </div>
        </div>

        <form action="{{ route('settings.documents.update') }}" method="POST" class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1fr)_360px]">
            @csrf
            <div class="space-y-5">
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                    <h3 class="font-semibold">Titres & identité visuelle</h3>
                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Titre vente</span><input name="sale_title" required value="{{ old('sale_title', $documentSettings['sale_title']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Titre facture</span><input name="invoice_title" required value="{{ old('invoice_title', $documentSettings['invoice_title']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Titre achat</span><input name="purchase_title" required value="{{ old('purchase_title', $documentSettings['purchase_title']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Couleur principale</span><input name="primary_color" type="color" value="{{ old('primary_color', $documentSettings['primary_color']) }}" class="h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Couleur accent</span><input name="accent_color" type="color" value="{{ old('accent_color', $documentSettings['accent_color']) }}" class="h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900"></label>
                        <div class="grid gap-2">
                            <input type="hidden" name="show_logo" value="0">
                            <input type="hidden" name="show_signature" value="0">
                            <input type="hidden" name="show_bank_details" value="0">
                            <label class="flex h-11 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><input name="show_logo" value="1" type="checkbox" @checked(old('show_logo', $documentSettings['show_logo'])) class="size-4 accent-[var(--brand-primary)]"> Logo</label>
                            <label class="flex h-11 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><input name="show_signature" value="1" type="checkbox" @checked(old('show_signature', $documentSettings['show_signature'])) class="size-4 accent-[var(--brand-primary)]"> Signature</label>
                            <label class="flex h-11 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><input name="show_bank_details" value="1" type="checkbox" @checked(old('show_bank_details', $documentSettings['show_bank_details'])) class="size-4 accent-[var(--brand-primary)]"> Banque</label>
                        </div>
                    </div>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                    <h3 class="font-semibold">Textes avec placeholders</h3>
                    <div class="mt-4 grid gap-4">
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Texte en-tête</span><textarea name="header_text" class="min-h-20 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('header_text', $documentSettings['header_text']) }}</textarea></label>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Note vente</span><textarea name="sale_note_template" class="min-h-32 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('sale_note_template', $documentSettings['sale_note_template']) }}</textarea></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Note facture</span><textarea name="invoice_note_template" class="min-h-32 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('invoice_note_template', $documentSettings['invoice_note_template']) }}</textarea></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Note achat</span><textarea name="purchase_note_template" class="min-h-32 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('purchase_note_template', $documentSettings['purchase_note_template']) }}</textarea></label>
                        </div>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Pied de document</span><textarea name="footer_text" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('footer_text', $documentSettings['footer_text']) }}</textarea></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Conditions générales</span><textarea name="terms" class="min-h-28 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('terms', $documentSettings['terms']) }}</textarea></label>
                    </div>
                </article>

                <button class="rounded-lg bg-brand px-5 py-3 text-sm font-semibold text-white shadow-sm">Enregistrer les modèles PDF</button>
            </div>

            <aside class="space-y-4">
                <article class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <h3 class="font-semibold">Placeholders disponibles</h3>
                    <div class="mt-3 max-h-[460px] space-y-2 overflow-y-auto pr-1">
                        @foreach ($documentPlaceholders as $key => $label)
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-950/50">
                                <code class="font-semibold text-brand">{{ $key }}</code>
                                <span class="text-right text-xs text-slate-500">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                    <h3 class="font-semibold">Logo & signature</h3>
                    <p class="mt-2 text-slate-500 dark:text-slate-400">Les chemins du logo, de la signature, l’adresse et les coordonnées bancaires sont définis dans Paramètres > Société.</p>
                    <a href="{{ route('module', ['module' => 'settings', 'section' => 'company']) }}" class="mt-3 inline-flex rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold hover:border-brand hover:text-brand dark:border-white/10">Ouvrir société</a>
                </article>
            </aside>
        </form>
    </article>
</section>
