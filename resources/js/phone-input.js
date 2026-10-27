// Every phone field gets a country picker (flag + dial code), is laid out as
// it is typed, and is sent international ("+212612345678") — so a number
// typed "06 12 34 56 78" in Casablanca and one typed in Paris do not look
// alike in the contacts list, and a WhatsApp link can be built from either.
//
// A field opts in by being type="tel" or carrying data-phone; data-phone="off"
// keeps a plain input. A number the country's plan rejects shows a warning
// under the field but is still saved: a shop knows its customers' numbers
// better than the numbering plan does.
//
// The library (with its numbering plans, ~300 KB) is fetched only on a page
// that has a phone field.

// The stylesheets ship with the page (17 KB; the flag images load only when a
// flag is drawn): fetched on demand, the library's own was dropped by Vite's
// preload helper and the picker rendered as a bare list. Ours comes second so
// it is the one that wins.
import 'intl-tel-input/styles';
import '../css/phone-input.css';

const SELECTOR = 'input[type="tel"]:not([data-phone="off"]), input[data-phone]:not([data-phone="off"])';

// Where requests come from first (App\Support\Countries::FREQUENT); EH is
// left out for the reason given there.
const FREQUENT = ['ma', 'fr', 'be', 'es', 'dz', 'tn', 'sn', 'ci', 'ca'];

const WARNING = {
    fr: 'Ce numéro ne semble pas valide pour ce pays — il sera enregistré tel quel.',
    en: 'This number does not look valid for this country — it will be saved as typed.',
    ar: 'يبدو أن هذا الرقم غير صالح لهذا البلد — سيُحفظ كما هو.',
    es: 'Este número no parece válido para este país — se guardará tal cual.',
    de: 'Diese Nummer scheint für dieses Land ungültig — sie wird so gespeichert.',
    it: 'Questo numero non sembra valido per questo paese — verrà salvato così.',
    pt: 'Este número não parece válido para este país — será guardado como está.',
};

const pageLocale = () => {
    const lang = (document.documentElement.dataset.locale || document.documentElement.lang || window.libraireProLocale || 'fr')
        .toLowerCase()
        .slice(0, 2);

    return WARNING[lang] ? lang : 'fr';
};

// The shop's country: the layout says it, else Maroc.
const defaultCountry = () =>
    (document.documentElement.dataset.phoneCountry || window.appPhoneCountry || 'MA').toLowerCase();

// The picker's own wording ("Recherche", "Aucun résultat"), in the languages
// the app ships; country names come from the browser (Intl.DisplayNames).
const UI_TRANSLATIONS = {
    fr: () => import('intl-tel-input/locale/fr'),
    en: () => import('intl-tel-input/locale/en'),
    ar: () => import('intl-tel-input/locale/ar'),
    es: () => import('intl-tel-input/locale/es'),
    de: () => import('intl-tel-input/locale/de'),
    it: () => import('intl-tel-input/locale/it'),
    pt: () => import('intl-tel-input/locale/pt'),
};

let library = null;
const loadLibrary = () => {
    library ??= (async () => {
        const [module, translations] = await Promise.all([
            import('intl-tel-input/intlTelInputWithUtils'),
            UI_TRANSLATIONS[pageLocale()]().catch(() => null),
        ]);

        return { intlTelInput: module.default, uiTranslations: translations?.default };
    })();

    return library;
};

// A country picked elsewhere on the form (the signup's "Pays", the setup's
// timezone) moves an empty phone field along with it.
const countryFrom = (input) => {
    const source = input.dataset.phoneCountryFrom ? document.querySelector(input.dataset.phoneCountryFrom) : null;
    if (!source) return null;

    const read = () => {
        const option = source.selectedOptions?.[0];
        const code = (option?.dataset.country || source.value || '').trim().toLowerCase();

        return /^[a-z]{2}$/.test(code) ? code : null;
    };

    return { source, read };
};

let warningCount = 0;
const warningNode = (input) => {
    const node = document.createElement('p');
    node.id = `phone-warning-${++warningCount}`;
    node.className = 'phone-warning';
    node.setAttribute('role', 'status');
    node.hidden = true;
    node.textContent = WARNING[pageLocale()];
    (input.closest('.iti') || input).insertAdjacentElement('afterend', node);

    return node;
};

