import DataTable from 'datatables.net-dt';
import 'datatables.net-dt/css/dataTables.dataTables.css';

const money = new Intl.NumberFormat('fr-MA', {
    style: 'currency',
    currency: 'MAD',
    currencyDisplay: 'narrowSymbol',
});

const appLocale = document.documentElement.dataset.locale || window.libraireProLocale || 'fr';
const translations = window.libraireProTranslations || {};
const translate = (text) => {
    const value = (text || '').toString().replace(/\s+/g, ' ').trim();
    if (appLocale === 'ar') {
        if (value.startsWith('Bonjour, ')) return `مرحبا، ${value.replace('Bonjour, ', '')}`;
        if (/^\d+ alerte\(s\), \d+ rupture\(s\)$/.test(value)) {
            return value.replace(' alerte(s), ', ' تنبيه، ').replace(' rupture(s)', ' نفاد مخزون');
        }
        if (/^\d+ résultat\(s\)/.test(value)) return value.replace('résultat(s)', 'نتيجة');
        if (/^\+\d+% vs hier$/.test(value)) return value.replace('vs hier', 'مقارنة بأمس');
    }

    return translations[value] || value;
};

const dataTableLanguage = (overrides = {}) => ({
    search: translate('Recherche table'),
    lengthMenu: translate('Afficher _MENU_ lignes'),
    info: translate('Affichage _START_-_END_ sur _TOTAL_'),
    infoEmpty: translate('Aucune ligne'),
    infoFiltered: translate('(filtré depuis _MAX_ lignes)'),
    zeroRecords: translate('Aucune donnée trouvée'),
    processing: translate('Chargement...'),
    emptyTable: translate('Aucune donnée disponible'),
    paginate: {
        first: translate('Début'),
        previous: translate('Précédent'),
        next: translate('Suivant'),
        last: translate('Fin'),
    },
    ...overrides,
});

const translateStaticPage = () => {
    if (appLocale !== 'ar') return;

    const ignored = 'script,style,textarea,code,pre,[data-no-translate],[data-command-search]';
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
            if (!node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
            if (node.parentElement?.closest(ignored)) return NodeFilter.FILTER_REJECT;
            return NodeFilter.FILTER_ACCEPT;
        },
    });

    const textNodes = [];
    while (walker.nextNode()) textNodes.push(walker.currentNode);

    textNodes.forEach((node) => {
        const original = node.nodeValue;
        const leading = original.match(/^\s*/)?.[0] || '';
        const trailing = original.match(/\s*$/)?.[0] || '';
        const translated = translate(original);
        if (translated !== original.trim()) {
            node.nodeValue = `${leading}${translated}${trailing}`;
        }
    });

    document.querySelectorAll('[placeholder],[title],[aria-label]').forEach((element) => {
        ['placeholder', 'title', 'aria-label'].forEach((attribute) => {
            if (!element.hasAttribute(attribute)) return;
            const translated = translate(element.getAttribute(attribute));
            if (translated) element.setAttribute(attribute, translated);
        });
    });
};

document.querySelectorAll('.app-theme-toggle').forEach((button) => {
    button.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
        localStorage.setItem('librairepro-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
    });
});

if (localStorage.getItem('librairepro-theme') === 'dark') {
    document.documentElement.classList.add('dark');
}

document.querySelectorAll('[data-command-menu]').forEach((menu) => {
    const input = menu.querySelector('[data-command-input]');
    const panel = menu.querySelector('[data-command-panel]');
    const empty = menu.querySelector('[data-command-empty]');
    const count = menu.querySelector('[data-command-count]');
    const items = [...menu.querySelectorAll('[data-command-item]')];
    let activeIndex = 0;

    if (!input || !panel) return;

    const normalize = (value) => (value || '')
        .toString()
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9\u0600-\u06ff]+/g, ' ')
        .trim();

    const visibleItems = () => items.filter((item) => !item.classList.contains('is-hidden'));

    const scoreItem = (haystack, tokens, title, label, moduleName, kind) => {
        if (tokens.length === 0) return 1;
        let score = 0;
        const query = tokens.join(' ');

        if (title === query) score += 80;
        if (title.startsWith(query)) score += 35;

        for (const token of tokens) {
            if (!haystack.includes(token)) return -1;
            score += label === token ? 40 : 0;
            score += label.startsWith(token) ? 22 : 0;
            score += moduleName === token ? 18 : 0;
            score += moduleName.startsWith(token) ? 12 : 0;
            score += kind === 'module' ? 3 : 0;
            score += haystack.startsWith(token) ? 8 : 1;
            score += haystack.split(' ').some((part) => part.startsWith(token)) ? 4 : 0;
        }

        return score;
    };

    const setActive = (nextIndex) => {
        const visible = visibleItems();
        if (visible.length === 0) {
            activeIndex = 0;
            items.forEach((item) => item.classList.remove('is-active'));
            return;
        }

        activeIndex = ((nextIndex % visible.length) + visible.length) % visible.length;
        items.forEach((item) => item.classList.remove('is-active'));
        visible[activeIndex].classList.add('is-active');
        visible[activeIndex].scrollIntoView({ block: 'nearest' });
    };

    const open = () => {
        panel.classList.remove('hidden');
        menu.classList.add('is-open');
        setActive(activeIndex);
    };

    const close = () => {
        panel.classList.add('hidden');
        menu.classList.remove('is-open');
        items.forEach((item) => item.classList.remove('is-active'));
    };

    const filter = () => {
        const query = normalize(input.value);
        const tokens = query.split(/\s+/).filter(Boolean);
        const scored = [];

        items.forEach((item) => {
            const haystack = normalize(item.dataset.commandSearch);
            const title = normalize(item.dataset.commandTitle);
            const label = normalize(item.dataset.commandLabel);
            const moduleName = normalize(item.dataset.commandModule);
            const kind = normalize(item.dataset.commandKind);
            const score = scoreItem(haystack, tokens, title, label, moduleName, kind);
            item.dataset.commandScore = String(score);
            item.classList.toggle('is-hidden', score < 0);
            item.classList.remove('is-active');
            if (score >= 0) scored.push(item);
        });

        scored
            .sort((a, b) => Number(b.dataset.commandScore) - Number(a.dataset.commandScore))
            .forEach((item) => item.parentElement?.insertBefore(item, empty || null));

        count && (count.textContent = scored.length.toLocaleString(appLocale === 'ar' ? 'ar-MA' : 'fr-FR'));
        empty?.classList.toggle('hidden', scored.length !== 0);
        setActive(0);
    };

    input.addEventListener('focus', () => {
        filter();
        open();
    });

    input.addEventListener('input', () => {
        filter();
        open();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            input.value = '';
            filter();
            close();
            input.blur();
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            open();
            setActive(activeIndex + 1);
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            open();
            setActive(activeIndex - 1);
            return;
        }

        if (event.key === 'Enter') {
            const target = visibleItems()[activeIndex] || visibleItems()[0];
            if (target) {
                event.preventDefault();
                window.location.assign(target.href);
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            input.focus();
            input.select();
            open();
        }
    });

    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) close();
    });
});

document.querySelectorAll('[data-product-search]').forEach((search) => {
    const endpoint = search.dataset.productSearchUrl;
    const input = search.querySelector('[data-product-search-input]');
    const panel = search.querySelector('[data-product-search-panel]');
    const results = search.querySelector('[data-product-search-results]');
    const empty = search.querySelector('[data-product-search-empty]');
    const count = search.querySelector('[data-product-search-count]');
    let activeIndex = 0;
    let requestId = 0;
    let debounceTimer = null;

    if (!endpoint || !input || !panel || !results) return;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const resultItems = () => [...results.querySelectorAll('[data-product-search-item]')];

    const setActive = (nextIndex) => {
        const items = resultItems();
        if (items.length === 0) {
            activeIndex = 0;
            return;
        }

        activeIndex = ((nextIndex % items.length) + items.length) % items.length;
        items.forEach((item) => item.classList.remove('is-active'));
        items[activeIndex].classList.add('is-active');
        items[activeIndex].scrollIntoView({ block: 'nearest' });
    };

    const open = () => {
        panel.classList.remove('hidden');
        search.classList.add('is-open');
    };

    const close = () => {
        panel.classList.add('hidden');
        search.classList.remove('is-open');
    };

    const renderPrompt = () => {
        count && (count.textContent = '0');
        empty?.classList.add('hidden');
        results.innerHTML = `
            <div class="app-product-search-hint">
                <strong>Recherche catalogue</strong>
                <span>Nom, ISBN, code article, SKU ou code-barres.</span>
            </div>
        `;
        open();
    };

    const render = (items) => {
        count && (count.textContent = items.length.toLocaleString(appLocale === 'ar' ? 'ar-MA' : 'fr-FR'));
        empty?.classList.toggle('hidden', items.length !== 0);

        results.innerHTML = items.map((item) => {
            const stock = item.stock === null || item.stock === undefined ? 'Sans stock' : `Stock ${item.stock}`;
            const visibility = [
                item.is_enabled ? 'Activé' : 'Désactivé',
                item.checkout_visible ? 'Caisse' : 'Hors caisse',
            ].join(' · ');
            const meta = [item.type_label, item.category, item.brand, item.code].filter(Boolean).join(' · ');

            return `
                <a href="${escapeHtml(item.url)}" class="app-product-search-item" data-product-search-item>
                    <span class="app-product-search-avatar">${escapeHtml(String(item.type_label || 'AR').slice(0, 2).toUpperCase())}</span>
                    <span class="min-w-0 flex-1">
                        <strong>${escapeHtml(item.title)}</strong>
                        <small>${escapeHtml(meta || 'Sans référence')}</small>
                    </span>
                    <span class="app-product-search-side">
                        <b>${escapeHtml(item.price)}</b>
                        <small>${escapeHtml(`${stock} · ${visibility}`)}</small>
                    </span>
                </a>
            `;
        }).join('');

        setActive(0);
        open();
    };

    const fetchProducts = async () => {
        const query = input.value.trim();
        const currentRequest = ++requestId;

        if (query === '') {
            renderPrompt();
            return;
        }

        try {
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('q', query);
            const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (currentRequest !== requestId) return;
            render(payload.items || []);
        } catch (error) {
            if (currentRequest !== requestId) return;
            count && (count.textContent = '0');
            results.innerHTML = '';
            empty?.classList.remove('hidden');
            open();
        }
    };

    const scheduleFetch = () => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(fetchProducts, 180);
    };

    input.addEventListener('focus', () => {
        if (input.value.trim() === '') renderPrompt();
        else fetchProducts();
    });

    input.addEventListener('input', scheduleFetch);

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
            input.blur();
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            open();
            setActive(activeIndex + 1);
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            open();
            setActive(activeIndex - 1);
            return;
        }

        if (event.key === 'Enter') {
            const target = resultItems()[activeIndex] || resultItems()[0];
            if (target) {
                event.preventDefault();
                window.location.assign(target.href);
            }
        }
    });

    document.addEventListener('click', (event) => {
        if (!search.contains(event.target)) close();
    });
});

