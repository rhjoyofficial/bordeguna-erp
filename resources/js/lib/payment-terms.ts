/**
 * ⭐ Structured supplier payment terms (ruling P6, 18 Oct 2026), the screen's
 * copy of App\Modules\Purchasing\Enums\PaymentTermType. The form uses it only
 * to SHOW the due date it expects; the server works the date out again and
 * stores it on the bill.
 */

export type PaymentTermType = 'immediate' | 'net_days' | 'end_of_month' | 'advance';

export interface PaymentTerms {
    type: PaymentTermType;
    days: number | null;
    label: string;
}

export interface PaymentTermOption {
    value: PaymentTermType;
    label: string;
    uses_days: boolean;
}

/** The four kinds, in the order a person thinks of them; labels come from the dictionary (terms.type.*). */
export const PAYMENT_TERM_OPTIONS: PaymentTermOption[] = [
    { value: 'immediate', label: 'Due immediately (cash on delivery)', uses_days: false },
    { value: 'net_days', label: 'Net N days', uses_days: true },
    { value: 'end_of_month', label: 'End of month + N days', uses_days: true },
    { value: 'advance', label: 'Advance required', uses_days: false },
];

export const usesDays = (type: string): boolean => type === 'net_days' || type === 'end_of_month';

/** "YYYY-MM-DD" plus days, in calendar days (UTC arithmetic, no time zone drift). */
function addDays(ymd: string, days: number): string {
    const [y, m, d] = ymd.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + days));

    return date.toISOString().slice(0, 10);
}

/**
 * The due date for a bill dated `billDate` (YYYY-MM-DD), or null when the
 * terms are incomplete. End of month is the bill's own month's last day, then
 * the days: 31 Jan + 15 is 15 Feb, never March.
 */
export function dueDateFor(type: string, days: string | number | null, billDate: string): string | null {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(billDate) || type === '') {
        return null;
    }

    if (type === 'immediate' || type === 'advance') {
        return billDate;
    }

    const n = days === null || days === '' ? NaN : Number(days);

    if (!Number.isInteger(n) || n < 0) {
        return null;
    }

    if (type === 'net_days') {
        return addDays(billDate, n);
    }

    if (type === 'end_of_month') {
        const [y, m] = billDate.split('-').map(Number);
        const lastDay = new Date(Date.UTC(y, m, 0)).toISOString().slice(0, 10);

        return addDays(lastDay, n);
    }

    return null;
}
