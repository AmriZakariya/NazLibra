{{-- The language menu: every language the back office speaks, each named in
     itself (never translated), the current one ticked. The choice is kept
     on the account and for the session (see LibraireProController::switchLocale).
     Needs $locale. --}}
<details class="lang-menu relative" data-lang-menu>
    <summary class="flex w-full cursor-pointer list-none items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">
        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        <span>Langue</span>
        <span class="ms-auto inline-flex items-center gap-1.5 text-slate-500 dark:text-slate-400" data-no-translate>
            <span aria-hidden="true">{{ \App\Support\Locale::FLAGS[$locale] ?? '' }}</span>{{ \App\Support\Locale::label($locale) }}
        </span>
    </summary>
    <div class="mt-1 grid gap-0.5 rounded-lg border border-slate-200 bg-white p-1 dark:border-white/10 dark:bg-slate-900" role="list">
        @foreach (\App\Support\Locale::SHOP_LANGUAGES as $code => $name)
            <form action="{{ route('locale.switch', $code) }}" method="POST" role="listitem">
                @csrf
                <button type="submit" lang="{{ $code }}" dir="{{ \App\Support\Locale::dir($code) }}" data-no-translate
                        class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-start text-sm {{ $code === $locale ? 'bg-brand/10 font-bold text-brand' : 'font-medium text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5' }}"
                        @if ($code === $locale) aria-current="true" @endif>
                    <span aria-hidden="true">{{ \App\Support\Locale::FLAGS[$code] }}</span>
                    <span>{{ $name }}</span>
                    @if ($code === $locale)
                        <svg class="ms-auto size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                    @endif
                </button>
            </form>
        @endforeach
    </div>
</details>