const showToast = (message, actionLabel = null, action = null) => {
    let container = document.querySelector('.app-toast-stack');
    if (!container) {
        container = document.createElement('div');
        container.className = 'app-toast-stack';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = 'app-toast';
    toast.innerHTML = `
        <span>${message}</span>
        ${actionLabel ? `<button class="app-toast-action" type="button">${actionLabel}</button>` : ''}
        <button class="app-toast-close" type="button" aria-label="Fermer">×</button>
    `;
    container.appendChild(toast);

    const close = () => toast.remove();
    toast.querySelector('.app-toast-close')?.addEventListener('click', close);
    toast.querySelector('.app-toast-action')?.addEventListener('click', () => {
        action?.();
        close();
    });
    window.setTimeout(close, 6000);
};

document.querySelectorAll('[data-sidebar]').forEach((sidebar) => {
    const toggle = sidebar.querySelector('[data-sidebar-toggle]');
    const current = sidebar.querySelector('[data-current-nav], [aria-current="page"]');
    const scrollArea = sidebar.querySelector('[data-sidebar-scroll]');
    const groups = [...sidebar.querySelectorAll('[data-sidebar-group]')];
    const openGroups = JSON.parse(localStorage.getItem('librairepro-sidebar-groups') || '[]');
    const setCollapsed = (collapsed) => {
        document.documentElement.classList.toggle('sidebar-collapsed', collapsed);
        localStorage.setItem('librairepro-sidebar', collapsed ? 'collapsed' : 'expanded');
        toggle?.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
        toggle?.setAttribute('aria-label', collapsed ? 'Ouvrir le menu' : 'Réduire le menu');
    };

    const savedSidebarState = localStorage.getItem('librairepro-sidebar');
    setCollapsed(savedSidebarState === null || savedSidebarState === 'collapsed');

    groups.forEach((group) => {
        if (openGroups.includes(group.dataset.sidebarGroup)) {
            group.open = true;
        }

        if (group.querySelector('[data-current-nav], [aria-current="page"]')) {
            group.open = true;
        }

        group.addEventListener('toggle', () => {
            const next = groups.filter((item) => item.open).map((item) => item.dataset.sidebarGroup);
            localStorage.setItem('librairepro-sidebar-groups', JSON.stringify(next));
        });
    });

    toggle?.addEventListener('click', () => {
        setCollapsed(!document.documentElement.classList.contains('sidebar-collapsed'));
    });

    requestAnimationFrame(() => {
        if (!current || !scrollArea || document.documentElement.classList.contains('sidebar-collapsed')) return;
        const currentBox = current.getBoundingClientRect();
        const scrollBox = scrollArea.getBoundingClientRect();
        if (currentBox.top < scrollBox.top + 24 || currentBox.bottom > scrollBox.bottom - 24) {
            current.scrollIntoView({ block: 'center' });
        }
    });
});

translateStaticPage();

const positionSaleActionMenu = (menu) => {
    const summary = menu.querySelector('summary');
    const panel = menu.querySelector('.sale-action-panel');
    if (!summary || !panel || !menu.open) return;

    const summaryBox = summary.getBoundingClientRect();
    const panelBox = panel.getBoundingClientRect();
    const margin = 12;
    const panelWidth = Math.min(panelBox.width || 280, window.innerWidth - margin * 2);
    let left = summaryBox.right - panelWidth;
    left = Math.max(margin, Math.min(left, window.innerWidth - panelWidth - margin));

    let top = summaryBox.bottom + 8;
    const panelHeight = Math.min(panelBox.height || 360, window.innerHeight - margin * 2);
    if (top + panelHeight > window.innerHeight - margin) {
        top = Math.max(margin, summaryBox.top - panelHeight - 8);
    }

    panel.style.setProperty('--sale-menu-left', `${left}px`);
    panel.style.setProperty('--sale-menu-top', `${top}px`);
    panel.style.setProperty('--sale-menu-max-height', `${Math.max(180, window.innerHeight - top - margin)}px`);
};

document.querySelectorAll('.sale-action-menu').forEach((menu) => {
    menu.addEventListener('toggle', () => {
        if (!menu.open) return;
        document.querySelectorAll('.sale-action-menu[open]').forEach((other) => {
            if (other !== menu) other.open = false;
        });
        requestAnimationFrame(() => positionSaleActionMenu(menu));
    });
});

document.addEventListener('click', (event) => {
    document.querySelectorAll('.sale-action-menu[open]').forEach((menu) => {
        if (!menu.contains(event.target)) {
            menu.open = false;
        }
    });
});

['resize', 'scroll'].forEach((eventName) => {
    window.addEventListener(eventName, () => {
        document.querySelectorAll('.sale-action-menu[open]').forEach(positionSaleActionMenu);
    }, { passive: true });
});

document.querySelectorAll('[data-manual-sale-form]').forEach((screen) => {
    const formatMoney = (amount) => money.format(Number.isFinite(amount) ? amount : 0);
    const rows = [...screen.querySelectorAll('.manual-sale-line')];
    const discountInput = screen.querySelector('[data-manual-sale-discount]');
    const chargesInput = screen.querySelector('[data-manual-sale-charges]');
    const paymentInputs = [...screen.querySelectorAll('[data-manual-sale-payment]')];
    const statusInput = screen.querySelector('[data-manual-sale-status]');

    const numberValue = (input) => Math.max(0, Number(input?.value || 0));

    const calculate = () => {
        let subtotal = 0;
        let lineDiscount = 0;
        let taxTotal = 0;

        rows.forEach((row) => {
            const select = row.querySelector('[data-manual-sale-item]');
            const quantityInput = row.querySelector('[data-manual-sale-qty]');
            const priceInput = row.querySelector('[data-manual-sale-price]');
            const discountLineInput = row.querySelector('[data-manual-sale-line-discount]');
            const taxInput = row.querySelector('[data-manual-sale-tax]');
            const selected = select?.selectedOptions?.[0];

            if (selected?.value && !priceInput.value) {
                priceInput.value = Number(selected.dataset.price || 0).toFixed(2);
            }
            if (selected?.value && !taxInput.value) {
                taxInput.value = Number(selected.dataset.tax || 20).toFixed(2);
            }
            if (selected?.value && !quantityInput.value) {
                quantityInput.value = '1';
            }

            const quantity = selected?.value ? Math.max(1, Number(quantityInput.value || 1)) : 0;
            const unitPrice = numberValue(priceInput);
            const grossLine = unitPrice * quantity;
            const discountLine = Math.min(numberValue(discountLineInput), grossLine);
            const netLine = Math.max(0, grossLine - discountLine);
            const taxRate = numberValue(taxInput);
            const tax = taxRate > 0 ? netLine * taxRate / (100 + taxRate) : 0;

            subtotal += grossLine;
            lineDiscount += discountLine;
            taxTotal += tax;
            const totalNode = row.querySelector('[data-manual-sale-line-total]');
            if (totalNode) totalNode.textContent = formatMoney(netLine);
        });

        const globalDiscount = Math.min(numberValue(discountInput), Math.max(0, subtotal - lineDiscount));
        const charges = numberValue(chargesInput);
        const total = Math.max(0, subtotal - lineDiscount - globalDiscount + charges);
        const paid = statusInput?.value === 'unpaid'
            ? 0
            : Math.min(paymentInputs.reduce((sum, input) => sum + numberValue(input), 0), total);

        screen.querySelector('[data-manual-sale-subtotal]').textContent = formatMoney(subtotal);
        screen.querySelector('[data-manual-sale-discount-total]').textContent = formatMoney(lineDiscount + globalDiscount);
        screen.querySelector('[data-manual-sale-tax-total]').textContent = formatMoney(taxTotal);
        screen.querySelector('[data-manual-sale-charges-total]').textContent = formatMoney(charges);
        screen.querySelector('[data-manual-sale-total]').textContent = formatMoney(total);
        screen.querySelector('[data-manual-sale-paid]').textContent = formatMoney(paid);
        screen.querySelector('[data-manual-sale-due]').textContent = formatMoney(Math.max(0, total - paid));
    };

    screen.addEventListener('input', calculate);
    screen.addEventListener('change', calculate);
    calculate();
});

document.querySelectorAll('[data-purchase-item-builder]').forEach((builder) => {
    const lines = builder.querySelector('[data-purchase-item-lines]');
    const template = builder.querySelector('[data-purchase-item-template]');
    const optionsNode = builder.querySelector('[data-purchase-item-options]');
    const searchInput = builder.querySelector('[data-purchase-item-search]');
    const suggestionsNode = builder.querySelector('[data-purchase-item-suggestions]');
    const addMatchButton = builder.querySelector('[data-purchase-item-add-match]');
    const clearButton = builder.querySelector('[data-purchase-item-clear]');
    const countNode = builder.querySelector('[data-purchase-item-count]');
    const stateNode = builder.querySelector('[data-purchase-item-state]');
    const selectedNode = builder.querySelector('[data-purchase-item-selected]');
    const totalNode = builder.querySelector('[data-purchase-item-total]');
    const searchUrl = builder.dataset.purchaseItemSearchUrl;
    let nextIndex = Number(builder.dataset.nextIndex || lines?.querySelectorAll('[data-purchase-item-row]').length || 0);
    let options = [];
    let timer = null;
    let sequence = 0;

    if (!lines || !template || !searchInput || !suggestionsNode) return;

    try {
        options = JSON.parse(optionsNode?.textContent || '[]');
    } catch {
        options = [];
    }

    const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim()
        .toLowerCase();
    const escapeHtml = (value) => String(value || '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    const rows = () => [...lines.querySelectorAll('[data-purchase-item-row]')];
    const selectedIds = () => new Set(rows().map((row) => row.dataset.itemId).filter(Boolean));
    const optionText = (option) => normalize([
        option.text,
        option.title,
        option.code,
        option.category,
        option.brand,
        option.value,
    ].filter(Boolean).join(' '));
    const matchesFor = (query) => {
        const tokens = normalize(query).split(/\s+/).filter(Boolean);
        return options.filter((option) => {
            if (!tokens.length) return true;
            const haystack = optionText(option);
            return tokens.every((token) => haystack.includes(token));
        });
    };
    const mergeServerOptions = (items = []) => {
        items.forEach((item) => {
            const incoming = {
                value: String(item.value),
                title: item.title,
                text: item.text || item.title,
                stock: item.stock,
                code: item.code,
                category: item.category,
                brand: item.brand,
                purchase_price: Number(item.purchase_price || 0),
            };
            const index = options.findIndex((option) => String(option.value) === incoming.value);
            if (index >= 0) {
                options[index] = { ...options[index], ...incoming };
            } else {
                options.push(incoming);
            }
        });
    };
    const focusExisting = (id) => {
        const row = rows().find((line) => line.dataset.itemId === String(id));
        if (!row) return;
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('purchase-item-row-highlight');
        window.setTimeout(() => row.classList.remove('purchase-item-row-highlight'), 1200);
        row.querySelector('[data-purchase-item-quantity]')?.focus();
    };
    const refresh = () => {
        let total = 0;
        rows().forEach((row, index) => {
            row.querySelector('[data-purchase-item-index]').textContent = String(index + 1).padStart(2, '0');
            const quantity = Math.max(0, Number(row.querySelector('[data-purchase-item-quantity]')?.value || 0));
            const cost = Math.max(0, Number(row.querySelector('[data-purchase-item-cost]')?.value || 0));
            const lineTotal = quantity * cost;
            total += lineTotal;
            row.querySelector('[data-purchase-item-line-total]').textContent = money.format(lineTotal);
        });
        if (selectedNode) selectedNode.textContent = rows().length.toLocaleString('fr-FR');
        if (totalNode) totalNode.textContent = money.format(total);
        builder.querySelector('[data-purchase-item-empty]')?.toggleAttribute('hidden', rows().length > 0);
    };
    const renderSuggestions = (matches = []) => {
        const selected = selectedIds();
        const visible = matches.slice(0, 10);
        suggestionsNode.hidden = visible.length === 0;
        if (countNode) countNode.textContent = matches.length.toLocaleString('fr-FR');
        if (stateNode) stateNode.textContent = searchInput.value.trim() ? ' résultat(s)' : ' disponible(s)';
        addMatchButton?.toggleAttribute('disabled', visible.length === 0);
        suggestionsNode.innerHTML = visible.map((option) => {
            const used = selected.has(String(option.value));
            return `
                <button type="button" class="purchase-item-suggestion ${used ? 'is-selected' : ''}" data-value="${escapeHtml(option.value)}">
                    <span>
                        <strong>${escapeHtml(option.title || option.text)}</strong>
                        <small>Stock ${escapeHtml(option.stock ?? '—')} · ${escapeHtml(option.code || 'Sans code')}${option.category ? ` · ${escapeHtml(option.category)}` : ''}${option.brand ? ` · ${escapeHtml(option.brand)}` : ''}</small>
                    </span>
                    <em>${used ? 'Déjà ajouté' : money.format(Number(option.purchase_price || 0))}</em>
                </button>
            `;
        }).join('');
    };
    const search = async (query, useServer = true) => {
        const cleanQuery = String(query || '').trim();
        const localMatches = matchesFor(cleanQuery);
        renderSuggestions(localMatches);

        if (!useServer || !searchUrl) return localMatches;
        const currentSequence = ++sequence;

        window.clearTimeout(timer);
        timer = window.setTimeout(async () => {
            try {
                const url = new URL(searchUrl, window.location.origin);
                if (cleanQuery) url.searchParams.set('q', cleanQuery);
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok || currentSequence !== sequence) return;
                const payload = await response.json();
                mergeServerOptions(payload.items || []);
                renderSuggestions(matchesFor(cleanQuery));
            } catch {
                renderSuggestions(localMatches);
            }
        }, 220);

        return localMatches;
    };
    const addOption = (option) => {
        if (!option?.value) return;
        const id = String(option.value);
        if (selectedIds().has(id)) {
            focusExisting(id);
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.trim().replaceAll('__INDEX__', String(nextIndex));
        nextIndex += 1;
        const row = wrapper.firstElementChild;
        row.dataset.itemId = id;
        row.querySelector('[data-purchase-item-id]').value = id;
        row.querySelector('[data-purchase-item-title]').textContent = option.title || option.text || 'Article';
        row.querySelector('[data-purchase-item-meta]').textContent = `Stock ${option.stock ?? '—'} · ${option.code || 'Sans code'}${option.category ? ` · ${option.category}` : ''}`;
        row.querySelector('[data-purchase-item-cost]').value = Number(option.purchase_price || 0).toFixed(2);

        builder.querySelector('[data-purchase-item-empty]')?.setAttribute('hidden', 'hidden');
        lines.append(row);
        refresh();
        renderSuggestions(matchesFor(searchInput.value));
        row.querySelector('[data-purchase-item-quantity]')?.focus();
    };
    const addFirstMatch = async () => {
        let matches = matchesFor(searchInput.value);
        if (!matches.length && searchInput.value.trim()) {
            matches = await search(searchInput.value, true);
        }
        addOption(matches[0]);
    };

    searchInput.addEventListener('input', () => search(searchInput.value, true));
    searchInput.addEventListener('focus', () => search(searchInput.value, false));
    searchInput.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        addFirstMatch();
    });
    addMatchButton?.addEventListener('click', addFirstMatch);
    clearButton?.addEventListener('click', () => {
        searchInput.value = '';
        searchInput.focus();
        search('', false);
    });
    suggestionsNode.addEventListener('click', (event) => {
        const button = event.target.closest('[data-value]');
        if (!button) return;
        addOption(options.find((option) => String(option.value) === String(button.dataset.value)));
    });
    lines.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-purchase-item-remove]');
        if (!remove) return;
        remove.closest('[data-purchase-item-row]')?.remove();
        refresh();
        renderSuggestions(matchesFor(searchInput.value));
    });
    lines.addEventListener('input', refresh);
    lines.addEventListener('change', refresh);

    search('', false);
    refresh();
});

