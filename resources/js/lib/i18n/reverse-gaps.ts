import type { Entry } from './entry';

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): used documents get Reverse
 * with a reason, never a hard delete. Received stock transfers, posted landed
 * costs and owner withdrawals, payroll inputs (Cancel after approval, Edit
 * while waiting) and leave types (Delete when never used). Latin digits.
 */
export const reverseGaps = {
    // ── Stock transfers ─────────────────────────────────────────────────
    'corr.stock.transfer.reversed': { en: 'Reversed', bn: 'রিভার্স হয়েছে' },
    'corr.stock.transfer.reversed_on': { en: 'Reversed on {date} by {by}.', bn: '{date} তারিখে {by} রিভার্স করেছেন।' },
    'corr.stock.transfer.label': { en: 'Transfer #{id}', bn: 'ট্রান্সফার #{id}' },
} satisfies Record<string, Entry>;
