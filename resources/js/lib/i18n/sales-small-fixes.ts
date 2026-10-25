import type { Entry } from './entry';

/**
 * IP21 (25 Oct 2026): small sales fixes. Numbers, dates and money never
 * translate.
 */
export const salesSmallFixes = {
    // The customer's open advance (2150), counted as credit they have.
    'ssf.credit.advance': {
        en: 'Advance with us Tk {amount}, counted as credit they have.',
        bn: 'আমাদের কাছে অগ্রিম ৳ {amount}, এটি তাঁর ক্রেডিট হিসেবে ধরা হয়েছে।',
    },
} satisfies Record<string, Entry>;
