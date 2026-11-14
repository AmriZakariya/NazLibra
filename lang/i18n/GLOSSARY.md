# Castl-it-POS back office — translation glossary

The back office is a point-of-sale and stock system for small shops (retail,
bookshops, cafés, restaurants, bakeries, barbers, pharmacies). The source is
French. Keep the tone short and practical, like a till's interface.

## Rules

- Keep every placeholder exactly: `{0}`, `{1}`, `:name`, `_MAX_`, `_START_`,
  `_END_`, `_TOTAL_`, `_MENU_`, `%s`, `%d`. Same count, may move in the sentence.
- Keep HTML entities, markup characters, emojis, arrows (→ ← ↑ ↓ ✓ ✕ · …) and
  units (DH, €, %, kg, ms, px).
- Keep a fragment a fragment: texts that start or end mid-sentence (« ) — ou
  laissez-la vide », « (coût), ») are pieces of a sentence split around a link
  or a code tag: translate the piece, keep its leading/trailing punctuation.
- Keep the same capitalisation style (Title vs sentence) and the final
  punctuation (. : … ? !). French puts a space before « : ? ! » — the target
  language uses its own convention.
- Brand and technical names stay: Castl-it-POS, ESC/POS, Bluetooth, USB, Wi-Fi,
  PIN, SKU, EAN, ISBN, QR, CSV, Excel, PDF, KDS, API, Chrome, Edge, Android,
  Windows, WhatsApp, Epson, Zebra…
- A text that is clearly not for people (code, an identifier, a CSS value): copy
  it unchanged.
- Arabic: Modern Standard Arabic, as used in Moroccan business software;
  digits stay Western (0-9).

## Vocabulary (fr → meaning)

| Français | Meaning / preferred term |
|---|---|
| Caisse | the till / point of sale (en: Register or POS; es: Caja; pt: Caixa; de: Kasse; it: Cassa; ar: الصندوق) |
| Ticket | the sale receipt / order ticket (en: Ticket for open orders, Receipt for the printed proof) |
| Vente | sale |
| Avoir | credit note |
| Devis | quote |
| Facture | invoice |
| Bon de livraison (BL) | delivery note |
| Bon de commande | purchase order |
| Achat | purchase (from a supplier) |
| Article | item (product sold) |
| Service | service |
| Catalogue | catalogue / catalog (en: Catalog) |
| Stock | stock / inventory level |
| Inventaire | stock count / inventory |
| Emplacement / Magasin / Dépôt | location / store / warehouse |
| Transfert | stock transfer |
| Ajustement | stock adjustment |
| Fournisseur | supplier |
| Client | customer |
| Fidélité / points | loyalty / points |
| Remise | discount |
| Coupon | coupon |
| Avance client | customer deposit / advance |
| Encaissement | payment collection / taking payment |
| Espèces / Carte / Virement | cash / card / bank transfer |
| Tiroir-caisse / Ouverture / Clôture | cash drawer / opening / closing |
| Terminal (virtuel) | (virtual) terminal |
| TTC / HT / TVA | incl. tax / excl. tax / VAT (de: brutto/netto/MwSt.; es: IVA incl./sin IVA; pt: c/ IVA, s/ IVA; it: IVA incl./escl.; ar: ش.ض / د.ض / الضريبة على القيمة المضافة) |
| Pourboire | tip |
| Barbier | barber |
| Rendez-vous | appointment |
| Cuisine / Écran cuisine | kitchen / kitchen display |
| Table / Ticket nommé | table / named ticket |
| Paramètres | settings |
| Société | company (business details) |
| Journal d'activité | activity log |
| Rôle / Permission | role / permission |
