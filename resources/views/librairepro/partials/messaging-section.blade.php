@php
    $messagingSection = request('section', 'messaging');
    $channels = [
        'whatsapp' => ['label' => 'WhatsApp', 'hint' => 'Reçus, relances, campagnes rapides'],
        'sms' => ['label' => 'SMS', 'hint' => 'Notifications courtes et urgentes'],
        'email' => ['label' => 'Email', 'hint' => 'Devis, rapports et messages longs'],
    ];
    $statusTones = ['simulated' => 'info', 'queued' => 'warning', 'sent' => 'success', 'failed' => 'danger'];
    $statusLabels = ['simulated' => 'Simulé', 'queued' => 'En file', 'sent' => 'Envoyé', 'failed' => 'Erreur'];
@endphp

<section class="space-y-5">
    <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="border-b border-slate-200 bg-slate-50/70 p-5 dark:border-white/10 dark:bg-white/[0.04]">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-start gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand text-lg font-semibold text-white">✉</span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand">Paramètres · messagerie</p>
                        <h2 class="mt-1 text-xl font-semibold">Messages clients, modèles & canaux</h2>
                        <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Envoyez des messages manuels, préparez les modèles et configurez SMS, WhatsApp et email sans perdre la trace des envois.</p>
                    </div>
                </div>
                <div class="app-action-row">
                    <x-status-pill :tone="$messagingConfig['test_mode'] ? 'warning' : 'success'">{{ $messagingConfig['test_mode'] ? 'Mode test' : 'Production' }}</x-status-pill>
                    <x-status-pill tone="info">{{ strtoupper($messagingConfig['default_channel']) }}</x-status-pill>
                    <x-status-pill tone="neutral">{{ count($messageTemplates) }} modèle(s)</x-status-pill>
                </div>
            </div>
        </div>

        <nav class="flex flex-wrap gap-2 border-b border-slate-200 p-4 dark:border-white/10">
            <a href="{{ route('module', ['module' => 'settings', 'section' => 'messaging']) }}" class="app-tab-link {{ $messagingSection === 'messaging' ? 'is-active' : '' }}">Envoyer</a>
            <a href="{{ route('module', ['module' => 'settings', 'section' => 'message-templates']) }}" class="app-tab-link {{ $messagingSection === 'message-templates' ? 'is-active' : '' }}">Modèles</a>
            <a href="{{ route('module', ['module' => 'settings', 'section' => 'sms-api']) }}" class="app-tab-link {{ $messagingSection === 'sms-api' ? 'is-active' : '' }}">API & canaux</a>
        </nav>

        @if ($messagingSection === 'messaging')
            <div class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(340px,0.85fr)]">
                <form action="{{ route('settings.messaging.send') }}" method="POST" class="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                    @csrf
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold">Envoyer un message</h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">En mode test, le message est simulé et enregistré dans le journal.</p>
                        </div>
                        <x-status-pill :tone="$messagingConfig['test_mode'] ? 'warning' : 'success'">{{ $messagingConfig['test_mode'] ? 'Simulation' : 'Envoi réel' }}</x-status-pill>
                    </div>
                    <div class="grid gap-3 md:grid-cols-3">
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Canal</span><select name="channel" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach($channels as $key => $channel)<option value="{{ $key }}" @selected(old('channel', $messagingConfig['default_channel']) === $key)>{{ $channel['label'] }}</option>@endforeach</select></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Destinataire</span><select name="recipient_mode" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="contact">Client existant</option><option value="manual">Saisie manuelle</option></select></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Client</span><select name="contact_id" data-searchable-select data-placeholder="Rechercher client..." class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Choisir client</option>@foreach($messagingContacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }} · {{ $contact->phone ?: $contact->email ?: 'sans contact' }}</option>@endforeach</select></label>
                    </div>
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Téléphone / email manuel</span><input name="recipient" value="{{ old('recipient') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="+212... ou client@email.ma"></label>
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Objet email</span><input name="subject" value="{{ old('subject') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Optionnel pour SMS / WhatsApp"></label>
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Message *</span><textarea name="body" required class="min-h-40 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Bonjour @{{client_name}}, ...">{{ old('body') }}</textarea></label>
                    <div class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 sm:flex-row sm:items-center sm:justify-between">
                        <span>Variables disponibles: <strong>@{{client_name}}</strong>, <strong>@{{store_name}}</strong>, <strong>@{{date}}</strong></span>
                        <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Envoyer / simuler</button>
                    </div>
                </form>

                <div class="space-y-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                        <h3 class="font-semibold">Modèles rapides</h3>
                        <div class="mt-3 grid gap-2">
                            @foreach (collect($messageTemplates)->where('is_active', true)->take(4) as $template)
                                <div class="rounded-lg border border-slate-200 p-3 dark:border-white/10">
                                    <div class="flex items-start justify-between gap-3"><strong>{{ $template['name'] }}</strong><x-status-pill tone="neutral">{{ $channels[$template['channel']]['label'] ?? $template['channel'] }}</x-status-pill></div>
                                    <p class="mt-2 line-clamp-2 text-sm text-slate-500 dark:text-slate-400">{{ $template['body'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </article>
                    @include('librairepro.partials.messaging-outbox')
                </div>
            </div>
        @elseif ($messagingSection === 'message-templates')
            <div class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                    <div class="border-b border-slate-200 p-4 dark:border-white/10"><h3 class="font-semibold">Modèles de messagerie</h3><p class="mt-1 text-sm text-slate-500">Créez des textes réutilisables pour tickets, relances, campagnes et notifications.</p></div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[820px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-3">Nom</th>
                                    <th class="px-3 py-3">Canal</th>
                                    <th class="px-3 py-3">Message</th>
                                    <th class="px-3 py-3">Statut</th>
                                    <th class="px-3 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                                @foreach($messageTemplates as $template)
                                    <tr>
                                        <td class="px-3 py-3 font-semibold">{{ $template['name'] }}</td>
                                        <td class="px-3 py-3">{{ $channels[$template['channel']]['label'] ?? $template['channel'] }}</td>
                                        <td class="max-w-md px-3 py-3 text-slate-500 dark:text-slate-400">
                                            <span class="line-clamp-2">{{ $template['body'] }}</span>
                                        </td>
                                        <td class="px-3 py-3">
                                            <x-status-pill :tone="$template['is_active'] ? 'success' : 'danger'">{{ $template['is_active'] ? 'Actif' : 'Inactif' }}</x-status-pill>
                                        </td>
                                        <td class="px-3 py-3 text-right">
                                            <button
                                                type="button"
                                                onclick="document.getElementById('message-template-{{ $template['key'] }}').showModal()"
                                                class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10"
                                            >
                                                Modifier
                                            </button>
                                        </td>
                                    </tr>

                                    <dialog id="message-template-{{ $template['key'] }}" class="app-dialog w-[min(720px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                        <form action="{{ route('settings.message-templates.update', ['key' => $template['key']]) }}" method="POST">
                                            @csrf
                                            @method('PUT')
                                            <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10">
                                                <div>
                                                    <p class="text-sm font-semibold text-brand">Modèle</p>
                                                    <h3 class="mt-1 text-xl font-semibold">Modifier {{ $template['name'] }}</h3>
                                                </div>
                                                <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 dark:border-white/10" type="button">×</button>
                                            </div>
                                            <div class="grid gap-4 p-5">
                                                <input name="name" required value="{{ $template['name'] }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                                <select name="channel" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                                    @foreach($channels as $key => $channel)
                                                        <option value="{{ $key }}" @selected($template['channel'] === $key)>{{ $channel['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                <input name="subject" value="{{ $template['subject'] }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Objet">
                                                <textarea name="body" required class="min-h-36 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ $template['body'] }}</textarea>
                                                <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5">
                                                    <input name="is_active" value="1" type="checkbox" @checked($template['is_active']) class="size-4 accent-[var(--brand-primary)]">
                                                    Modèle actif
                                                </label>
                                            </div>
                                            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-between">
                                                <button form="message-template-delete-{{ $template['key'] }}" class="rounded-lg border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600" type="submit">Supprimer</button>
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" class="dialog-close rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950">Annuler</button>
                                                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
                                                </div>
                                            </div>
                                        </form>
                                        <form id="message-template-delete-{{ $template['key'] }}" action="{{ route('settings.message-templates.destroy', ['key' => $template['key']]) }}" method="POST" onsubmit="return confirm('Supprimer ce modèle ?')">
                                            @csrf
                                            @method('DELETE')
                                        </form>
                                    </dialog>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                    <h3 class="font-semibold">Créer un modèle</h3>
                    <form action="{{ route('settings.message-templates.store') }}" method="POST" class="mt-4 grid gap-3">@csrf<input name="name" required class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom du modèle"><select name="channel" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach($channels as $key => $channel)<option value="{{ $key }}">{{ $channel['label'] }}</option>@endforeach</select><input name="subject" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Objet email"><textarea name="body" required class="min-h-36 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Message..."></textarea><label class="flex items-center gap-2 text-sm font-semibold"><input name="is_active" value="1" checked type="checkbox" class="size-4 accent-[var(--brand-primary)]"> Actif</label><button class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Créer modèle</button></form>
                </article>
            </div>
        @else
            <div class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                <form action="{{ route('settings.messaging.update') }}" method="POST" class="space-y-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                    @csrf
                    <section><h3 class="font-semibold">Canal par défaut</h3><div class="mt-3 grid gap-3 md:grid-cols-3">@foreach($channels as $key => $channel)<label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40"><input name="default_channel" value="{{ $key }}" type="radio" @checked($messagingConfig['default_channel'] === $key) class="size-4 accent-[var(--brand-primary)]"> <strong class="ml-2">{{ $channel['label'] }}</strong><span class="mt-2 block text-sm text-slate-500">{{ $channel['hint'] }}</span></label>@endforeach</div></section>
                    <section class="grid gap-3 border-t border-slate-200 pt-4 dark:border-white/10 md:grid-cols-2"><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom expéditeur</span><input name="sender_name" value="{{ $messagingConfig['sender_name'] }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Reply-to</span><input name="reply_to" type="email" value="{{ $messagingConfig['reply_to'] }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label></section>
                    <section class="grid gap-3 border-t border-slate-200 pt-4 dark:border-white/10 md:grid-cols-2"><h3 class="md:col-span-2 font-semibold">SMS</h3><input name="sms_provider" value="{{ $messagingConfig['sms_provider'] }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Provider"><input name="sms_sender_id" value="{{ $messagingConfig['sms_sender_id'] }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Sender ID"><input name="sms_api_key" value="{{ $messagingConfig['sms_api_key'] }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900 md:col-span-2" placeholder="Clé API SMS"></section>
                    <section class="grid gap-3 border-t border-slate-200 pt-4 dark:border-white/10 md:grid-cols-2"><h3 class="md:col-span-2 font-semibold">WhatsApp</h3><input name="whatsapp_provider" value="{{ $messagingConfig['whatsapp_provider'] }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Provider"><input name="whatsapp_number" value="{{ $messagingConfig['whatsapp_number'] }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="+212..."><input name="whatsapp_token" value="{{ $messagingConfig['whatsapp_token'] }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900 md:col-span-2" placeholder="Token WhatsApp"></section>
                    <section class="grid gap-3 border-t border-slate-200 pt-4 dark:border-white/10 md:grid-cols-2"><h3 class="md:col-span-2 font-semibold">Email</h3><input name="email_provider" value="{{ $messagingConfig['email_provider'] }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="smtp / resend / sendgrid"><input name="email_from" type="email" value="{{ $messagingConfig['email_from'] }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="no-reply@..."></section>
                    <section class="grid gap-3 border-t border-slate-200 pt-4 dark:border-white/10 md:grid-cols-2"><input type="hidden" name="test_mode" value="0"><input type="hidden" name="log_messages" value="0"><label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40"><input name="test_mode" value="1" type="checkbox" @checked($messagingConfig['test_mode']) class="size-4 accent-[var(--brand-primary)]"> <strong class="ml-2">Mode test</strong><span class="mt-2 block text-sm text-slate-500">Simule les envois sans contacter le fournisseur.</span></label><label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40"><input name="log_messages" value="1" type="checkbox" @checked($messagingConfig['log_messages']) class="size-4 accent-[var(--brand-primary)]"> <strong class="ml-2">Journaliser les messages</strong><span class="mt-2 block text-sm text-slate-500">Garde les derniers envois pour le support.</span></label></section>
                    <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Enregistrer API & canaux</button>
                </form>
                <div class="space-y-4">@include('librairepro.partials.messaging-outbox')</div>
            </div>
        @endif
    </article>
</section>
