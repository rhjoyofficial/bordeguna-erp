import type { Entry } from './entry';

/**
 * Initial plan B9 + Joy's Q19 (25 Oct 2026): the "Return" popup on the goods
 * receipt and supplier bill pages (what the supplier owes back, kept as an
 * advance or refunded), and the VAT fields that stay visible but do nothing
 * while the business is not VAT registered. Numbers, dates and money never
 * translate.
 */
export const purchaseReturnsVat = {
    'vatoff.badge': { en: 'VAT not registered', bn: 'ভ্যাট নিবন্ধিত নয়' },
    'vatoff.hint': {
        en: 'VAT not registered: these fields do nothing until VAT registration is switched on, and every VAT amount stays zero.',
        bn: 'ভ্যাট নিবন্ধিত নয়: ভ্যাট নিবন্ধন চালু না হওয়া পর্যন্ত এই ঘরগুলো কিছু করে না, আর সব ভ্যাটের পরিমাণ শূন্য থাকে।',
    },
    // Employee statement (R63): his Employee cash account beside his other ledgers.
    'estmt.cash_tile': { en: 'Company cash: {account}', bn: 'কোম্পানির নগদ: {account}' },
    'estmt.cash_holds': { en: 'holds company cash of', bn: 'কোম্পানির নগদ হাতে আছে' },
    'estmt.cash_owed': { en: 'is owed by the company', bn: 'কোম্পানির কাছে পাওনা' },
} satisfies Record<string, Entry>;