document.querySelectorAll('.pos-screen').forEach((screen) => {
    const cart = [];
    const cartNode = screen.querySelector('.pos-cart');
    const emptyNode = screen.querySelector('.pos-empty');
    let products = [...screen.querySelectorAll('.pos-product')];
    const search = screen.querySelector('.pos-search');
    const typeFilter = screen.querySelector('.pos-type-filter');
    const stockFilter = screen.querySelector('.pos-stock-filter');
    const categoryFilter = screen.querySelector('.pos-category-filter');
    const brandFilter = screen.querySelector('.pos-brand-filter');
    const unitFilter = screen.querySelector('.pos-unit-filter');
    const checkout = screen.querySelector('[data-pos-checkout]');
    const cartJson = screen.querySelector('.pos-cart-json');
    const submit = screen.querySelector('.pos-submit');
    const cartCount = screen.querySelector('.pos-cart-count');
    const discountInput = screen.querySelector('.pos-discount');
    const paymentInputs = [...screen.querySelectorAll('.pos-payment')];
    const viewButtons = [...screen.querySelectorAll('.pos-view-btn')];
    const productsGrid = screen.querySelector('.pos-products');
    const columnsInput = screen.querySelector('.pos-grid-columns');
    const submitLabel = screen.querySelector('.pos-submit-label');
    const suggestionButtons = [...screen.querySelectorAll('.pos-suggestion-btn')];
    const itemDialog = screen.querySelector('.pos-item-dialog');
    const initialProductsHtml = productsGrid?.innerHTML || '';
    const dialogTitle = itemDialog?.querySelector('.pos-dialog-title');
    const dialogMeta = itemDialog?.querySelector('.pos-dialog-meta');
    const dialogQuantity = itemDialog?.querySelector('.pos-dialog-quantity');
    const dialogPrice = itemDialog?.querySelector('.pos-dialog-price');
    const dialogNote = itemDialog?.querySelector('.pos-dialog-note');
    const stockDialog = screen.querySelector('.pos-stock-dialog');
    const stockDialogTitle = stockDialog?.querySelector('.pos-stock-dialog-title');
    const stockDialogMeta = stockDialog?.querySelector('.pos-stock-dialog-meta');
    const stockDialogStock = stockDialog?.querySelector('.pos-stock-dialog-stock');
    const stockDialogCode = stockDialog?.querySelector('.pos-stock-dialog-code');
    const stockDialogPrice = stockDialog?.querySelector('.pos-stock-dialog-price');
    const stockDialogLink = stockDialog?.querySelector('.pos-stock-dialog-link');
    const priceEditable = screen.dataset.priceEditable === '1';
    const allowOversell = screen.dataset.allowOversell === '1';
    const showOutOfStock = screen.dataset.showOutOfStock === '1';
    const searchUrl = screen.dataset.posSearchUrl;
    const searchState = screen.querySelector('.pos-search-state');
    const submitButtons = [...screen.querySelectorAll('button[type="submit"]')];
    let activeSubmitter = null;
    let submitting = false;
    let suggestionMode = 'all';
    let favoriteIds = JSON.parse(localStorage.getItem('librairepro-pos-favorites') || '[]').map(Number);
    let searchTimer = null;
    let searchSequence = 0;

    const normalizeText = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const setSearchState = (message) => {
        if (searchState) searchState.textContent = message;
    };

    const setProductView = (view) => {
        if (!productsGrid) return;
        productsGrid.dataset.view = view;
        localStorage.setItem('librairepro-pos-view', view);
        viewButtons.forEach((button) => button.classList.toggle('is-active', button.dataset.view === view));
    };

    const refreshFavorites = () => {
        products.forEach((product) => {
            const active = favoriteIds.includes(Number(product.dataset.id));
            product.dataset.favorite = active ? '1' : '0';
            const favoriteButton = product.querySelector('.pos-favorite-star');
            favoriteButton?.classList.toggle('is-active', active);
            favoriteButton?.setAttribute('aria-pressed', active ? 'true' : 'false');
            favoriteButton?.setAttribute('title', active ? 'Retirer des favoris' : 'Ajouter aux favoris');
        });
    };

    const productCardHtml = (item) => {
        const stock = Number(item.stock || 0);
        const lowThreshold = Number(item.low_threshold || 3);
        const isService = item.type === 'service';
        const isOutOfStock = Boolean(item.out_of_stock);
        const isSellable = Boolean(item.sellable);
        const statusTone = isOutOfStock ? 'danger' : (isService ? 'info' : (stock <= lowThreshold ? 'warning' : 'success'));
        const statusLabel = isOutOfStock ? 'Rupture' : (isService ? 'Service' : stock);
        const statusClasses = {
            success: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
            warning: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
            danger: 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
            info: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20',
        }[statusTone];
        const initials = escapeHtml(String(item.name || 'AR').slice(0, 2).toUpperCase());
        const image = item.image_url
            ? `<img src="${escapeHtml(item.image_url)}" alt="" class="size-12 rounded-lg object-cover">`
            : `<div class="grid size-12 place-items-center rounded-lg bg-slate-100 text-sm font-bold text-slate-500 dark:bg-white/10">${initials}</div>`;
        const soldBadge = Number(item.sold || 0) > 0
            ? `<span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500 dark:bg-white/10">${Number(item.sold || 0)} vendus</span>`
            : '';
        const unavailable = !isSellable
            ? '<p class="mt-2 rounded-lg bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 ring-1 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20">Non vendable · cliquer pour gérer le stock</p>'
            : '';

        return `
            <article class="pos-product pos-item pos-product-card rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-brand hover:shadow-md dark:border-white/10 dark:bg-white/[0.03] ${isSellable ? '' : 'opacity-60 grayscale hover:translate-y-0 hover:border-rose-200 hover:shadow-sm dark:hover:border-rose-500/30'}"
                role="button"
                tabindex="0"
                aria-disabled="${isSellable ? 'false' : 'true'}"
                data-id="${escapeHtml(item.id)}"
                data-name="${escapeHtml(item.name)}"
                data-price="${escapeHtml(item.price)}"
                data-stock="${escapeHtml(stock)}"
                data-sellable="${isSellable ? '1' : '0'}"
                data-stock-url="${escapeHtml(item.stock_url || '')}"
                data-low-threshold="${escapeHtml(lowThreshold)}"
                data-type="${escapeHtml(item.type || 'book')}"
                data-category-id="${escapeHtml(item.category_id || '')}"
                data-brand-id="${escapeHtml(item.brand_id || '')}"
                data-unit-id="${escapeHtml(item.unit_id || '')}"
                data-sold="${escapeHtml(item.sold || 0)}"
                data-barcode="${escapeHtml(item.barcode || '')}"
                data-search="${escapeHtml(normalizeText(item.search || `${item.name} ${item.barcode || ''} ${item.category_name || ''} ${item.brand_name || ''} ${item.unit_name || ''}`))}">
                <div class="pos-product-top flex items-start justify-between gap-3">
                    ${image}
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${statusClasses}">${escapeHtml(statusLabel)}</span>
                </div>
                <div class="pos-product-name mt-3 flex items-start gap-2">
                    <button class="pos-favorite-star text-base text-slate-300" data-product-id="${escapeHtml(item.id)}" type="button" aria-label="Basculer favori" title="Basculer favori">★</button>
                    <p class="line-clamp-2 min-h-10 text-sm font-semibold">${escapeHtml(item.name)}</p>
                </div>
                <p class="pos-product-meta mt-2 truncate text-xs text-slate-500">${escapeHtml(item.category_name || 'Sans catégorie')} · ${escapeHtml(item.barcode || 'Sans code')}</p>
                ${unavailable}
                <div class="pos-product-footer mt-3 flex items-center justify-between gap-2">
                    <p class="text-lg font-semibold">${money.format(Number(item.price || 0))}</p>
                    ${soldBadge}
                </div>
            </article>
        `;
    };

    const setSuggestionMode = (mode) => {
        suggestionMode = mode;
        suggestionButtons.forEach((button) => button.classList.toggle('is-active', button.dataset.suggest === mode));
        filterProducts();
    };

    const setProductColumns = (columns) => {
        if (!productsGrid) return;
        productsGrid.style.setProperty('--pos-columns', String(columns));
        localStorage.setItem('librairepro-pos-columns', String(columns));
        if (columnsInput) columnsInput.value = String(columns);
    };

    const totals = () => {
        const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
        const discount = Math.min(Number(discountInput?.value || 0), subtotal);
        const total = Math.max(0, subtotal - discount);
        const paid = paymentInputs.reduce((sum, input) => sum + Number(input.value || 0), 0);
        return { subtotal, discount, total, paid, tax: total * 0.2 / 1.2, remaining: Math.max(0, total - paid), change: Math.max(0, paid - total) };
    };

    const stockLabel = (item) => item.stock >= 999999 ? 'illimité' : item.stock;

    const canExceedStock = (item) => allowOversell || item.type === 'service' || item.stock >= 999999;

    const normalizeQuantity = (item, quantity, warn = false) => {
        const requested = Math.max(1, Number(quantity || 1));
        if (canExceedStock(item)) return requested;

        const limited = Math.min(item.stock, requested);
        if (warn && requested > limited) {
            showToast(`Stock disponible atteint pour ${item.name}: ${stockLabel(item)} unité(s).`);
        }

        return limited;
    };

    const renderCart = () => {
        if (!cartNode) return;

        cartNode.innerHTML = cart.map((item, index) => `
            <div class="pos-cart-line rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-slate-950/60" data-index="${index}">
                <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-3">
                    <div class="min-w-0">
                        <p class="pos-cart-title">${item.name}</p>
                        <p class="pos-cart-meta">${item.barcode || 'Sans code'} · ${money.format(item.price)} · stock ${stockLabel(item)}${item.note ? ` · ${item.note}` : ''}</p>
                    </div>
                    <div class="flex items-center gap-1">
                        <button class="pos-cart-edit grid size-8 place-items-center rounded-md bg-slate-100 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-slate-200" data-index="${index}" type="button" title="Modifier">✎</button>
                        <button class="pos-remove grid size-8 place-items-center rounded-md bg-rose-50 text-base font-bold text-rose-600" data-index="${index}" type="button">×</button>
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between gap-3">
                    <div class="flex items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 dark:border-white/10 dark:bg-white/5">
                        <button class="pos-qty h-8 w-8 text-sm font-bold" data-index="${index}" data-delta="-1" type="button">-</button>
                        <input class="pos-qty-input h-8 w-12 border-x border-slate-200 text-center text-sm font-semibold dark:border-white/10" data-index="${index}" value="${item.quantity}" inputmode="numeric">
                        <button class="pos-qty h-8 w-8 text-sm font-bold" data-index="${index}" data-delta="1" type="button">+</button>
                    </div>
                    <div class="text-right">
                        <span class="block text-[11px] font-semibold uppercase tracking-normal text-slate-500">Ligne</span>
                        <strong class="text-base">${money.format(item.price * item.quantity)}</strong>
                    </div>
                </div>
            </div>
        `).join('');

        emptyNode?.classList.toggle('hidden', cart.length > 0);
        if (cartCount) {
            cartCount.textContent = String(cart.length);
        }
        if (cartJson) {
            cartJson.value = JSON.stringify(cart.map(({ id, quantity, price, originalPrice, note }) => ({ id, quantity, price, original_price: originalPrice, note })));
        }

        const total = totals();
        screen.querySelector('.pos-subtotal').textContent = money.format(total.subtotal);
        screen.querySelector('.pos-tax').textContent = money.format(total.tax);
        screen.querySelector('.pos-discount-label').textContent = money.format(total.discount);
        screen.querySelector('.pos-total').textContent = money.format(total.total);
        screen.querySelector('.pos-remaining').textContent = money.format(total.remaining);
        screen.querySelector('.pos-change').textContent = money.format(total.change);
        if (submit) {
            const blocked = cart.length === 0 || total.paid + 0.001 < total.total;
            submit.disabled = false;
            submit.classList.toggle('is-blocked', blocked);
            submit.setAttribute('aria-disabled', blocked ? 'true' : 'false');
        }
        if (submitLabel) {
            submitLabel.textContent = cart.length === 0
                ? 'Panier vide'
                : (total.paid + 0.001 < total.total ? `Reste ${money.format(total.remaining)}` : `Encaisser ${money.format(total.total)}`);
        }
    };

    const addProduct = (button) => {
        if (button.dataset.sellable === '0') {
            openStockDialog(button);
            return;
        }

        const id = Number(button.dataset.id || 0);
        const stock = Number(button.dataset.stock || 0);
        const existing = cart.find((item) => item.id === id);
        if (existing) {
            const nextQuantity = normalizeQuantity(existing, existing.quantity + 1, true);
            existing.quantity = nextQuantity;
        } else {
            cart.push({
                id,
                name: button.dataset.name,
                price: Number(button.dataset.price || 0),
                originalPrice: Number(button.dataset.price || 0),
                stock,
                type: button.dataset.type || 'book',
                barcode: button.dataset.barcode || '',
                quantity: 1,
                note: '',
            });
        }
        renderCart();
    };

    const openStockDialog = (button) => {
        if (!stockDialog || !button) return;

        const stock = Number(button.dataset.stock || 0);
        const barcode = button.dataset.barcode || 'Sans code';
        if (stockDialogTitle) stockDialogTitle.textContent = button.dataset.name || 'Article';
        if (stockDialogMeta) stockDialogMeta.textContent = `${barcode} · stock ${stockLabel({ stock })}`;
        if (stockDialogStock) stockDialogStock.textContent = stockLabel({ stock });
        if (stockDialogCode) stockDialogCode.textContent = barcode;
        if (stockDialogPrice) stockDialogPrice.textContent = money.format(Number(button.dataset.price || 0));
        if (stockDialogLink && button.dataset.stockUrl) stockDialogLink.href = button.dataset.stockUrl;
        stockDialog.showModal();
    };

    const openItemDialog = (item, index = null) => {
        if (!itemDialog || !item) return;
        itemDialog.dataset.mode = index === null ? 'add' : 'edit';
        itemDialog.dataset.index = index === null ? '' : String(index);
        itemDialog.dataset.productId = String(item.id);
        itemDialog.dataset.productName = item.name;
        itemDialog.dataset.productBarcode = item.barcode || '';
        itemDialog.dataset.productStock = String(item.stock);
        itemDialog.dataset.productType = item.type || 'book';
        itemDialog.dataset.productOriginalPrice = String(item.originalPrice ?? item.price);
        if (dialogTitle) dialogTitle.textContent = item.name;
        if (dialogMeta) dialogMeta.textContent = `${item.barcode || 'Sans code'} · stock ${stockLabel(item)}${canExceedStock(item) ? ' · hors stock autorisé' : ''}`;
        if (dialogQuantity) dialogQuantity.value = String(item.quantity || 1);
        if (dialogPrice) {
            dialogPrice.value = Number(item.price || 0).toFixed(2);
            dialogPrice.disabled = !priceEditable;
        }
        if (dialogNote) dialogNote.value = item.note || '';
        itemDialog.showModal();
        window.setTimeout(() => dialogQuantity?.focus(), 40);
    };

    const productFromButton = (button) => ({
        id: Number(button.dataset.id || 0),
        name: button.dataset.name,
        price: Number(button.dataset.price || 0),
        originalPrice: Number(button.dataset.price || 0),
        stock: Number(button.dataset.stock || 0),
        type: button.dataset.type || 'book',
        barcode: button.dataset.barcode || '',
        quantity: 1,
        note: '',
    });

    const applyDialogItem = () => {
        if (!itemDialog || !dialogQuantity || !dialogPrice) return;
        const mode = itemDialog.dataset.mode;
        const dialogItem = {
            name: itemDialog.dataset.productName,
            stock: Number(itemDialog.dataset.productStock || 0),
            type: itemDialog.dataset.productType || 'book',
        };
        const quantity = normalizeQuantity(dialogItem, Number(dialogQuantity.value || 1), true);
        const originalPrice = Number(itemDialog.dataset.productOriginalPrice || 0);
        const price = priceEditable ? Math.max(0, Number(dialogPrice.value || 0)) : originalPrice;
        const note = dialogNote?.value?.trim() || '';

        if (mode === 'edit') {
            const item = cart[Number(itemDialog.dataset.index)];
            if (!item) return;
            item.quantity = quantity;
            item.price = price;
            item.note = note;
        } else {
            cart.push({
                id: Number(itemDialog.dataset.productId || 0),
                name: itemDialog.dataset.productName,
                barcode: itemDialog.dataset.productBarcode || '',
                stock: Number(itemDialog.dataset.productStock || 0),
                type: itemDialog.dataset.productType || 'book',
                originalPrice,
                price,
                quantity,
                note,
            });
        }

        itemDialog.close();
        renderCart();
    };

    const filterProducts = () => {
        const query = normalizeText(search?.value || '');
        const queryTokens = query.split(/\s+/).filter(Boolean);
        const type = typeFilter?.value || 'all';
        const stock = stockFilter?.value || 'available';
        const category = categoryFilter?.value || 'all';
        const brand = brandFilter?.value || 'all';
        const unit = unitFilter?.value || 'all';
        let visible = 0;

        products.forEach((product) => {
            const searchable = normalizeText(product.dataset.search || '');
            const barcode = normalizeText(product.dataset.barcode || '');
            const matchesQuery = !query || barcode === query || queryTokens.every((token) => searchable.includes(token) || barcode.includes(token));
            const matchesType = type === 'all' || product.dataset.type === type;
            const matchesCategory = category === 'all' || product.dataset.categoryId === category;
            const matchesBrand = brand === 'all' || product.dataset.brandId === brand;
            const matchesUnit = unit === 'all' || product.dataset.unitId === unit;
            const productStock = Number(product.dataset.stock || 0);
            const lowThreshold = Number(product.dataset.lowThreshold || 3);
            const matchesStock = stock === 'all' || (stock === 'available' && (allowOversell || showOutOfStock || productStock > 0 || product.dataset.type === 'service')) || (stock === 'low' && product.dataset.type !== 'service' && productStock > 0 && productStock <= lowThreshold);
            const matchesSuggestion = suggestionMode === 'all'
                || (suggestionMode === 'favorites' && product.dataset.favorite === '1')
                || (suggestionMode === 'top' && Number(product.dataset.sold || 0) > 0);
            const show = matchesQuery && matchesType && matchesCategory && matchesBrand && matchesUnit && matchesStock && matchesSuggestion;
            product.classList.toggle('hidden', !show);
            if (show) visible += 1;
        });

        screen.querySelector('.pos-visible-count').textContent = String(visible);
        screen.querySelector('.pos-empty-products')?.classList.toggle('hidden', visible > 0);
        return visible;
    };

    // Removed server search - POS system should use instant client-side filtering only
    // All products are already loaded (300 items), so no server request needed

    const addBySearch = () => {
        const query = (search?.value || '').trim().toLowerCase();
        if (!query) return false;
        const exactUnavailable = products.find((product) => product.dataset.sellable === '0' && product.dataset.barcode?.toLowerCase() === query);
        if (exactUnavailable) {
            openStockDialog(exactUnavailable);
            return true;
        }

        const exact = products.find((product) => product.dataset.sellable !== '0' && product.dataset.barcode?.toLowerCase() === query);
        if (exact) {
            addProduct(exact);
            search.value = '';
            filterProducts();
            return true;
        }
        const unavailableVisibleProducts = products.filter((product) => !product.classList.contains('hidden') && product.dataset.sellable === '0');
        if (unavailableVisibleProducts.length === 1) {
            openStockDialog(unavailableVisibleProducts[0]);
            return true;
        }

        const visibleProducts = products.filter((product) => !product.classList.contains('hidden') && product.dataset.sellable !== '0');
        if (visibleProducts.length === 1) {
            addProduct(visibleProducts[0]);
            search.value = '';
            filterProducts();
            return true;
        }
        return false;
    };

    const bindProductInteractions = (button) => {
        if (button.dataset.bound === '1') return;
        button.dataset.bound = '1';
        let pressTimer = null;
        let longPress = false;

        button.addEventListener('pointerdown', () => {
            longPress = false;
            pressTimer = window.setTimeout(() => {
                longPress = true;
                if (button.dataset.sellable === '0') {
                    openStockDialog(button);
                    return;
                }
                openItemDialog(productFromButton(button));
            }, 520);
        });
        ['pointerup', 'pointerleave', 'pointercancel'].forEach((eventName) => {
            button.addEventListener(eventName, () => {
                window.clearTimeout(pressTimer);
            });
        });
        button.addEventListener('click', (event) => {
            if (event.target.closest('.pos-favorite-star')) {
                return;
            }
            if (longPress) {
                event.preventDefault();
                return;
            }
            if (button.dataset.sellable === '0') {
                event.preventDefault();
                openStockDialog(button);
                return;
            }
            addProduct(button);
        });
        button.addEventListener('keydown', (event) => {
            if (!['Enter', ' '].includes(event.key) || event.target.closest('.pos-favorite-star')) {
                return;
            }

            event.preventDefault();
            if (button.dataset.sellable === '0') {
                openStockDialog(button);
                return;
            }
            addProduct(button);
        });
    };

    const hydrateProducts = () => {
        products = [...screen.querySelectorAll('.pos-product')];
        products.forEach(bindProductInteractions);
        refreshFavorites();
        filterProducts();
    };

    const fetchServerProducts = async () => {
        if (!searchUrl || !productsGrid || !search) return;
        const query = search.value.trim();
        const sequence = ++searchSequence;

        if (query === '') {
            if (productsGrid && initialProductsHtml) {
                productsGrid.innerHTML = initialProductsHtml;
                hydrateProducts();
            }
            setSearchState('Favoris et plus vendus.');
            filterProducts();
            return;
        }

        const url = new URL(searchUrl, window.location.origin);
        url.searchParams.set('q', query);
        url.searchParams.set('type', typeFilter?.value || 'all');
        url.searchParams.set('stock', stockFilter?.value || 'available');
        url.searchParams.set('category', categoryFilter?.value || 'all');
        url.searchParams.set('brand', brandFilter?.value || 'all');
        url.searchParams.set('unit', unitFilter?.value || 'all');

        setSearchState('Recherche en cours...');
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Search failed');
            const payload = await response.json();
            if (sequence !== searchSequence) return;
            productsGrid.innerHTML = (payload.items || []).map(productCardHtml).join('');
            setSearchState(`${payload.count || 0} résultat(s) serveur.`);
            hydrateProducts();
        } catch {
            if (sequence !== searchSequence) return;
            setSearchState('Recherche locale uniquement.');
            filterProducts();
        }
    };

    products.forEach(bindProductInteractions);
    search?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        filterProducts();
        searchTimer = window.setTimeout(fetchServerProducts, 220);
    });
    search?.addEventListener('keydown', async (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (!addBySearch()) {
                window.clearTimeout(searchTimer);
                await fetchServerProducts();
                addBySearch();
            }
        }
    });
    const refilterProducts = () => {
        filterProducts();
        if ((search?.value || '').trim()) {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(fetchServerProducts, 120);
        }
    };
    typeFilter?.addEventListener('change', refilterProducts);
    stockFilter?.addEventListener('change', refilterProducts);
    categoryFilter?.addEventListener('change', refilterProducts);
    brandFilter?.addEventListener('change', refilterProducts);
    unitFilter?.addEventListener('change', refilterProducts);

    screen.addEventListener('click', (event) => {
        const favorite = event.target.closest('.pos-favorite-star');
        if (favorite) {
            event.preventDefault();
            event.stopPropagation();
            const id = Number(favorite.dataset.productId);
            favoriteIds = favoriteIds.includes(id) ? favoriteIds.filter((item) => item !== id) : [...favoriteIds, id];
            localStorage.setItem('librairepro-pos-favorites', JSON.stringify(favoriteIds));
            refreshFavorites();
            filterProducts();
            return;
        }

        const remove = event.target.closest('.pos-remove');
        if (remove) {
            cart.splice(Number(remove.dataset.index), 1);
            renderCart();
        }

        const edit = event.target.closest('.pos-cart-edit');
        if (edit) {
            openItemDialog(cart[Number(edit.dataset.index)], Number(edit.dataset.index));
        }

        const qty = event.target.closest('.pos-qty');
        if (qty) {
            const item = cart[Number(qty.dataset.index)];
            if (!item) return;
            item.quantity = normalizeQuantity(item, item.quantity + Number(qty.dataset.delta), true);
            renderCart();
        }
    });

    screen.addEventListener('dblclick', (event) => {
        const line = event.target.closest('.pos-cart-line');
        if (line) openItemDialog(cart[Number(line.dataset.index)], Number(line.dataset.index));
    });

    itemDialog?.querySelector('.pos-dialog-save')?.addEventListener('click', applyDialogItem);
    itemDialog?.querySelectorAll('.dialog-close').forEach((button) => {
        button.addEventListener('click', () => itemDialog.close());
    });
    stockDialog?.querySelectorAll('.dialog-close').forEach((button) => {
        button.addEventListener('click', () => stockDialog.close());
    });

    screen.addEventListener('input', (event) => {
        const input = event.target.closest('.pos-qty-input');
        if (input) {
            const item = cart[Number(input.dataset.index)];
            if (!item) return;
            item.quantity = normalizeQuantity(item, Number(input.value || 1), true);
            renderCart();
        }
        if (event.target.matches('.pos-payment, .pos-discount')) {
            renderCart();
        }
    });

    const setCheckoutBusy = (busy, submitter = null) => {
        submitting = busy;
        submitButtons.forEach((button) => {
            button.disabled = busy;
        });

        if (busy && submitter) {
            submitter.dataset.originalText = submitter.textContent.trim();
            submitter.textContent = 'Traitement...';
        }
    };

    screen.querySelector('.pos-clear')?.addEventListener('click', () => {
        if (cart.length === 0) {
            showToast('Le panier est déjà vide.');
            return;
        }

        const previousCart = cart.map((item) => ({ ...item }));
        cart.splice(0, cart.length);
        renderCart();
        showToast('Panier vidé.', 'Annuler', () => {
            cart.splice(0, cart.length, ...previousCart);
            renderCart();
        });
    });

    screen.querySelector('.pos-clear-filters')?.addEventListener('click', () => {
        if (search) search.value = '';
        if (typeFilter) typeFilter.value = 'all';
        if (stockFilter) stockFilter.value = 'available';
        if (categoryFilter) categoryFilter.value = 'all';
        if (brandFilter) brandFilter.value = 'all';
        if (unitFilter) unitFilter.value = 'all';
        setSuggestionMode('all');
        filterProducts();
        search?.focus();
    });

    screen.querySelector('.pos-fill-cash')?.addEventListener('click', () => {
        const total = totals();
        screen.querySelector('input[name="cash_amount"]').value = total.total.toFixed(2);
        screen.querySelector('input[name="card_amount"]').value = '';
        screen.querySelector('input[name="transfer_amount"]').value = '';
        renderCart();
    });

    screen.querySelector('.pos-fill-card')?.addEventListener('click', () => {
        const total = totals();
        screen.querySelector('input[name="card_amount"]').value = total.total.toFixed(2);
        screen.querySelector('input[name="cash_amount"]').value = '';
        screen.querySelector('input[name="transfer_amount"]').value = '';
        renderCart();
    });

    screen.querySelector('.pos-exact-split')?.addEventListener('click', () => {
        const total = totals();
        const firstHalf = Math.floor(total.total * 100 / 2) / 100;
        screen.querySelector('input[name="cash_amount"]').value = firstHalf.toFixed(2);
        screen.querySelector('input[name="card_amount"]').value = (total.total - firstHalf).toFixed(2);
        screen.querySelector('input[name="transfer_amount"]').value = '';
        renderCart();
    });

    checkout?.addEventListener('submit', (event) => {
        const submitter = event.submitter || activeSubmitter;
        renderCart();

        if (submitting) {
            event.preventDefault();
            return;
        }

        if (cart.length === 0) {
            event.preventDefault();
            showToast('Ajoutez au moins un article avant de valider.');
            search?.focus();
            return;
        }

        if (submitter?.classList.contains('pos-hold-submit')) {
            setCheckoutBusy(true, submitter);
            return;
        }

        const total = totals();
        if (total.paid + 0.001 < total.total) {
            event.preventDefault();
            showToast(`Paiement insuffisant. Reste ${money.format(total.remaining)}.`);
            screen.querySelector('input[name="cash_amount"]')?.focus();
            return;
        }

        setCheckoutBusy(true, submitter);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'F2') {
            event.preventDefault();
            search?.focus();
        }
        if (event.key === 'F4') {
            event.preventDefault();
            screen.querySelector('input[name="cash_amount"]')?.focus();
        }
    });

    viewButtons.forEach((button) => button.addEventListener('click', () => setProductView(button.dataset.view)));
    suggestionButtons.forEach((button) => button.addEventListener('click', () => setSuggestionMode(button.dataset.suggest)));
    submitButtons.forEach((button) => button.addEventListener('click', () => {
        activeSubmitter = button;
    }));
    columnsInput?.addEventListener('input', () => setProductColumns(columnsInput.value));
    setProductView(localStorage.getItem('librairepro-pos-view') || 'compact');
    setProductColumns(localStorage.getItem('librairepro-pos-columns') || columnsInput?.value || 4);
    refreshFavorites();
    screen.querySelectorAll('.pos-print-ticket, .pos-print-pdf').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });

    filterProducts();
    try {
        const resumeCart = JSON.parse(screen.dataset.resumeCart || '[]');
        resumeCart.forEach((line) => {
            const product = products.find((item) => Number(item.dataset.id) === Number(line.item_id || line.id));
            const quantity = Math.max(1, Number(line.quantity || 1));
            if (product) {
                addProduct(product);
                const added = cart.find((item) => item.id === Number(product.dataset.id));
                if (added) {
                    added.quantity = Math.min(added.stock, quantity);
                    added.quantity = normalizeQuantity(added, quantity, false);
                    added.price = Number(line.price || line.unit_price || product.dataset.price || added.price);
                    added.originalPrice = Number(line.original_price || product.dataset.price || added.originalPrice);
                    added.note = line.note || '';
                }
            }
        });
    } catch {
        // Ignore malformed resume payloads; the server still owns the ticket.
    }
    renderCart();
});

