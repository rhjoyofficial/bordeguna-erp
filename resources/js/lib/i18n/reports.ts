import type { Entry } from './entry';

/**
 * The report screen (reports pack, 11 Oct 2026): showing a report on screen,
 * its chart and table, and the sidebar links to the sales and cash reports.
 * Report names, column headings and basis notes come from the server in
 * English; digits, dates and money never come through here.
 */
export const reports = {
    'reports.show_on_screen': { en: 'Show on screen', bn: 'স্ক্রিনে দেখুন' },
    'reports.refresh': { en: 'Refresh', bn: 'আবার দেখুন' },
    'reports.on_screen': { en: 'On screen', bn: 'স্ক্রিনে' },
    'reports.loading': { en: 'Preparing the report...', bn: 'রিপোর্ট তৈরি হচ্ছে...' },
    'reports.failed': {
        en: 'The report could not be shown. Check the filters and try again.',
        bn: 'রিপোর্টটি দেখানো গেল না। ফিল্টার দেখে আবার চেষ্টা করুন।',
    },
    'reports.no_rows': {
        en: 'No rows matched these filters. The report ran and found nothing.',
        bn: 'এই ফিল্টারে কোনো সারি মেলেনি। রিপোর্ট চলেছে, কিছু পাওয়া যায়নি।',
    },
    'reports.rows': { en: '{count} rows', bn: '{count}টি সারি' },
    'reports.rows_capped': {
        en: 'Showing the first {shown} of {count} rows. The totals cover all of them; download the file for every row.',
        bn: '{count}টি সারির প্রথম {shown}টি দেখানো হচ্ছে। মোট হিসাব সবগুলোর; সব সারির জন্য ফাইল নামান।',
    },
    'reports.total': { en: 'Total', bn: 'মোট' },
    'reports.basis': { en: 'How these figures are counted', bn: 'এই হিসাব কীভাবে করা হয়েছে' },
    'reports.chart_note': {
        en: 'Drawn from the rows below; the exact figures are in the table.',
        bn: 'নিচের সারিগুলো থেকে আঁকা; সঠিক অঙ্ক টেবিলে আছে।',
    },
    'reports.chart_top': { en: 'Largest {count} shown', bn: 'সবচেয়ে বড় {count}টি দেখানো হয়েছে' },
    'reports.download_hint': {
        en: 'Or download the whole report as a file:',
        bn: 'অথবা পুরো রিপোর্ট ফাইল হিসেবে নামান:',
    },

    // ── Sidebar ─────────────────────────────────────────────────────────
    'menu.sales_reports': { en: 'Sales reports', bn: 'বিক্রয় রিপোর্ট' },
    'menu.report_sales_by_channel': { en: 'Sales by brand and channel', bn: 'ব্র্যান্ড ও চ্যানেল অনুযায়ী বিক্রি' },
    'menu.report_margin': { en: 'Product and brand margin', bn: 'পণ্য ও ব্র্যান্ডের মার্জিন' },
    'menu.report_delivery_economics': { en: 'Delivery economics', bn: 'ডেলিভারির আয়-খরচ' },
    'menu.report_courier_ageing': { en: 'Courier settlement ageing', bn: 'কুরিয়ারের বকেয়া টাকার বয়স' },
    'menu.report_cash_position': { en: 'Cash position', bn: 'নগদ অবস্থান' },
    'menu.all_reports': { en: 'All reports', bn: 'সব রিপোর্ট' },
} satisfies Record<string, Entry>;
