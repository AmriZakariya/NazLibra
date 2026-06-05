@php
    $statusTones = ['simulated' => 'info', 'queued' => 'warning', 'sent' => 'success', 'failed' => 'danger'];
    $statusLabels = ['simulated' => 'Simulé', 'queued' => 'En file', 'sent' => 'Envoyé', 'failed' => 'Erreur'];
@endphp

<article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-950/40">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h3 class="font-semibold">Journal d’envoi</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Derniers messages simulés ou placés en file.</p>
        </div>
        <x-status-pill tone="neutral">{{ $messagingOutbox->count() }}</x-status-pill>
    </div>
    <div class="mt-4 max-h-[420px] space-y-2 overflow-y-auto pr-1">
        @forelse($messagingOutbox as $message)
            <div class="rounded-lg border border-slate-200 p-3 text-sm dark:border-white/10">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ strtoupper($message['channel'] ?? 'message') }} · {{ $message['recipient_name'] ?? $message['recipient'] ?? 'Destinataire' }}</p>
                        <p class="mt-1 truncate text-xs text-slate-500">{{ $message['recipient'] ?? '—' }} · {{ $message['created_at'] ?? '—' }}</p>
                    </div>
                    <x-status-pill :tone="$statusTones[$message['status'] ?? 'queued'] ?? 'neutral'">{{ $statusLabels[$message['status'] ?? 'queued'] ?? ($message['status'] ?? 'En file') }}</x-status-pill>
                </div>
                <p class="mt-2 line-clamp-3 text-slate-600 dark:text-slate-300">{{ $message['body'] ?? '' }}</p>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-white/10">Aucun message enregistré pour le moment.</div>
        @endforelse
    </div>
</article>
