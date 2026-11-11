/** Paise (INR minor units) to a localized currency string. */
export function formatMoney(paise: number, currency = 'INR'): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(paise / 100)
}

/** Paise to rupees with exactly two decimals (invoices need the paisa). */
export function formatPaise(paise: number, currency = 'INR'): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(paise / 100)
}
