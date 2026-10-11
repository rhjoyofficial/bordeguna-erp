import type { Unit } from './types';

/** How a figure reads on the dashboard. Taka is written "Tk" with grouping. */
export function formatValue(value: number | string | null | undefined, unit: Unit = 'count', compact = false): string {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    if (typeof value === 'string' || unit === 'text') {
        return String(value);
    }

    switch (unit) {
        case 'money':
            return `Tk ${formatNumber(value, compact ? 1 : 2, compact)}`;
        case 'percent':
            return `${formatNumber(value, 1)}%`;
        case 'days':
            return `${formatNumber(value, 1)} d`;
        case 'quantity':
            return formatNumber(value, 2);
        default:
            return formatNumber(value, 0, compact);
    }
}

export function formatNumber(value: number, decimals = 0, compact = false): string {
    if (compact && Math.abs(value) >= 100000) {
        // Bangladesh reads large sums in lakh and crore.
        if (Math.abs(value) >= 10000000) {
            return `${(value / 10000000).toLocaleString('en-US', { maximumFractionDigits: 2 })} cr`;
        }

        return `${(value / 100000).toLocaleString('en-US', { maximumFractionDigits: 2 })} lakh`;
    }

    return value.toLocaleString('en-US', { minimumFractionDigits: decimals === 2 ? 2 : 0, maximumFractionDigits: decimals });
}

/** "+12.5%" against a previous figure, or null when there is nothing to compare. */
export function changeAgainst(current: number, previous: number | null | undefined): { text: string; up: boolean } | null {
    if (previous === null || previous === undefined || previous === 0) {
        return null;
    }

    const change = ((current - previous) / Math.abs(previous)) * 100;

    return { text: `${change >= 0 ? '+' : ''}${change.toFixed(1)}%`, up: change >= 0 };
}

export function secondsAgo(iso: string | null, now: number): number | null {
    if (!iso) {
        return null;
    }

    const at = Date.parse(iso);

    return Number.isNaN(at) ? null : Math.max(0, Math.round((now - at) / 1000));
}

export function agoText(seconds: number | null): string {
    if (seconds === null) {
        return '';
    }

    if (seconds < 5) {
        return 'just now';
    }

    if (seconds < 60) {
        return `${seconds} s ago`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} min ago`;
    }

    return `${Math.floor(seconds / 3600)} h ago`;
}
