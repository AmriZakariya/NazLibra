/**
 * The shop's currency, as the server formats it (App\Support\Money).
 *
 * The layout sets window.appCurrency from the shop's settings. Amounts used to
 * be formatted here with a fixed MAD/"DH", so a shop in euros saw dirhams in
 * the POS and every form calculator.
 */
const currency = () => window.appCurrency || { code: 'MAD', symbol: 'DH', placement: 'Right', decimals: 2 };

/** "1 250,00" — the number alone ("1,250.00" for a currency written so, as EGP). */
export const formatNumber = (value) => {
    const { decimals, decimal_separator: decimal = ',', thousands_separator: thousands = ' ' } = currency();
    const [whole, cents] = Number(value || 0).toFixed(decimals).split('.');
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g, thousands) + (cents ? `${decimal}${cents}` : '');
};

const place = (number, symbol) => (currency().placement === 'Left' ? `${symbol} ${number}` : `${number} ${symbol}`);

/** "1 250,00 €". */
export const formatMoney = (value) => place(formatNumber(value), currency().symbol);

export const currencySymbol = () => currency().symbol;

/**
 * For a thermal printer: its code pages have no "€" or "£", so a symbol
 * outside ASCII prints as the ISO code instead ("12,00 EUR").
 */
export const formatReceiptMoney = (value) => {
    const { symbol, code } = currency();
    return place(formatNumber(value), /^[\x20-\x7E]+$/.test(symbol) ? symbol : code);
};
