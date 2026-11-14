// The back office's translator. The pages are written in French; in any
// other language this swaps every French text for its translation, from the
// language's dictionary (window.libraireProTranslations, served by
// /i18n/{locale}.js and built from lang/{locale}.json):
//
//  - the page as it loads, then whatever is added later (tables, dialogs,
//    toasts), and the browser's own alert / confirm / prompt;
//  - placeholders, tooltips and labels as well as text;
//  - a text built around values (« Bonjour, Amina », « 12 résultat(s) »)
//    through its pattern (« Bonjour, {0} »), the values kept.
//
// French needs nothing: this does nothing then. A text with no translation
// stays French.

const locale = (document.documentElement.dataset.locale || window.libraireProLocale || 'fr').slice(0, 2);
const dictionary = window.libraireProTranslations || {};
const active = locale !== 'fr' && Object.keys(dictionary).length > 0;

const clean = (text) => (text || '').toString().replace(/[\s ]+/g, ' ').trim();

// Patterns: « {0} résultat(s) » → a regex; the values go back in the same order.
const patterns = [];
const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
if (active) {
    for (const [key, value] of Object.entries(dictionary)) {
        if (!/\{\d+\}/.test(key)) continue;
        const order = [];
        const source = key.split(/(\{\d+\})/).map((part) => {
            const m = part.match(/^\{(\d+)\}$/);
            if (m) {
                order.push(m[1]);
                return '(.+?)';
            }
            return escapeRe(part);
        }).join('');
        // A pattern must hold some words of its own, or it would match anything.
        if (key.replace(/\{\d+\}/g, '').replace(/[\s\W\d]/g, '').length < 2) continue;
        patterns.push({ re: new RegExp(`^${source}$`, 'u'), order, value });
    }
    // Longest first: the most specific pattern wins.
    patterns.sort((a, b) => b.re.source.length - a.re.source.length);
}

const cache = new Map();

export const translate = (text) => {
    const value = clean(text);
    if (!active || !value) return value;
    if (cache.has(value)) return cache.get(value);

    let result = dictionary[value];
    if (result === undefined) {
        for (const p of patterns) {
            const m = value.match(p.re);
            if (!m) continue;
            // The values may be French texts themselves (« Total {0} » with
            // « HT »): translated too, numbers and names left as they are.
            const values = {};
            p.order.forEach((n, i) => { values[n] = translate(m[i + 1]); });
            result = p.value.replace(/\{(\d+)\}/g, (_, n) => values[n] ?? '');
            break;
        }
    }
    // Parts joined by « · » (« Sous-module · Les clients »): each part on its own.
    if (result === undefined && value.includes(' · ')) {
        const parts = value.split(' · ');
        const translated = parts.map((part) => translate(part));
        if (translated.some((part, i) => part !== parts[i])) result = translated.join(' · ');
    }
    result ??= value;
    if (cache.size > 5000) cache.clear();
    cache.set(value, result);

    return result;
};

const IGNORED = 'script,style,textarea,code,pre,[data-no-translate],[contenteditable="true"]';
const ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'alt', 'data-confirm', 'data-title'];

// What this script wrote into each node: never translated twice, so a
// translation that happens to be another French text cannot ping-pong.
const written = new WeakMap();

const translateText = (node) => {
    const original = node.nodeValue;
    if (!original || !original.trim()) return;
    if (written.get(node) === original) return;
    if (node.parentElement?.closest(IGNORED)) return;
    const translated = translate(original);
    if (translated === clean(original)) return;
    const leading = original.match(/^[\s ]*/)[0];
    const trailing = original.match(/[\s ]*$/)[0];
    const next = `${leading}${translated}${trailing}`;
    if (next !== original) {
        written.set(node, next);
        node.nodeValue = next;
    }
};

const translateAttributes = (element) => {
    if (element.closest?.(IGNORED)) return;
    for (const attribute of ATTRIBUTES) {
        if (!element.hasAttribute(attribute)) continue;
        const original = element.getAttribute(attribute);
        if (written.get(element)?.[attribute] === original) continue;
        const translated = translate(original);
        if (translated && translated !== clean(original)) {
            written.set(element, { ...(written.get(element) || {}), [attribute]: translated });
            element.setAttribute(attribute, translated);
        }
    }
    // Buttons drawn as inputs carry their label in value.
    if (element.tagName === 'INPUT' && /^(submit|button|reset)$/i.test(element.type)) {
        const translated = translate(element.value);
        if (translated && translated !== clean(element.value)) element.value = translated;
    }
};

export const translateTree = (root) => {
    if (!active || !root) return;
    if (root.nodeType === Node.TEXT_NODE) {
        translateText(root);
        return;
    }
    if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) return;
    const element = root.nodeType === Node.DOCUMENT_NODE ? root.body : root;
    if (!element || element.closest?.(IGNORED)) return;

    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    const texts = [];
    while (walker.nextNode()) texts.push(walker.currentNode);
    texts.forEach(translateText);

    translateAttributes(element);
    element.querySelectorAll(`[${ATTRIBUTES.join('],[')}],input[type=submit],input[type=button]`).forEach(translateAttributes);
};

if (active) {
    // The browser's own dialogs.
    const nativeAlert = window.alert.bind(window);
    const nativeConfirm = window.confirm.bind(window);
    const nativePrompt = window.prompt.bind(window);
    window.alert = (message) => nativeAlert(translate(message));
    window.confirm = (message) => nativeConfirm(translate(message));
    window.prompt = (message, value) => nativePrompt(translate(message), value);

    document.title = translate(document.title);
    translateTree(document);

    // What the page adds or changes later.
    new MutationObserver((records) => {
        for (const record of records) {
            if (record.type === 'characterData') {
                translateText(record.target);
            } else if (record.type === 'attributes') {
                translateAttributes(record.target);
            } else {
                record.addedNodes.forEach(translateTree);
            }
        }
    }).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRIBUTES });
}

window.translate = translate;
window.translateTree = translateTree;

// The page was hidden until translated (see partials/i18n-head), so French
// never flashes before the translation.
document.documentElement.classList.remove('i18n-pending');
