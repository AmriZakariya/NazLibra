/**
 * Hardware Connectivity Module for LibrairePro POS
 * Supports thermal printers, cash drawers, and barcode scanners
 * on Windows devices via Web Serial API.
 */

const ESC = 0x1B;
const GS = 0x1D;

const Hardware = {
    port: null,
    writer: null,
    reader: null,
    connected: false,

    async connect() {
        if (!('serial' in navigator)) {
            throw new Error('Web Serial API not supported. Use Chrome or Edge on Windows.');
        }
        try {
            this.port = await navigator.serial.requestPort({
                filters: [
                    { usbVendorId: 0x0483 }, // STMicroelectronics
                    { usbVendorId: 0x067B }, // Prolific
                    { usbVendorId: 0x0403 }, // FTDI
                    { usbVendorId: 0x1A86 }, // QinHeng
                    { usbVendorId: 0x0525 }, // Netchip
                ],
            });
            await this.port.open({ baudRate: 9600, dataBits: 8, stopBits: 1, parity: 'none' });
            this.writer = this.port.writable.getWriter();
            this.connected = true;
            return true;
        } catch (error) {
            console.error('Hardware connect error:', error);
            throw error;
        }
    },

    async disconnect() {
        if (this.writer) {
            this.writer.releaseLock();
            this.writer = null;
        }
        if (this.port) {
            await this.port.close();
            this.port = null;
        }
        this.connected = false;
    },

    async send(bytes) {
        if (!this.connected || !this.writer) {
            throw new Error('Printer not connected');
        }
        const data = new Uint8Array(bytes);
        await this.writer.write(data);
    },

    // ESC/POS Commands
    cmdInit() { return [ESC, 0x40]; },
    cmdAlignCenter() { return [ESC, 0x61, 0x01]; },
    cmdAlignLeft() { return [ESC, 0x61, 0x00]; },
    cmdAlignRight() { return [ESC, 0x61, 0x02]; },
    cmdBold(on) { return [ESC, 0x45, on ? 0x01 : 0x00]; },
    cmdDoubleSize(on) { return on ? [GS, 0x21, 0x11] : [GS, 0x21, 0x00]; },
    cmdFeed(lines) { return [ESC, 0x64, lines]; },
    cmdCut() { return [GS, 0x56, 0x01]; },
    cmdDrawerKick() { return [ESC, 0x70, 0x00, 0x19, 0xFA]; }, // pulse pin 2
    cmdText(str) {
        const encoder = new TextEncoder();
        return Array.from(encoder.encode(str));
    },

    // Receipt Printing
    async printReceipt(data) {
        const { storeName, serialNumber, ticketNumber, date, items, subtotal, discount, coupon, total, paid, change, paymentMethod, note, createdBy, updatedBy } = data;
        const width = 32;
        const separator = '-'.repeat(width);
        const money = (value) => `${Number(value || 0).toFixed(2)} DH`;
        const clean = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();
        const fit = (value, length) => clean(value).slice(0, length).padEnd(length);
        const line = (label, value) => {
            const left = clean(label).slice(0, 15);
            const right = clean(value).slice(0, width - 1);
            const spaces = Math.max(1, width - left.length - right.length);

            return `${left}${' '.repeat(spaces)}${right}`;
        };
        const wrap = (value, length = width) => {
            const words = clean(value).split(' ').filter(Boolean);
            const chunks = [];
            let current = '';

            words.forEach((word) => {
                if ((current + ' ' + word).trim().length > length) {
                    if (current) chunks.push(current);
                    current = word;
                } else {
                    current = `${current} ${word}`.trim();
                }
            });

            if (current) chunks.push(current);

            return chunks.length ? chunks : [''];
        };
        const resolvedSerial = serialNumber || ticketNumber || '';
        const lines = [];

        lines.push(...this.cmdInit());
        lines.push(...this.cmdAlignCenter());
        lines.push(...this.cmdBold(true));
        lines.push(...this.cmdText(clean(storeName || 'LibrairePro')));
        lines.push(...this.cmdBold(false));
        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdText('N SERIE / TICKET'));
        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdDoubleSize(true));
        lines.push(...this.cmdText(clean(resolvedSerial)));
        lines.push(...this.cmdDoubleSize(false));
        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdText(date || new Date().toLocaleString('fr-FR')));
        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdText(separator));
        lines.push(...this.cmdAlignLeft());

        if (items && items.length) {
            for (const item of items) {
                const quantity = Number(item.quantity || 1);
                const unitPrice = Number(item.price || 0);
                const lineTotal = Number(item.total || quantity * unitPrice);
                wrap(item.name || 'Article').forEach((chunk) => {
                    lines.push(...this.cmdText(chunk));
                    lines.push(...this.cmdFeed(1));
                });
                lines.push(...this.cmdText(line(`${quantity} x ${money(unitPrice)}`, money(lineTotal))));
                lines.push(...this.cmdFeed(1));
            }
        }

        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdText(separator));
        lines.push(...this.cmdFeed(1));

        if (subtotal !== undefined) {
            lines.push(...this.cmdText(line('Sous-total', money(subtotal))));
            lines.push(...this.cmdFeed(1));
        }
        if (discount) {
            lines.push(...this.cmdText(line('Remise', `-${money(discount)}`)));
            lines.push(...this.cmdFeed(1));
        }
        if (coupon) {
            lines.push(...this.cmdText(line('Coupon', `-${money(coupon)}`)));
            lines.push(...this.cmdFeed(1));
        }
        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdBold(true));
        lines.push(...this.cmdText(line('TOTAL', money(total))));
        lines.push(...this.cmdBold(false));
        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdText(line('Paye', money(paid))));
        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdText(line('Monnaie', money(change))));
        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdText(line('Mode', paymentMethod || 'Espèces')));
        if (createdBy || updatedBy) {
            lines.push(...this.cmdFeed(1));
            lines.push(...this.cmdText(separator));
            lines.push(...this.cmdFeed(1));
            if (createdBy) {
                lines.push(...this.cmdText(line('Cree par', createdBy)));
                lines.push(...this.cmdFeed(1));
            }
            if (updatedBy && updatedBy !== createdBy) {
                lines.push(...this.cmdText(line('Maj par', updatedBy)));
                lines.push(...this.cmdFeed(1));
            }
        }
        if (note) {
            lines.push(...this.cmdFeed(1));
            lines.push(...this.cmdText(separator));
            lines.push(...this.cmdFeed(1));
            lines.push(...this.cmdText('Note:'));
            lines.push(...this.cmdFeed(1));
            wrap(note).forEach((chunk) => {
                lines.push(...this.cmdText(chunk));
                lines.push(...this.cmdFeed(1));
            });
        }
        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdAlignCenter());
        lines.push(...this.cmdText(separator));
        lines.push(...this.cmdFeed(1));
        lines.push(...this.cmdText('Merci pour votre visite !'));
        lines.push(...this.cmdFeed(3));
        lines.push(...this.cmdCut());

        await this.send(lines);
    },

    // Drawer Kick
    async kickDrawer() {
        await this.send(this.cmdDrawerKick());
    },

    // Test Print
    async testPrint() {
        const lines = [
            ...this.cmdInit(),
            ...this.cmdAlignCenter(),
            ...this.cmdDoubleSize(true),
            ...this.cmdText('LibrairePro'),
            ...this.cmdDoubleSize(false),
            ...this.cmdFeed(1),
            ...this.cmdText('Test imprimante'),
            ...this.cmdFeed(1),
            ...this.cmdText(new Date().toLocaleString('fr-FR')),
            ...this.cmdFeed(3),
            ...this.cmdCut(),
        ];
        await this.send(lines);
    },
};