document.querySelectorAll('.catalog-check-all').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
        document.querySelectorAll('.catalog-item-check').forEach((item) => {
            item.checked = checkbox.checked;
        });
    });
});

document.querySelectorAll('.catalog-labels').forEach((button) => {
    button.addEventListener('click', () => {
        const ids = [...document.querySelectorAll('.catalog-item-check:checked')].map((checkbox) => checkbox.value);
        const url = new URL('/catalogue/etiquettes', window.location.origin);
        if (ids.length > 0) {
            url.searchParams.set('items', ids.join(','));
        }
        window.location.href = url.toString();
    });
});

document.querySelectorAll('.label-select-all').forEach((button) => {
    button.addEventListener('click', () => {
        const form = button.closest('form');
        const checks = [...(form?.querySelectorAll('[data-label-row]:not([hidden]) .label-item-check') || [])];
        const shouldCheck = checks.some((check) => !check.checked);
        checks.forEach((check) => {
            check.checked = shouldCheck;
        });
        button.textContent = shouldCheck ? 'Tout désélectionner' : 'Tout sélectionner';
        updateLabelSelection(form);
    });
});

function updateLabelSelection(form) {
    if (!form) return;

    const checked = [...form.querySelectorAll('.label-item-check:checked')];
    const selectedCounts = [...form.querySelectorAll('.label-selected-count')];
    const totalCounts = [...form.querySelectorAll('.label-total-count')];
    const selectAll = form.querySelector('.label-select-all');
    const allChecks = [...form.querySelectorAll('[data-label-row]:not([hidden]) .label-item-check')];
    const visibleRows = [...form.querySelectorAll('[data-label-row]:not([hidden])')];
    const visibleCount = form.querySelector('[data-label-visible-count]');
    const total = checked.reduce((sum, checkbox) => {
        const quantity = form.querySelector(`[name="quantities[${checkbox.value}]"]`);
        return sum + Math.max(1, Number(quantity?.value || 1));
    }, 0);

    selectedCounts.forEach((node) => {
        node.textContent = String(checked.length);
    });
    totalCounts.forEach((node) => {
        node.textContent = String(total);
    });
    if (visibleCount) visibleCount.textContent = String(visibleRows.length);
    if (selectAll) {
        selectAll.textContent = allChecks.length > 0 && allChecks.every((check) => check.checked)
            ? 'Tout désélectionner'
            : 'Tout sélectionner';
    }
}

