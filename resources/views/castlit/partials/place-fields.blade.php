{{-- Pays et ville du commerce — le même bloc sur le site public et dans
     « Nouveau client », pour que les deux formulaires ne divergent pas.

     Le pays présélectionné est celui du visiteur (adresse IP, puis langue du
     navigateur — App\Support\GeoCountry ; à défaut, son fuseau horaire, ci-
     dessous), sinon le Maroc. La devise suit le pays tant que personne
     ne l'a choisie à la main : la valeur par défaut était MAD pour tout le
     monde, et une boutique parisienne qui n'avait pas vu le champ se
     retrouvait créée en dirhams. --}}
@php
    $placeLocale = in_array(app()->getLocale(), ['fr', 'ar', 'en'], true) ? app()->getLocale() : 'fr';
    $placeGroups = \App\Support\Countries::grouped($placeLocale);
    $detectedCountry = $detectedCountry ?? null;
    $selectedCountry = old('country', $detectedCountry ?? \App\Support\Countries::DEFAULT);
    // Only needed when the server could not tell: the browser's timezone
    // then decides (Africa/Casablanca → MA, Europe/Paris → FR).
    // The public signup only: in « Nouveau client » the admin's own timezone
    // says nothing about the client's country.
    $timezoneCountries = (empty($detectCountry) || $detectedCountry || old('country')) ? null : \App\Support\GeoCountry::timezoneMap();
@endphp
<div class="grid2" data-place-fields data-currency-map='@json(\App\Support\Countries::currencyMap())' @if ($timezoneCountries) data-timezone-countries='@json($timezoneCountries)' @endif>
    <div class="fld">
        <label for="country">{{ __('castlit.f_country') }} <span class="req">*</span></label>
        <select id="country" name="country" required data-country>
            {{-- Les pays d'où viennent réellement les demandes d'abord, puis
                 tous les autres par ordre alphabétique : 248 pays sans
                 regroupement, c'est le Maroc noyé au milieu de la liste. --}}
            <optgroup label="{{ __('castlit.f_country_frequent') }}">
                @foreach ($placeGroups['frequent'] as $code => $name)
                    <option value="{{ $code }}" @selected($selectedCountry === $code)>{{ \App\Support\Countries::flag($code) }} {{ $name }}</option>
                @endforeach
            </optgroup>
            <optgroup label="{{ __('castlit.f_country_all') }}">
                @foreach ($placeGroups['others'] as $code => $name)
                    <option value="{{ $code }}" @selected($selectedCountry === $code)>{{ \App\Support\Countries::flag($code) }} {{ $name }}</option>
                @endforeach
            </optgroup>
        </select>
        @error('country')<span class="field-error">{{ $message }}</span>@enderror
    </div>
    <div class="fld">
        <label for="city">{{ __('castlit.f_city') }}</label>
        <input id="city" name="city" maxlength="120" autocomplete="address-level2" value="{{ old('city') }}" placeholder="{{ __('castlit.f_city_ph') }}">
        @error('city')<span class="field-error">{{ $message }}</span>@enderror
    </div>
</div>
<script>
(() => {
    const block = document.currentScript.previousElementSibling;
    const form = block.closest('form');
    const country = block.querySelector('[data-country]');
    const currency = form?.querySelector('select[name="currency"]');
    if (!country || !currency) return;

    const map = JSON.parse(block.dataset.currencyMap || '{}');

    // The server could not place the visitor: their timezone is the next best
    // witness. Set before the currency logic below reads the country.
    if (block.dataset.timezoneCountries) {
        try {
            const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            const guess = JSON.parse(block.dataset.timezoneCountries)[zone];
            if (guess && [...country.options].some((o) => o.value === guess) && country.value !== guess) {
                country.value = guess;
                country.dispatchEvent(new Event('change'));
                if (map[guess] && [...currency.options].some((o) => o.value === map[guess])) {
                    currency.value = map[guess];
                }
            }
        } catch { /* An old browser keeps the default. */ }
    }
    // Follows the country only while it still holds the previous country's
    // suggestion: a currency someone picked deliberately is left alone.
    let suggested = map[country.value] ?? currency.value;
    currency.addEventListener('change', () => { suggested = null; });
    country.addEventListener('change', () => {
        const next = map[country.value];
        if (!next || suggested === null || currency.value !== suggested) return;
        if ([...currency.options].some((o) => o.value === next)) {
            currency.value = next;
            suggested = next;
        }
    });
})();
</script>