// Barcode Scanner Auto-focus Handler
const BarcodeScanner = {
    init() {
        // Most USB barcode scanners act as HID keyboards.
        // We intercept rapid key sequences in POS search inputs.
        document.addEventListener('keydown', (event) => {
            const posSearch = document.querySelector('.pos-search');
            if (!posSearch) return;
            if (event.target === posSearch || event.target.closest('.pos-search')) return;
            if (event.key.length === 1 && !event.ctrlKey && !event.altKey && !event.metaKey) {
                // Focus search on any alphanumeric key outside input fields
                if (!event.target.closest('input, textarea, select')) {
                    posSearch.focus();
                }
            }
        });
    },
};

// Register Service Worker for PWA
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then((registration) => registration.update())
            .catch(() => {});
    });
}

// Settings page hardware controls
const HardwareSettings = {
    init() {
        const connectBtn = document.querySelector('.pos-hw-connect-printer');
        const disconnectBtn = document.querySelector('.pos-hw-disconnect-printer');
        const testPrintBtn = document.querySelector('.pos-hw-test-print');
        const testDrawerBtn = document.querySelector('.pos-hw-test-drawer');
        const statusLabel = document.querySelector('.pos-hw-printer-status');
        const barcodeTest = document.querySelector('.pos-hw-barcode-test');
        const barcodeResult = document.querySelector('.pos-hw-barcode-result');

        const printFeedback = document.querySelector('.pos-hw-print-feedback');
        const drawerFeedback = document.querySelector('.pos-hw-drawer-feedback');
        const dot = document.querySelector('.pos-hw-printer-dot');

        const updateStatus = (connected) => {
            if (statusLabel) {
                statusLabel.textContent = connected ? 'Connecté' : 'Déconnecté';
                statusLabel.className = connected
                    ? 'pos-hw-printer-status inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-500/10'
                    : 'pos-hw-printer-status inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500 dark:bg-white/10';
            }
            if (dot) {
                dot.className = connected
                    ? 'pos-hw-printer-dot inline-block size-2.5 rounded-full bg-emerald-500 animate-pulse'
                    : 'pos-hw-printer-dot inline-block size-2.5 rounded-full bg-slate-300 dark:bg-slate-600';
            }
            if (connectBtn) connectBtn.classList.toggle('hidden', connected);
            if (disconnectBtn) disconnectBtn.classList.toggle('hidden', !connected);
        };

        connectBtn?.addEventListener('click', async () => {
            try {
                await Hardware.connect();
                updateStatus(true);
                if (typeof showToast === 'function') showToast('Imprimante connectée');
            } catch (error) {
                if (typeof showToast === 'function') showToast(error.message || 'Connexion impossible');
            }
        });

        disconnectBtn?.addEventListener('click', async () => {
            try {
                await Hardware.disconnect();
                updateStatus(false);
                if (typeof showToast === 'function') showToast('Déconnecté');
            } catch (error) {
                console.error(error);
            }
        });

        testPrintBtn?.addEventListener('click', async () => {
            if (!Hardware.connected) {
                if (typeof showToast === 'function') showToast('Connectez d\'abord l\'imprimante');
                if (printFeedback) {
                    printFeedback.textContent = 'Connectez d\'abord l\'imprimante';
                    printFeedback.classList.remove('hidden');
                }
                return;
            }
            try {
                await Hardware.testPrint();
                if (typeof showToast === 'function') showToast('Test imprimante envoyé');
                if (printFeedback) {
                    printFeedback.textContent = 'Ticket de test envoyé avec succès';
                    printFeedback.classList.remove('hidden');
                    setTimeout(() => printFeedback.classList.add('hidden'), 3000);
                }
            } catch (error) {
                if (typeof showToast === 'function') showToast('Erreur d\'impression');
                if (printFeedback) {
                    printFeedback.textContent = 'Erreur d\'impression : ' + (error.message || '');
                    printFeedback.className = 'pos-hw-print-feedback mt-3 hidden text-sm font-medium text-rose-600 dark:text-rose-400';
                    printFeedback.classList.remove('hidden');
                }
            }
        });

        testDrawerBtn?.addEventListener('click', async () => {
            if (!Hardware.connected) {
                if (typeof showToast === 'function') showToast('Connectez d\'abord l\'imprimante');
                if (drawerFeedback) {
                    drawerFeedback.textContent = 'Connectez d\'abord l\'imprimante';
                    drawerFeedback.className = 'pos-hw-drawer-feedback mt-3 hidden text-sm font-medium text-rose-600 dark:text-rose-400';
                    drawerFeedback.classList.remove('hidden');
                }
                return;
            }
            try {
                const pin = document.querySelector('.pos-hw-drawer-pin')?.value || '0';
                const pulse = parseInt(document.querySelector('.pos-hw-drawer-pulse')?.value || '25', 10);
                // Update drawer kick command with settings
                Hardware.cmdDrawerKick = () => [ESC, 0x70, parseInt(pin, 10), pulse, 0xFA];
                await Hardware.kickDrawer();
                if (typeof showToast === 'function') showToast('Tiroir ouvert');
                if (drawerFeedback) {
                    drawerFeedback.textContent = 'Impulsion envoyée au tiroir';
                    drawerFeedback.className = 'pos-hw-drawer-feedback mt-3 hidden text-sm font-medium text-emerald-600 dark:text-emerald-400';
                    drawerFeedback.classList.remove('hidden');
                    setTimeout(() => drawerFeedback.classList.add('hidden'), 3000);
                }
            } catch (error) {
                if (typeof showToast === 'function') showToast('Impossible d\'ouvrir le tiroir');
                if (drawerFeedback) {
                    drawerFeedback.textContent = 'Erreur tiroir : ' + (error.message || '');
                    drawerFeedback.className = 'pos-hw-drawer-feedback mt-3 hidden text-sm font-medium text-rose-600 dark:text-rose-400';
                    drawerFeedback.classList.remove('hidden');
                }
            }
        });

        if (barcodeTest) {
            barcodeTest.addEventListener('input', () => {
                if (barcodeResult) {
                    const code = barcodeTest.value;
                    if (code.length > 3) {
                        barcodeResult.innerHTML = `<svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> <span>${code}</span>`;
                        barcodeResult.classList.remove('hidden');
                        setTimeout(() => {
                            barcodeResult.classList.add('hidden');
                            barcodeTest.value = '';
                        }, 2500);
                    }
                }
            });
        }
    },
};

// Expose to global scope for inline scripts
window.LibraireProHardware = Hardware;
window.LibraireProBarcodeScanner = BarcodeScanner;
window.LibraireProHardwareSettings = HardwareSettings;

// Auto-init on settings page
document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('.pos-hw-connect-printer')) {
        HardwareSettings.init();
    }
});