document.querySelectorAll('.label-workbench').forEach((form) => {
    const normalizeLabelSearch = (value) => (value || '')
        .toString()
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .trim();
    const searchInput = form.querySelector('[data-label-search-input]');

    const refreshTemplateOptions = () => {
        form.querySelectorAll('[data-label-template-option]').forEach((option) => {
            const input = option.querySelector('input[type="radio"]');
            option.classList.toggle('is-active', Boolean(input?.checked));
        });
    };

    const filterLoadedRows = () => {
        const query = normalizeLabelSearch(searchInput?.value || '');
        const tokens = query.split(/\s+/).filter(Boolean);

        form.querySelectorAll('[data-label-row]').forEach((row) => {
            const haystack = normalizeLabelSearch(row.dataset.labelSearch || row.textContent);
            row.hidden = tokens.length > 0 && !tokens.every((token) => haystack.includes(token));
        });

        updateLabelSelection(form);
    };

    updateLabelSelection(form);
    refreshTemplateOptions();

    form.querySelectorAll('[data-label-row]').forEach((row) => {
        row.addEventListener('click', (event) => {
            if (event.target.closest('input, button, select, textarea, a')) return;

            const checkbox = row.querySelector('.label-item-check');
            if (!checkbox) return;

            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    form.querySelectorAll('.label-item-check, .label-quantity').forEach((input) => {
        input.addEventListener('change', () => updateLabelSelection(form));
        input.addEventListener('input', () => updateLabelSelection(form));
    });

    form.querySelectorAll('[data-label-template-option] input[type="radio"]').forEach((input) => {
        input.addEventListener('change', refreshTemplateOptions);
    });

    searchInput?.addEventListener('input', filterLoadedRows);
});

document.querySelectorAll('[data-yajra-table]').forEach((table) => {
    const panel = table.dataset.panel || 'articles';
    const hasAlertColumn = panel !== 'services';

    const baseColumns = [
        { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false },
        { data: 'image', name: 'image', orderable: false, searchable: false },
        { data: 'barcode', name: 'barcode', orderable: true, searchable: true },
        { data: 'title', name: 'title', orderable: true, searchable: true },
        { data: 'category_type', name: 'category_type', orderable: false, searchable: true },
        { data: 'unit_label', name: 'unit_label', orderable: false, searchable: true },
        { data: 'stock_quantity', name: 'stock_quantity', orderable: true, searchable: false },
    ];

    const columns = hasAlertColumn
        ? [
            ...baseColumns,
            { data: 'min_stock_threshold', name: 'min_stock_threshold', orderable: true, searchable: false },
            { data: 'sale_price', name: 'sale_price', orderable: true, searchable: false },
            { data: 'tax_label', name: 'tax_label', orderable: false, searchable: true },
            { data: 'status', name: 'status', orderable: true, searchable: false },
            { data: 'action', name: 'action', orderable: false, searchable: false },
        ]
        : [
            ...baseColumns,
            { data: 'sale_price', name: 'sale_price', orderable: true, searchable: false },
            { data: 'tax_label', name: 'tax_label', orderable: false, searchable: true },
            { data: 'status', name: 'status', orderable: true, searchable: false },
            { data: 'action', name: 'action', orderable: false, searchable: false },
        ];

    new DataTable(table, {
        ajax: table.dataset.ajaxUrl,
        columns,
        order: [[3, 'asc']],
        pageLength: Number(table.dataset.length || 25),
        processing: true,
        serverSide: true,
        autoWidth: false,
        scrollX: true,
        language: dataTableLanguage(),
        columnDefs: [
            { targets: [0, 1], className: 'dt-center' },
            { targets: [2], className: 'font-mono text-xs' },
            { targets: [-1], className: 'dt-right' },
            { targets: '_all', defaultContent: '' },
        ],
        createdRow: (row, data) => {
            if (data?.row_url) {
                row.dataset.rowHref = data.row_url;
                row.dataset.rowActionLabel = translate('Modifier');
                row.classList.add('app-row-openable');
            }
        },
        drawCallback: () => {
            document.querySelectorAll('.catalog-check-all').forEach((checkbox) => {
                checkbox.checked = false;
            });
        },
    });
});

document.querySelectorAll('[data-contact-table]').forEach((table) => {
    const isSupplier = table.dataset.kind === 'supplier';
    const clientColumns = [
        { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false },
        { data: 'code', name: 'code', orderable: true, searchable: true },
        { data: 'name', name: 'name', orderable: true, searchable: true },
        { data: 'mobile', name: 'phone', orderable: true, searchable: true },
        { data: 'email', name: 'email', orderable: true, searchable: true },
        { data: 'location', name: 'city', orderable: true, searchable: true },
        { data: 'credit_limit', name: 'credit_limit', orderable: true, searchable: false },
        { data: 'outstanding_balance', name: 'outstanding_balance', orderable: true, searchable: false },
        { data: 'advance_balance', name: 'advance_balance', orderable: true, searchable: false },
        { data: 'status', name: 'status', orderable: true, searchable: false },
        { data: 'action', name: 'action', orderable: false, searchable: false },
    ];
    const supplierColumns = [
        { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false },
        { data: 'code', name: 'code', orderable: true, searchable: true },
        { data: 'name', name: 'name', orderable: true, searchable: true },
        { data: 'mobile', name: 'phone', orderable: true, searchable: true },
        { data: 'email', name: 'email', orderable: true, searchable: true },
        { data: 'previous_balance', name: 'opening_balance', orderable: true, searchable: false },
        { data: 'purchase_due', name: 'purchases_due_sum', orderable: true, searchable: false },
        { data: 'purchase_return_due', name: 'purchase_returns_due_sum', orderable: true, searchable: false },
        { data: 'supplier_total', name: 'supplier_total', orderable: false, searchable: false },
        { data: 'status', name: 'status', orderable: true, searchable: false },
        { data: 'action', name: 'action', orderable: false, searchable: false },
    ];

    new DataTable(table, {
        ajax: table.dataset.ajaxUrl,
        columns: isSupplier ? supplierColumns : clientColumns,
        order: [[2, 'asc']],
        pageLength: Number(table.dataset.length || 25),
        processing: true,
        serverSide: true,
        autoWidth: false,
        scrollX: true,
        language: dataTableLanguage({
            zeroRecords: translate('Aucun contact trouvé'),
            emptyTable: translate('Aucun contact disponible'),
        }),
        columnDefs: [
            { targets: [0], className: 'dt-center' },
            { targets: [1], className: 'font-mono text-xs' },
            { targets: [-1], className: 'dt-right' },
            { targets: '_all', defaultContent: '' },
        ],
        createdRow: (row, data) => {
            if (data?.row_url) {
                row.dataset.rowHref = data.row_url;
                row.dataset.rowActionLabel = translate('Modifier');
                row.classList.add('app-row-openable');
            }
        },
    });
});

document.querySelectorAll('[data-advance-table]').forEach((table) => {
    new DataTable(table, {
        ajax: table.dataset.ajaxUrl,
        columns: [
            { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false },
            { data: 'paid_at', name: 'paid_at', orderable: true, searchable: false },
            { data: 'number', name: 'number', orderable: true, searchable: true },
            { data: 'customer', name: 'customer', orderable: false, searchable: true },
            { data: 'mobile', name: 'mobile', orderable: false, searchable: true },
            { data: 'payment_method', name: 'payment_method', orderable: true, searchable: true },
            { data: 'reference', name: 'reference', orderable: true, searchable: true },
            { data: 'amount', name: 'amount', orderable: true, searchable: false },
            { data: 'status', name: 'status', orderable: true, searchable: false },
            { data: 'action', name: 'action', orderable: false, searchable: false },
        ],
        order: [[1, 'desc']],
        pageLength: Number(table.dataset.length || 25),
        processing: true,
        serverSide: true,
        autoWidth: false,
        scrollX: true,
        language: dataTableLanguage({
            infoEmpty: translate('Aucune avance'),
            zeroRecords: translate('Aucune avance trouvée'),
            emptyTable: translate('Aucune avance disponible'),
        }),
        columnDefs: [
            { targets: [0], className: 'dt-center' },
            { targets: [2], className: 'font-mono text-xs' },
            { targets: [7], className: 'dt-right' },
            { targets: [-1], className: 'dt-right' },
            { targets: '_all', defaultContent: '' },
        ],
        createdRow: (row) => {
            row.classList.add('app-row-openable');
            row.dataset.rowActionLabel = translate('Reçu');
        },
    });
});

const isInteractiveTableTarget = (target) => target.closest('a, button, input, select, textarea, label, summary, details, form, [role="button"], [contenteditable="true"]');

const findRowPrimaryAction = (row) => row.querySelector(
    [
        '[data-row-primary-action]',
        'a[href*="edit"]',
        'a[href*="#edit"]',
        'a[href*="#contact-form"]',
        'button[data-advance-detail]',
        'button[onclick*="showModal"]',
        'a[href]',
    ].join(', ')
);

const decodeRowHref = (href) => {
    const textarea = document.createElement('textarea');
    textarea.innerHTML = href || '';
    return textarea.value;
};

const openInteractiveRow = (row) => {
    const href = decodeRowHref(row.dataset.rowHref);
    if (href) {
        window.location.assign(href);
        return true;
    }

    const dialogId = row.dataset.rowDialog;
    if (dialogId) {
        document.getElementById(dialogId)?.showModal();
        return true;
    }

    const action = findRowPrimaryAction(row);
    if (!action) return false;

    action.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
    return true;
};

const markOpenableRows = (root = document) => {
    root.querySelectorAll('tbody tr').forEach((row) => {
        if (row.classList.contains('app-row-openable')) return;
        if (row.dataset.rowHref || row.dataset.rowDialog || findRowPrimaryAction(row)) {
            row.classList.add('app-row-openable');
        }
    });
};

document.addEventListener('dblclick', (event) => {
    if (isInteractiveTableTarget(event.target)) return;

    const row = event.target.closest('tbody tr');
    if (!row || !row.closest('table')) return;

    if (openInteractiveRow(row)) {
        event.preventDefault();
    }
});

markOpenableRows();

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-advance-detail]');
    if (!trigger) return;

    const dialog = document.querySelector('[data-advance-receipt-dialog]');
    if (!dialog) return;

    const details = JSON.parse(trigger.dataset.advanceDetail || '{}');
    dialog.querySelectorAll('[data-advance-receipt-value]').forEach((node) => {
        node.textContent = details[node.dataset.advanceReceiptValue] || '—';
    });
    dialog.showModal();
});

document.querySelectorAll('.theme-swatch').forEach((button) => {
    button.addEventListener('click', () => {
        document.documentElement.style.setProperty('--brand-primary', button.dataset.color);
        const primaryInput = document.querySelector('input[name="primary"]');
        if (primaryInput) {
            primaryInput.value = button.dataset.color;
        }
    });
});

document.querySelectorAll('[data-collapsible-menu]').forEach((menu, index) => {
    const key = `librairepro-menu:${menu.dataset.menuKey || index}`;
    const stateNode = menu.querySelector('[data-collapsible-menu-state]');
    const saved = localStorage.getItem(key);

    if (saved === 'open') {
        menu.open = true;
    } else if (saved === 'closed') {
        menu.open = false;
    }

    const refresh = () => {
        if (stateNode) {
            stateNode.textContent = menu.open ? 'Masquer' : 'Afficher';
        }
    };

    menu.addEventListener('toggle', () => {
        localStorage.setItem(key, menu.open ? 'open' : 'closed');
        refresh();
    });
    refresh();
});

document.querySelectorAll('[data-stock-adjustment-builder]').forEach((form) => {
    const lines = form.querySelector('[data-stock-adjustment-lines]');
    const template = form.querySelector('[data-stock-adjustment-row-template]');
    const addButton = form.querySelector('[data-stock-adjustment-add]');
    const addMatchButton = form.querySelector('[data-stock-adjustment-add-match]');
    const clearSearchButton = form.querySelector('[data-stock-adjustment-clear]');
    const searchInput = form.querySelector('[data-stock-adjustment-search]');
    const searchCountNode = form.querySelector('[data-stock-adjustment-search-count]');
    const searchStateNode = form.querySelector('[data-stock-adjustment-search-state]');
    const suggestionsNode = form.querySelector('[data-stock-adjustment-suggestions]');
    const countNode = form.querySelector('[data-stock-adjustment-count]');
    const totalNode = form.querySelector('[data-stock-adjustment-total]');
    const initialStockQuery = String(form.dataset.stockAdjustmentInitialQuery || '').trim();
    const stockSearchUrl = form.dataset.stockAdjustmentSearchUrl;
    const selectOptions = new WeakMap();
    let baseOptions = [];
    let stockSearchTimer = null;
    let stockSearchSequence = 0;
    let nextIndex = Number(form.dataset.nextIndex || lines?.querySelectorAll('[data-stock-adjustment-row]').length || 0);

    if (!lines || !template || !addButton) return;

    const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim()
        .toLowerCase();
    const escapeHtml = (value) => String(value || '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const itemSelects = () => [...lines.querySelectorAll('[data-stock-adjustment-item-select]')];
    const selectedValues = () => new Set(itemSelects().map((select) => select.value).filter(Boolean));

    const focusExistingRow = (select) => {
        const row = select?.closest('[data-stock-adjustment-row]');
        row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row?.classList.add('stock-adjustment-row-highlight');
        window.setTimeout(() => row?.classList.remove('stock-adjustment-row-highlight'), 1200);
        row?.querySelector('[data-stock-adjustment-quantity]')?.focus();
    };

    const cacheSelect = (select) => {
        if (selectOptions.has(select)) return selectOptions.get(select);

        const options = [...select.options].map((option) => ({
            value: option.value,
            text: option.textContent,
            selected: option.selected,
            dataset: { ...option.dataset },
        }));
        selectOptions.set(select, options);
        if (baseOptions.length === 0) {
            baseOptions = options.filter((option) => option.value);
        }
        return options;
    };

    const selectedOptionText = (select) => cacheSelect(select).find((option) => option.value === select.value)?.text || '';

    const optionSearchText = (option) => normalize([
        option.text,
        option.value,
        option.dataset?.title,
        option.dataset?.code,
        option.dataset?.category,
        option.dataset?.brand,
    ].filter(Boolean).join(' '));

    const optionMatches = (option, query) => {
        const tokens = normalize(query).split(/\s+/).filter(Boolean);
        if (tokens.length === 0) return true;
        const haystack = optionSearchText(option);
        return tokens.every((token) => haystack.includes(token));
    };

    const applyOptionDataset = (node, dataset = {}) => {
        Object.entries(dataset || {}).forEach(([key, value]) => {
            if (value !== undefined && value !== null) {
                node.dataset[key] = value;
            }
        });
    };

    const syncSelectOptions = (options) => {
        baseOptions = options.filter((option) => option.value);
        itemSelects().forEach((select) => {
            const currentValue = select.value;
            const currentOption = cacheSelect(select).find((option) => option.value === currentValue);
            const merged = [
                { value: '', text: 'Choisir un article', selected: false, dataset: {} },
                ...baseOptions,
            ];
            if (currentOption?.value && !merged.some((option) => option.value === currentOption.value)) {
                merged.push(currentOption);
            }
            selectOptions.set(select, merged);
            renderSelectOptions(select, searchInput?.value || '');
        });
    };

    const renderComboOptions = (select, query = '') => {
        const combo = select.nextElementSibling?.matches?.('[data-stock-combobox]') ? select.nextElementSibling : null;
        if (!combo) return;

        const list = combo.querySelector('[data-stock-combobox-list]');
        const normalized = normalize(query);
        const matches = cacheSelect(select)
            .filter((option) => option.value)
            .filter((option) => !normalized || optionMatches(option, normalized))
            .slice(0, 60);
        const selected = selectedValues();

        list.innerHTML = matches.length
            ? matches.map((option) => `
                <button type="button" class="stock-combobox-option ${option.value === select.value ? 'is-selected' : ''} ${selected.has(option.value) && option.value !== select.value ? 'is-used' : ''}" data-value="${escapeHtml(option.value)}">
                    <span>${escapeHtml(option.dataset?.title || option.text)}</span>
                    <small>Stock ${escapeHtml(option.dataset?.stock ?? '—')} · ${escapeHtml(option.dataset?.code || 'Sans code')}${option.dataset?.category ? ` · ${escapeHtml(option.dataset.category)}` : ''}${selected.has(option.value) && option.value !== select.value ? ' · déjà ajouté' : ''}</small>
                </button>
            `).join('')
            : '<p class="stock-combobox-empty">Aucun article trouvé. Essayez un code-barres, ISBN ou un autre mot.</p>';
    };

    const renderSearchSuggestions = (matches = []) => {
        if (!suggestionsNode) return;
        const visibleMatches = matches.filter((option) => option.value).slice(0, 8);
        suggestionsNode.hidden = visibleMatches.length === 0;
        const selected = selectedValues();
        suggestionsNode.innerHTML = visibleMatches.map((option) => {
            const stock = option.dataset?.stock ?? '—';
            const code = option.dataset?.code || 'Sans code';
            const category = option.dataset?.category ? ` · ${escapeHtml(option.dataset.category)}` : '';
            const title = option.dataset?.title || option.text;
            const isSelected = selected.has(option.value);
            return `
                <button type="button" class="stock-adjustment-suggestion ${isSelected ? 'is-selected' : ''}" data-value="${escapeHtml(option.value)}">
                    <span>
                        <strong>${escapeHtml(title)}</strong>
                        <small>Stock ${escapeHtml(stock)} · ${escapeHtml(code)}${category}</small>
                    </span>
                    <em>${isSelected ? 'Déjà ajouté' : 'Ajouter'}</em>
                </button>
            `;
        }).join('');
    };

    const syncComboDisplay = (select) => {
        const combo = select.nextElementSibling?.matches?.('[data-stock-combobox]') ? select.nextElementSibling : null;
        const input = combo?.querySelector('[data-stock-combobox-input]');
        if (input && document.activeElement !== input) {
            input.value = selectedOptionText(select);
        }
        renderComboOptions(select, input?.value || '');
    };

    const enhanceItemSelect = (select) => {
        if (!select || select.dataset.stockComboReady === '1') return;
        select.dataset.stockComboReady = '1';
        select.classList.add('stock-combobox-native');

        const combo = document.createElement('div');
        combo.className = 'stock-combobox';
        combo.dataset.stockCombobox = '1';
        combo.innerHTML = `
            <input type="search" class="stock-combobox-input" data-stock-combobox-input placeholder="Choisir un article..." autocomplete="off">
            <div class="stock-combobox-list" data-stock-combobox-list hidden></div>
        `;
        select.insertAdjacentElement('afterend', combo);

        const input = combo.querySelector('[data-stock-combobox-input]');
        const list = combo.querySelector('[data-stock-combobox-list]');

        input.value = selectedOptionText(select);
        renderComboOptions(select);

        const open = () => {
            list.hidden = false;
            renderComboOptions(select, input.value);
        };
        const close = () => {
            list.hidden = true;
            input.value = selectedOptionText(select);
        };
        const choose = (value) => {
            const duplicate = itemSelects().find((itemSelect) => itemSelect !== select && itemSelect.value === String(value));
            if (duplicate) {
                focusExistingRow(duplicate);
                list.hidden = true;
                input.value = selectedOptionText(select);
                return;
            }
            select.value = String(value);
            select.dispatchEvent(new Event('change', { bubbles: true }));
            input.value = selectedOptionText(select);
            list.hidden = true;
        };

        input.addEventListener('focus', () => {
            input.select();
            open();
        });
        input.addEventListener('input', () => {
            select.value = '';
            open();
            renderComboOptions(select, input.value);
            window.clearTimeout(stockSearchTimer);
            stockSearchTimer = window.setTimeout(() => fetchStockOptions(input.value), 220);
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                return;
            }

            if (event.key !== 'Enter') return;
            event.preventDefault();
            const firstOption = list.querySelector('[data-value]');
            if (firstOption) {
                choose(firstOption.dataset.value);
                select.closest('[data-stock-adjustment-row]')?.querySelector('[data-stock-adjustment-quantity]')?.focus();
            }
        });
        list.addEventListener('mousedown', (event) => {
            event.preventDefault();
            const option = event.target.closest('[data-value]');
            if (option) {
                choose(option.dataset.value);
            }
        });
        document.addEventListener('click', (event) => {
            if (!combo.contains(event.target)) close();
        });
    };

    const getBaseOptions = () => {
        if (baseOptions.length) return baseOptions;
        const firstSelect = itemSelects()[0] || template.content?.querySelector?.('[data-stock-adjustment-item-select]');
        if (!firstSelect) return [];
        return cacheSelect(firstSelect).filter((option) => option.value);
    };

    const matchingOptions = (query) => {
        const normalized = normalize(query);
        return getBaseOptions().filter((option) => !normalized || optionMatches(option, normalized));
    };

    const fetchStockOptions = async (query) => {
        if (!stockSearchUrl) return matchingOptions(query);
        const cleanQuery = String(query || '').trim();
        const sequence = ++stockSearchSequence;

        if (searchStateNode) searchStateNode.textContent = cleanQuery ? ' · recherche...' : ' disponibles';

        try {
            const url = new URL(stockSearchUrl, window.location.origin);
            if (cleanQuery) url.searchParams.set('q', cleanQuery);
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Stock search failed');
            const payload = await response.json();
            if (sequence !== stockSearchSequence) return matchingOptions(query);

            const options = (payload.items || []).map((item) => ({
                value: String(item.value),
                text: item.text,
                selected: false,
                dataset: {
                    title: item.title,
                    stock: item.stock,
                    threshold: item.threshold,
                    code: item.code,
                    category: item.category,
                    brand: item.brand,
                },
            }));
            syncSelectOptions(options);
            if (searchCountNode) searchCountNode.textContent = Number(payload.count || options.length).toLocaleString('fr-FR');
            if (searchStateNode) searchStateNode.textContent = cleanQuery ? ' · résultats serveur' : ' disponibles';
            renderSearchSuggestions(options);
            addMatchButton?.toggleAttribute('disabled', options.length === 0);
            return options;
        } catch {
            if (sequence !== stockSearchSequence) return matchingOptions(query);
            const matches = matchingOptions(query);
            if (searchStateNode) searchStateNode.textContent = ' · recherche locale';
            if (searchCountNode) searchCountNode.textContent = matches.length.toLocaleString('fr-FR');
            renderSearchSuggestions(matches);
            return matches;
        }
    };

    const renderSelectOptions = (select, query = '') => {
        const options = cacheSelect(select);
        const currentValue = select.value;
        const normalized = normalize(query);
        const filtered = options.filter((option) => {
            if (!option.value) return true;
            return !normalized || optionMatches(option, normalized) || option.value === currentValue;
        });

        select.innerHTML = '';
        filtered.forEach((option) => {
            const node = new Option(option.text, option.value, false, option.value === currentValue);
            applyOptionDataset(node, option.dataset);
            select.add(node);
        });
        syncComboDisplay(select);
    };

    const applySearch = () => {
        const query = searchInput?.value || '';
        const matches = matchingOptions(query);
        itemSelects().forEach((select) => renderSelectOptions(select, query));
        if (searchCountNode) {
            searchCountNode.textContent = matches.length.toLocaleString('fr-FR');
        }
        if (searchStateNode) {
            searchStateNode.textContent = query ? ' · résultats locaux' : ' disponibles';
        }
        renderSearchSuggestions(matches);
        addMatchButton?.toggleAttribute('disabled', matches.length === 0);
        return matches;
    };

    const scheduleStockSearch = () => {
        window.clearTimeout(stockSearchTimer);
        applySearch();
        stockSearchTimer = window.setTimeout(() => fetchStockOptions(searchInput?.value || ''), 220);
    };

    const refreshSummary = () => {
        const rows = [...lines.querySelectorAll('[data-stock-adjustment-row]')];
        let selectedCount = 0;
        let totalQuantity = 0;

        rows.forEach((row, index) => {
            const indexNode = row.querySelector('[data-stock-adjustment-index]');
            const itemSelect = row.querySelector('select[name*="[item_id]"]');
            const quantityInput = row.querySelector('[data-stock-adjustment-quantity]');
            const removeButton = row.querySelector('[data-stock-adjustment-remove]');
            const quantity = Number(quantityInput?.value || 0);

            if (indexNode) {
                indexNode.textContent = String(index + 1).padStart(2, '0');
            }

            if (itemSelect?.value && quantity > 0) {
                selectedCount += 1;
                totalQuantity += quantity;
            }

            if (removeButton) {
                removeButton.disabled = rows.length === 1;
            }
        });

        if (countNode) countNode.textContent = selectedCount.toLocaleString('fr-FR');
        if (totalNode) totalNode.textContent = totalQuantity.toLocaleString('fr-FR');
    };

    const addRow = (preselectedValue = '') => {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.trim().replaceAll('__INDEX__', String(nextIndex));
        nextIndex += 1;
        const row = wrapper.firstElementChild;
        lines.append(row);
        const itemSelect = row.querySelector('[data-stock-adjustment-item-select]');
        cacheSelect(itemSelect);
        enhanceItemSelect(itemSelect);
        applySearch();
        if (preselectedValue) {
            itemSelect.value = String(preselectedValue);
            syncComboDisplay(itemSelect);
        }
        row.querySelector('[data-stock-combobox-input]')?.focus();
        refreshSummary();
        return row;
    };

    const selectMatch = (match) => {
        if (!match) return;

        const duplicate = itemSelects().find((select) => select.value === String(match.value));
        if (duplicate) {
            focusExistingRow(duplicate);
            return;
        }

        let targetSelect = itemSelects().find((select) => !select.value);
        if (!targetSelect) {
            targetSelect = addRow().querySelector('[data-stock-adjustment-item-select]');
        }

        renderSelectOptions(targetSelect, searchInput?.value || '');
        targetSelect.value = match.value;
        syncComboDisplay(targetSelect);
        targetSelect.dispatchEvent(new Event('change', { bubbles: true }));
        targetSelect.closest('[data-stock-adjustment-row]')?.querySelector('[data-stock-adjustment-quantity]')?.focus();
    };

    const selectFirstMatch = async () => {
        let match = applySearch()[0];
        if (searchInput?.value && stockSearchUrl) {
            const serverMatches = await fetchStockOptions(searchInput.value);
            match = serverMatches?.[0] || match;
        }
        selectMatch(match);
    };

    addButton.addEventListener('click', () => addRow());
    addMatchButton?.addEventListener('click', () => {
        selectFirstMatch();
    });
    clearSearchButton?.addEventListener('click', () => {
        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }
        scheduleStockSearch();
    });
    searchInput?.addEventListener('input', scheduleStockSearch);
    searchInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        selectFirstMatch();
    });
    suggestionsNode?.addEventListener('click', (event) => {
        const suggestion = event.target.closest('[data-value]');
        if (!suggestion) return;
        const match = getBaseOptions().find((option) => option.value === suggestion.dataset.value)
            || matchingOptions(searchInput?.value || '').find((option) => option.value === suggestion.dataset.value);
        selectMatch(match || { value: suggestion.dataset.value, text: suggestion.textContent.trim(), dataset: {} });
    });
    lines.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-stock-adjustment-remove]');
        if (!removeButton) return;
        const rows = lines.querySelectorAll('[data-stock-adjustment-row]');
        if (rows.length <= 1) return;
        removeButton.closest('[data-stock-adjustment-row]')?.remove();
        refreshSummary();
    });
    lines.addEventListener('input', refreshSummary);
    lines.addEventListener('change', () => {
        scheduleStockSearch();
        refreshSummary();
    });
    itemSelects().forEach((select) => {
        cacheSelect(select);
        enhanceItemSelect(select);
    });
    if (initialStockQuery && searchInput?.value) {
        selectFirstMatch();
    } else {
        applySearch();
    }
    refreshSummary();
});

