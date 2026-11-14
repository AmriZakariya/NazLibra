{{-- The back office's language, for every page with a <head>: the dictionary
     (cached under its version), then the translator, which must run before the
     page's own scripts. In a language other than French the page stays hidden
     until translated, so French never flashes first; at most 2.5 s, whatever
     happens. Needs $locale. --}}
<script>window.libraireProLocale = @json($locale);</script>
@if ($locale !== 'fr')
    <style>html.i18n-pending body { visibility: hidden; }</style>
    <script>
        document.documentElement.classList.add('i18n-pending');
        setTimeout(function () { document.documentElement.classList.remove('i18n-pending'); }, 2500);
    </script>
    <script src="{{ route('i18n.script', ['locale' => $locale, 'v' => \App\Support\Locale::version($locale)]) }}"></script>
@endif
@vite('resources/js/i18n.js')
