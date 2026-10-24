import type { Entry } from './entry';

/**
 * ⭐ Integration of the initial plan branches (25 Oct 2026): the few words the
 * merges added where two builders' screens meet.
 */
export const integration = {
    // IP3 meets IP14: a return's credit note on the invoice it credited.
    'ipi.return_credit': { en: 'Return', bn: 'ফেরত' },
    'ipi.reversed': { en: 'Reversed', bn: 'বাতিল করা হয়েছে' },
    // IP14's open credit kept as an IP3 customer advance.
    'ipi.keep_as_advance': { en: 'Keep as advance', bn: 'অগ্রিম হিসেবে রাখুন' },
    'ipi.keep_as_advance_title': { en: 'Keep the open credit of {number} as advance', bn: '{number} এর খোলা ক্রেডিট অগ্রিম হিসেবে রাখুন' },
    'ipi.keep_as_advance_hint': {
        en: "Open credit {open}. It becomes the customer's advance, which their next invoices take.",
        bn: 'খোলা ক্রেডিট {open}। এটি কাস্টমারের অগ্রিম হবে, যা তাদের পরের ইনভয়েসে কাটা যাবে।',
    },
    'ipi.kept_as_advance': { en: 'Kept as advance', bn: 'অগ্রিম হিসেবে রাখা' },
    // The Recycle bin's label for IP17's leave types.
    'ipi.bin.leave_type': { en: 'Leave type', bn: 'ছুটির ধরন' },
    'ipi.date': { en: 'Date', bn: 'তারিখ' },
    'ipi.amount': { en: 'Amount', bn: 'পরিমাণ' },
} satisfies Record<string, Entry>;