document.querySelectorAll('[data-inventory-item-picker]').forEach((form) => {
    const input = form.querySelector('[data-inventory-item-input]');
    const hiddenId = form.querySelector('[data-inventory-item-id]');
    const results = form.querySelector('[data-inventory-item-results]');
    const searchUrl = form.dataset.inventoryItemSearchUrl;
    let timer = null;
    let sequence = 0;
    let matches = [];
    let activeIndex = -1;

    if (!input || !hiddenId || !results || !searchUrl) return;

    const escapeHtml = (value) => String(value || '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const hideResults = () => {
        results.hidden = true;
        activeIndex = -1;
    };

    const targetUrl = (itemId) => {
        const url = new URL(form.action, window.location.origin);
        url.searchParams.set('panel', 'stock-adjustments');
        url.searchParams.set('inventory_item', itemId);
        url.hash = 'inventory-history';
        return url.toString();
    };

    const selectItem = (item) => {
        if (!item?.value) return;
        hiddenId.value = item.value;
        input.value = item.title || item.text || '';
        hideResults();
        window.location.href = targetUrl(item.value);
    };

    const render = () => {
        const visibleMatches = matches.slice(0, 10);
        results.hidden = visibleMatches.length === 0;
        if (visibleMatches.length === 0) {
            results.innerHTML = '';
            activeIndex = -1;
            return;
        }

        results.innerHTML = visibleMatches.map((item, index) => {
            const code = item.code || 'Sans code';
            const category = item.category ? ` · ${escapeHtml(item.category)}` : '';
            const brand = item.brand ? ` · ${escapeHtml(item.brand)}` : '';
            return `
                <button type="button" class="inventory-item-option ${index === activeIndex ? 'is-active' : ''}" data-inventory-value="${escapeHtml(item.value)}">
                    <span class="inventory-item-option-main">
                        <strong>${escapeHtml(item.title || item.text)}</strong>
                        <small>${escapeHtml(code)}${category}${brand}</small>
                    </span>
                    <span class="inventory-item-option-stock">Stock ${Number(item.stock || 0).toLocaleString('fr-FR')}</span>
                </button>
            `;
        }).join('');
    };

    const search = async () => {
        const query = input.value.trim();
        hiddenId.value = '';

        if (query.length < 2) {
            matches = [];
            render();
            return;
        }

        const current = ++sequence;
        const url = new URL(searchUrl, window.location.origin);
        url.searchParams.set('q', query);

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Inventory item search failed');
            const payload = await response.json();
            if (current !== sequence) return;
            matches = payload.items || [];
            activeIndex = matches.length ? 0 : -1;
            render();
        } catch {
            if (current !== sequence) return;
            matches = [];
            results.hidden = false;
            results.innerHTML = '<p class="inventory-item-empty">Recherche indisponible. Essayez encore ou validez le texte saisi.</p>';
        }
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 180);
    });

    input.addEventListener('focus', () => {
        if (matches.length) render();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hideResults();
            return;
        }

        if (!matches.length) return;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeIndex = Math.min(activeIndex + 1, Math.min(matches.length, 10) - 1);
            render();
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            render();
            return;
        }

        if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            selectItem(matches[activeIndex]);
        }
    });

    results.addEventListener('mousedown', (event) => {
        event.preventDefault();
        const option = event.target.closest('[data-inventory-value]');
        if (!option) return;
        const item = matches.find((match) => String(match.value) === String(option.dataset.inventoryValue));
        selectItem(item);
    });

    form.addEventListener('submit', (event) => {
        if (!hiddenId.value && matches.length > 0 && activeIndex >= 0) {
            event.preventDefault();
            selectItem(matches[activeIndex]);
        }
    });

    document.addEventListener('click', (event) => {
        if (!form.contains(event.target)) hideResults();
    });
});

