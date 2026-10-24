import type { Entry } from './entry';

/**
 * ⭐ Corrections wave 1 gaps (24 Oct 2026): what the purchase and sales pages
 * show about corrections made elsewhere. A purchase return reversed after it
 * was recorded is marked on the goods receipt it came from. Latin digits;
 * dates never translate.
 */
export const corrW1Pages = {
    'corr.w1.return_reversed': { en: 'Reversed', bn: 'রিভার্স করা হয়েছে' },
    'corr.w1.return_reversed_on': {
        en: 'Reversed on {date}: the goods went back into the same batches.',
        bn: '{date} তারিখে রিভার্স করা হয়েছে: মাল আগের ব্যাচেই ফিরে গেছে।',
    },
} satisfies Record<string, Entry>;
