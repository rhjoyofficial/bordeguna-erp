import type { Entry } from './entry';

/**
 * The Tally Gateway sidebar and the header Create menu (purchase redesign
 * PR6, 18 Oct 2026): new section and tab names, and the quick entries.
 */
export const gateway = {
    // ── Purchases sections and tabs ─────────────────────────────────────
    'gw.receipts_returns': { en: 'Receipts & returns', bn: 'মাল গ্রহণ ও ফেরত' },
    'gw.reports': { en: 'Reports', bn: 'রিপোর্ট' },
    'gw.setup': { en: 'Setup', bn: 'সেটআপ' },
    'gw.new_bill': { en: 'New bill', bn: 'নতুন বিল' },
    'gw.new_payment': { en: 'New payment', bn: 'নতুন পরিশোধ' },
    'gw.new_advance': { en: 'New advance', bn: 'নতুন অগ্রিম' },
    'gw.new_credit_note': { en: 'New credit note', bn: 'নতুন ক্রেডিট নোট' },
    'gw.import_expenses': { en: 'Import expenses', bn: 'খরচ ইমপোর্ট' },
    'gw.approval_limits': { en: 'Approval limits', bn: 'অনুমোদনের সীমা' },

    // ── Header Create menu ──────────────────────────────────────────────
    'gw.create': { en: 'Create', bn: 'তৈরি' },
    'gw.create_entries': { en: 'Entries', bn: 'এন্ট্রি' },
    'gw.create_records': { en: 'Records', bn: 'রেকর্ড' },
    'gw.c_po': { en: 'Purchase order', bn: 'ক্রয় অর্ডার' },
    'gw.c_buy': { en: 'Buy and receive', bn: 'কেনা ও গ্রহণ' },
    'gw.c_payment': { en: 'Payment', bn: 'পেমেন্ট' },
    'gw.c_receipt': { en: 'Receipt', bn: 'রিসিট' },
    'gw.c_journal': { en: 'Journal', bn: 'জার্নাল' },
    'gw.c_production': { en: 'Production run', bn: 'উৎপাদন' },
    'gw.c_sale': { en: 'Sale', bn: 'বিক্রি' },
} satisfies Record<string, Entry>;