document.querySelectorAll('[data-searchable-select]').forEach((select) => {
    if (select.dataset.searchReady === '1') return;
    select.dataset.searchReady = '1';

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'select-search-input mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10';
    search.placeholder = select.dataset.placeholder || 'Rechercher...';
    select.parentNode.insertBefore(search, select);

    const allOptions = [...select.options].map((option) => ({
        value: option.value,
        text: option.textContent,
        selected: option.selected,
        dataset: { ...option.dataset },
    }));

    const renderOptions = (query = '') => {
        const currentValue = select.value;
        const normalized = query.trim().toLowerCase();
        select.innerHTML = '';

        allOptions
            .filter((option) => !normalized || option.text.toLowerCase().includes(normalized) || option.value.toLowerCase().includes(normalized))
            .forEach((option) => {
                const node = new Option(option.text, option.value, false, option.value === currentValue);
                Object.entries(option.dataset || {}).forEach(([key, value]) => {
                    node.dataset[key] = value;
                });
                select.add(node);
            });
    };

    select.addEventListener('inline-option-added', (event) => {
        const exists = allOptions.some((option) => String(option.value) === String(event.detail.value));
        if (!exists) {
            allOptions.push({ value: String(event.detail.value), text: event.detail.label, selected: false, dataset: {} });
        }
        search.value = '';
        renderOptions();
        select.value = String(event.detail.value);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });

    search.addEventListener('input', () => renderOptions(search.value));
});

