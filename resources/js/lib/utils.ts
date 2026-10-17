import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/**
 * A stored decimal(15,4) quantity as a person reads it: "100.0000" -> "100",
 * "2.5000" -> "2.5", grouped like the server's Money::format.
 */
export function formatQuantity(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '-';
    // Safe on a value the server already formatted ("1,000").
    const number = Number(String(value).replace(/,/g, ''));
    return Number.isFinite(number) ? number.toLocaleString('en-US', { maximumFractionDigits: 4 }) : String(value);
}

/** A stored decimal as money: "8000.0000" -> "8,000.00". */
export function formatMoney(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '-';
    // Safe on a value the server already formatted ("1,500.00").
    const number = Number(String(value).replace(/,/g, ''));
    return Number.isFinite(number) ? number.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : String(value);
}

/**
 * A stored decimal for an input box or a sentence, with no grouping: "5.0000"
 * -> "5", "0.5000" -> "0.5", "2.2500" -> "2.25" (practice run F5/F13, 17 Oct
 * 2026: an edit box showed "Reorder 5.0000"). Up to 4 decimals, as stored.
 */
export function plainNumber(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';
    const number = Number(String(value).replace(/,/g, ''));
    if (!Number.isFinite(number)) return String(value);

    return String(Number(number.toFixed(4)));
}

/**
 * A stored code as words, for the few places that only have the code: "walk_in"
 * -> "Walk in", "dealer" -> "Dealer". Prefer the server's own label list when the
 * page has one (practice run F27).
 */
export function codeLabel(code: string | null | undefined, labels?: { value: string; label: string }[]): string {
    if (!code) return '-';
    const known = labels?.find((option) => option.value === code);
    if (known) return known.label;
    const words = code.replace(/[_-]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}
