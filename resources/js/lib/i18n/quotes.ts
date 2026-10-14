import type { Entry } from './entry';

/**
 * Rule #6 (Joy, 14 Oct 2026): a quote priced at the retail list because the
 * buyer's group has no list says so on the quote list and on the print. Only
 * the sentences that rule added; the quotation screens around them are still
 * English by the 31 Aug decision (staff-first Bangla).
 */
export const quotes = {
    'quotes.retail_fallback_badge': { en: 'Retail price used', bn: 'খুচরা দাম ধরা হয়েছে' },
    'quotes.retail_fallback_hint': {
        en: "At least one line is priced at the retail list: the buyer's group has no price list.",
        bn: 'অন্তত একটি লাইনে খুচরা তালিকার দাম ধরা হয়েছে: এই ক্রেতার দলের কোনো দামের তালিকা নেই।',
    },
    'quotes.retail_fallback_line': { en: 'Retail price (no price list for this buyer)', bn: 'খুচরা দাম (এই ক্রেতার জন্য দামের তালিকা নেই)' },
    'quotes.retail_fallback_note': {
        en: 'Lines marked "retail price" are quoted at our retail list, because no price list is set for this buyer yet.',
        bn: '"খুচরা দাম" চিহ্নিত লাইনগুলো আমাদের খুচরা তালিকার দামে দেওয়া হয়েছে, কারণ এই ক্রেতার জন্য এখনও কোনো দামের তালিকা ঠিক করা হয়নি।',
    },
} satisfies Record<string, Entry>;
