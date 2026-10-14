import type { Entry } from './entry';

/**
 * Fixes from the 14 Oct 2026 inventory audit: only the sentences those fixes
 * added. The inventory screens around them are still English by the 31 Aug
 * decision (staff-first Bangla); a new sentence carries both languages anyway.
 */
export const stock = {
    // ── Products dialog (INV-005) ────────────────────────────────────────
    'stock.product.not_allowed': {
        en: 'You may not add or change products. Ask a manager.',
        bn: 'আপনি পণ্য যোগ বা বদল করতে পারেন না। একজন ম্যানেজারকে বলুন।',
    },
} satisfies Record<string, Entry>;
