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
    return Number(value).toLocaleString('en-US', { maximumFractionDigits: 4 });
}

/** A stored decimal as money: "8000.0000" -> "8,000.00". */
export function formatMoney(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '-';
    return Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