const enhance = async (input) => {
    if (input.dataset.phoneReady) return;
    input.dataset.phoneReady = 'pending';

    const { intlTelInput, uiTranslations } = await loadLibrary();
    if (!input.isConnected) return;

    const locale = pageLocale();
    const follow = countryFrom(input);
    const initialCountry = input.dataset.phoneCountry?.toLowerCase() || follow?.read() || defaultCountry();

    // The placeholder is the country's own example from now on; "06 00 00 00
    // 00" under a French flag would mislead.
    input.removeAttribute('placeholder');
    // The library checks the plan; the old "8 to 24 digits" pattern would
    // block a number it accepts, and the browser's message is not ours.
    input.removeAttribute('pattern');
    input.setAttribute('inputmode', 'tel');
    input.setAttribute('autocomplete', input.getAttribute('autocomplete') || 'tel');
    input.setAttribute('dir', 'ltr');
    if (input.type !== 'tel') input.type = 'tel';

    const iti = intlTelInput(input, {
        initialCountry,
        countryOrder: FREQUENT,
        excludeCountries: ['eh'],
        countryNameLocale: locale,
        ...(uiTranslations ? { uiTranslations } : {}),
        separateDialCode: true,
        formatAsYouType: true,
        strictMode: true,
        containerClass: 'phone-field',
    });

    input.dataset.phoneReady = '1';
    input._iti = iti;

    const warning = warningNode(input);
    const check = () => {
        const empty = input.value.trim() === '';
        const valid = empty || iti.isValidNumber() !== false;
        warning.hidden = valid;
        input.classList.toggle('phone-invalid', !valid);
        if (valid) {
            input.removeAttribute('aria-describedby');
        } else {
            input.setAttribute('aria-describedby', warning.id);
        }
    };
    input.addEventListener('blur', () => {
        // A pasted "0612345678" (or "+33 6…", which also moves the flag) is
        // laid out like the country writes it once the cashier moves on.
        if (input.value.trim() !== '' && iti.isValidNumber()) {
            iti.setNumber(iti.getNumber());
        }
        check();
    });
    input.addEventListener('countrychange', () => {
        if (!warning.hidden) check();
    });
    input.addEventListener('input', () => {
        if (!warning.hidden) check();
    });
    if (input.value.trim() !== '') check();

    if (follow) {
        follow.source.addEventListener('change', () => {
            const code = follow.read();
            if (code && input.value.trim() === '') iti.setSelectedCountry(code);
        });
    }
};

// The number as the server stores it. Called just before a form is sent
// (and by scripts that read the field themselves).
export const internationalPhone = (input) => {
    const raw = (input?.value || '').trim();
    if (raw === '' || !input?._iti) return raw;

    return input._iti.getNumber() || raw;
};

const toInternational = (form) => {
    // form.elements, not querySelectorAll: the till's quick-client phone sits
    // outside its form and joins it with form="pos-checkout-form".
    Array.from(form.elements).forEach((input) => {
        if (!input._iti || !input.matches(SELECTOR)) return;
        const number = internationalPhone(input);
        if (number && number !== input.value) {
            // The library would re-format the shown value on its next input
            // event; the form is leaving, so it does not get one.
            input.value = number;
        }
    });
};

export const initPhoneInputs = (root = document) => {
    root.querySelectorAll?.(SELECTOR).forEach((input) => {
        enhance(input).catch((error) => {
            // A failed download leaves a plain field, which still works.
            input.dataset.phoneReady = '';
            console.warn('phone field', error);
        });
    });
};

let started = false;
export const startPhoneInputs = () => {
    if (started) return;
    started = true;

    // Capture phase: runs before the form's own submit handlers, so one that
    // sends FormData by fetch already reads the international number.
    document.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement) toInternational(event.target);
    }, true);

    const run = () => {
        initPhoneInputs();
        // Fields added later: a dialog filled in by script, a setup row.
        new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType !== 1) return;
                    if (node.matches?.(SELECTOR)) initPhoneInputs(node.parentElement);
                    else if (node.querySelector?.(SELECTOR)) initPhoneInputs(node);
                });
            }
        }).observe(document.body, { childList: true, subtree: true });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }
};

window.internationalPhone = internationalPhone;
