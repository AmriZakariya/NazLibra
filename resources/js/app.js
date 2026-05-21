import DataTable from 'datatables.net-dt';
import 'datatables.net-dt/css/dataTables.dataTables.css';

const money = new Intl.NumberFormat('fr-MA', {
    style: 'currency',
    currency: 'MAD',
    currencyDisplay: 'narrowSymbol',
});

document.querySelectorAll('.app-theme-toggle').forEach((button) => {
    button.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
        localStorage.setItem('librairepro-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
    });
});

if (localStorage.getItem('librairepro-theme') === 'dark') {
    document.documentElement.classList.add('dark');
}

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

    setCollapsed(localStorage.getItem('librairepro-sidebar') === 'collapsed');

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

document.querySelectorAll('.app-rtl-toggle').forEach((button) => {
    button.addEventListener('click', () => {
        const html = document.documentElement;
        html.dir = html.dir === 'rtl' ? 'ltr' : 'rtl';
        button.textContent = html.dir === 'rtl' ? 'Français' : 'العربية';
    });
});

const cart = [];
const renderCart = () => {
    const cartNode = document.querySelector('.pos-cart');
    const emptyNode = document.querySelector('.pos-empty');
    if (!cartNode) return;

    cartNode.innerHTML = cart.map((item, index) => `
        <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 dark:border-white/10">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold">${item.name}</p>
                <p class="text-xs text-slate-500">${item.quantity} × ${money.format(item.price)}</p>
            </div>
            <button class="pos-remove rounded-md px-2 py-1 text-xs font-semibold text-rose-600" data-index="${index}" type="button">Retirer</button>
        </div>
    `).join('');

    emptyNode?.classList.toggle('hidden', cart.length > 0);
    const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
    const tax = subtotal * 0.2 / 1.2;
    document.querySelector('.pos-subtotal').textContent = money.format(subtotal);
    document.querySelector('.pos-tax').textContent = money.format(tax);
    document.querySelector('.pos-total').textContent = money.format(subtotal);
};

document.querySelectorAll('.pos-item').forEach((button) => {
    button.addEventListener('click', () => {
        const name = button.dataset.name;
        const price = Number(button.dataset.price || 0);
        const existing = cart.find((item) => item.name === name);

        if (existing) {
            existing.quantity += 1;
        } else {
            cart.push({ name, price, quantity: 1 });
        }

        renderCart();
    });
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('.pos-remove');
    if (!button) return;
    cart.splice(Number(button.dataset.index), 1);
    renderCart();
});

document.querySelectorAll('.pos-clear').forEach((button) => {
    button.addEventListener('click', () => {
        cart.splice(0, cart.length);
        renderCart();
    });
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
        language: {
            search: 'Recherche table',
            lengthMenu: 'Afficher _MENU_ lignes',
            info: 'Affichage _START_-_END_ sur _TOTAL_',
            infoEmpty: 'Aucune ligne',
            infoFiltered: '(filtré depuis _MAX_ lignes)',
            zeroRecords: 'Aucune donnée trouvée',
            processing: 'Chargement...',
            emptyTable: 'Aucune donnée disponible',
            paginate: {
                first: 'Début',
                previous: 'Précédent',
                next: 'Suivant',
                last: 'Fin',
            },
        },
        columnDefs: [
            { targets: [0, 1], className: 'dt-center' },
            { targets: [2], className: 'font-mono text-xs' },
            { targets: [-1], className: 'dt-right' },
            { targets: '_all', defaultContent: '' },
        ],
        drawCallback: () => {
            document.querySelectorAll('.catalog-check-all').forEach((checkbox) => {
                checkbox.checked = false;
            });
        },
    });
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
    }));

    const renderOptions = (query = '') => {
        const currentValue = select.value;
        const normalized = query.trim().toLowerCase();
        select.innerHTML = '';

        allOptions
            .filter((option) => !normalized || option.text.toLowerCase().includes(normalized) || option.value.toLowerCase().includes(normalized))
            .forEach((option) => {
                const node = new Option(option.text, option.value, false, option.value === currentValue);
                select.add(node);
            });
    };

    select.addEventListener('inline-option-added', (event) => {
        const exists = allOptions.some((option) => String(option.value) === String(event.detail.value));
        if (!exists) {
            allOptions.push({ value: String(event.detail.value), text: event.detail.label, selected: false });
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

document.querySelectorAll('.dialog-close').forEach((button) => {
    button.addEventListener('click', () => {
        const dialog = button.closest('dialog');
        dialog?.querySelectorAll('[data-inline-create] input, [data-inline-create] select, [data-inline-create] textarea').forEach((field) => {
            field.disabled = true;
        });
        dialog?.close();
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
