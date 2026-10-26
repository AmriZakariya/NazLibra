{{-- Pays et ville du commerce — le même bloc sur le site public et dans
     « Nouveau client », pour que les deux formulaires ne divergent pas.

     Le Maroc est présélectionné, et la devise suit le pays tant que personne
     ne l'a choisie à la main : la valeur par défaut était MAD pour tout le
     monde, et une boutique parisienne qui n'avait pas vu le champ se
     retrouvait créée en dirhams. --}}
@php
    $placeLocale = in_array(app()->getLocale(), ['fr', 'ar', 'en'], true) ? app()->getLocale() : 'fr';
    $placeCountries = \App\Support\Countries::localized($placeLocale);
    $selectedCountry = old('country', \App\Support\Countries::DEFAULT);
@endphp
<div class="grid2" data-place-fields data-currency-map='@json(\App\Support\Countries::currencyMap())'>
    <div class="fld">
        <label for="country">{{ __('castlit.f_country') }} <span class="req">*</span></label>
        <select id="country" name="country" required data-country>
            @foreach ($placeCountries as $code => $name)
                <option value="{{ $code }}" @selected($selectedCountry === $code)>{{ \App\Support\Countries::flag($code) }} {{ $name }}</option>
            @endforeach
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
