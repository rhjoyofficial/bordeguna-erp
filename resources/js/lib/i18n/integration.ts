import type { Entry } from './entry';

/**
 * ⭐ Integration of the initial plan branches (25 Oct 2026): the few words the
 * merges added where two builders' screens meet.
 */
export const integration = {
    // IP3 meets IP14: a return's credit note on the invoice it credited.
    'ipi.return_credit': { en: 'Return', bn: 'ফেরত' },
    'ipi.reversed': { en: 'Reversed', bn: 'বাতিল করা হয়েছে' },
} satisfies Record<string, Entry>;