document.querySelectorAll('.inline-create-open').forEach((button) => {
    button.addEventListener('click', () => {
        const dialog = document.getElementById(button.dataset.dialog);
        dialog?.querySelectorAll('[data-inline-create] input, [data-inline-create] select, [data-inline-create] textarea').forEach((field) => {
            field.disabled = false;
        });
        dialog?.showModal();
        dialog?.querySelector('input[name="name"]')?.focus();
    });
});

const closeDialogAndDisableInlineFields = (dialog) => {
    dialog?.querySelectorAll('[data-inline-create] input, [data-inline-create] select, [data-inline-create] textarea').forEach((field) => {
        field.disabled = true;
    });
    dialog?.close();
};

document.querySelectorAll('.dialog-close').forEach((button) => {
    button.addEventListener('click', () => {
        closeDialogAndDisableInlineFields(button.closest('dialog'));
    });
});

document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            closeDialogAndDisableInlineFields(dialog);
        }
    });
});

document.querySelectorAll('[data-table-filter]').forEach((input) => {
    const table = document.getElementById(input.dataset.tableFilter);
    const rows = table ? Array.from(table.querySelectorAll('tbody tr')) : [];

    input.addEventListener('input', () => {
        const query = input.value.trim().toLowerCase();

        rows.forEach((row) => {
            row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query);
        });
    });
});

document.querySelectorAll('[data-import-kind-select]').forEach((select) => {
    const form = select.closest('form');
    const link = form?.querySelector('[data-import-example-base]');
    if (!link) return;

    select.addEventListener('change', () => {
        link.href = `${link.dataset.importExampleBase}/${encodeURIComponent(select.value)}`;
    });
});

document.querySelectorAll('[data-smart-validation]').forEach((form) => {
    form.noValidate = true;

    const summary = form.querySelector('[data-validation-summary]');
    const visualFields = (field) => {
        const fields = [field];
        if (field.matches('select[data-searchable-select]')) {
            const search = field.previousElementSibling;
            if (search?.classList.contains('select-search-input')) {
                fields.push(search);
            }
        }

        return fields;
    };
    const highlightField = (field) => {
        visualFields(field).forEach((visualField) => visualField.classList.add('app-field-invalid'));
        field.closest('label, .block')?.classList.add('app-field-invalid-label');
    };
    const clearHighlights = () => {
        form.querySelectorAll('.app-field-invalid').forEach((field) => field.classList.remove('app-field-invalid'));
        form.querySelectorAll('.app-field-invalid-label').forEach((label) => label.classList.remove('app-field-invalid-label'));
    };
    const fieldLabel = (field) => {
        const explicitLabel = field.closest('label, .block')?.querySelector('span')?.textContent;
        return (explicitLabel || field.getAttribute('placeholder') || field.name || 'Champ').replace('*', '').trim();
    };
    const escapeHtml = (value) => String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    const showSummary = (messages) => {
        if (!summary) return;
        summary.classList.remove('hidden');
        summary.innerHTML = `
            <div class="app-validation-summary-heading">
                <span class="app-validation-summary-icon">!</span>
                <div>
                    <strong>Le formulaire contient des informations à corriger.</strong>
                    <p>${messages.length} champ(s) nécessitent votre attention. Veuillez compléter les champs surlignés avant de continuer.</p>
                </div>
            </div>
            <ul>${messages.map((message) => `<li>${escapeHtml(message)}</li>`).join('')}</ul>
        `;
    };

    try {
        const serverFields = JSON.parse(form.dataset.errorFields || '[]');
        serverFields.forEach((name) => {
            const normalized = String(name).split('.')[0];
            form.querySelectorAll(`[name="${CSS.escape(normalized)}"], [name^="${CSS.escape(normalized)}["]`).forEach(highlightField);
        });
        if (serverFields.length && summary) {
            summary.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    } catch {
        // Ignore malformed validation metadata.
    }

    form.addEventListener('input', (event) => {
        if (event.target instanceof HTMLElement) {
            visualFields(event.target).forEach((field) => field.classList.remove('app-field-invalid'));
            event.target.closest('label, .block')?.classList.remove('app-field-invalid-label');
        }
    });

    form.addEventListener('change', (event) => {
        if (event.target instanceof HTMLElement) {
            visualFields(event.target).forEach((field) => field.classList.remove('app-field-invalid'));
            event.target.closest('label, .block')?.classList.remove('app-field-invalid-label');
        }
    });

    form.addEventListener('submit', (event) => {
        clearHighlights();
        const invalidFields = Array.from(form.querySelectorAll('input, select, textarea'))
            .filter((field) => field.willValidate && !field.checkValidity());

        if (!invalidFields.length) return;

        event.preventDefault();
        invalidFields.forEach(highlightField);
        showSummary(invalidFields.map((field) => `${fieldLabel(field)}: ${field.validationMessage}`));
        invalidFields[0]?.focus({ preventScroll: true });
        invalidFields[0]?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
});

document.querySelectorAll('[data-report-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const table = document.getElementById(button.dataset.reportCopy);
        if (!table) return;
        const text = Array.from(table.querySelectorAll('tr')).map((row) =>
            Array.from(row.children).map((cell) => cell.textContent.trim()).join('\t')
        ).join('\n');

        try {
            await navigator.clipboard.writeText(text);
            button.textContent = 'Copié';
            setTimeout(() => {
                button.textContent = 'Copier tableau';
            }, 1600);
        } catch {
            button.textContent = 'Copie indisponible';
        }
    });
});

document.querySelectorAll('[data-inline-create]').forEach((container) => {
    container.querySelectorAll('input, select, textarea').forEach((field) => {
        field.disabled = true;
    });

    const submit = container.querySelector('.inline-create-submit');
    const errorNode = container.querySelector('.inline-create-error');
    container.closest('dialog')?.addEventListener('close', () => {
        container.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = true;
        });
    });

    submit?.addEventListener('click', async () => {
        const payload = new FormData();
        container.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field.name) payload.append(field.name, field.value);
        });

        errorNode?.classList.add('hidden');
        submit.disabled = true;
        submit.textContent = 'Ajout...';

        try {
            const response = await fetch(container.dataset.endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: payload,
            });

            const data = await response.json();
            if (!response.ok) {
                const firstError = data?.errors ? Object.values(data.errors).flat()[0] : data?.message;
                throw new Error(firstError || 'Impossible d’ajouter cette donnée.');
            }

            const targetNames = [container.dataset.target];
            if (container.dataset.target === 'category_id') targetNames.push('parent_id');

            document.querySelectorAll(targetNames.map((name) => `select[name="${name}"]`).join(',')).forEach((select) => {
                select.dispatchEvent(new CustomEvent('inline-option-added', {
                    detail: { value: data.id, label: data.label },
                }));
            });

            container.querySelectorAll('input:not([type="hidden"]), textarea').forEach((field) => {
                if (field.type !== 'color') field.value = '';
            });
            container.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = true;
            });
            container.closest('dialog')?.close();
        } catch (error) {
            if (errorNode) {
                errorNode.textContent = error.message;
                errorNode.classList.remove('hidden');
            }
        } finally {
            submit.disabled = false;
            submit.textContent = 'Ajouter';
        }
    });
});

document.querySelectorAll('.barcode-scan-btn').forEach((button) => {
    button.addEventListener('click', async () => {
        const input = button.closest('div')?.querySelector('.barcode-input');
        if (!input) return;

        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
            const code = window.prompt('Scanner non disponible sur ce navigateur. Saisissez ou collez le code à barre :', input.value || '');
            if (code) {
                input.value = code.trim();
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
            return;
        }

        const dialog = document.createElement('dialog');
        dialog.className = 'w-[min(520px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40';
        dialog.innerHTML = `
            <div class="p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="font-semibold">Scanner un code à barre</h3>
                        <p class="mt-1 text-sm text-slate-500">Placez le code face à la caméra.</p>
                    </div>
                    <button class="barcode-scan-close text-2xl leading-none text-slate-400" type="button">&times;</button>
                </div>
                <video class="mt-4 aspect-video w-full rounded-xl bg-slate-950 object-cover" autoplay muted playsinline></video>
                <p class="barcode-scan-status mt-3 text-sm text-slate-500">Recherche en cours...</p>
            </div>
        `;
        document.body.appendChild(dialog);

        const video = dialog.querySelector('video');
        const status = dialog.querySelector('.barcode-scan-status');
        const detector = new window.BarcodeDetector({ formats: ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e'] });
        let stream;
        let stopped = false;

        const stop = () => {
            stopped = true;
            stream?.getTracks().forEach((track) => track.stop());
            dialog.close();
            dialog.remove();
        };

        dialog.querySelector('.barcode-scan-close')?.addEventListener('click', stop);
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            stop();
        });

        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            video.srcObject = stream;
            dialog.showModal();

            const tick = async () => {
                if (stopped) return;
                const codes = await detector.detect(video);
                if (codes.length > 0) {
                    input.value = codes[0].rawValue;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    stop();
                    return;
                }
                requestAnimationFrame(tick);
            };

            video.addEventListener('loadedmetadata', tick, { once: true });
        } catch (error) {
            status.textContent = 'Caméra indisponible. Vous pouvez saisir le code manuellement.';
            dialog.showModal();
        }
    });
});
