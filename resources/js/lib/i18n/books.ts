import type { Entry } from './entry';

/**
 * Fixes from the 14 Oct 2026 books audit (accounting, assets, bank statements):
 * only the sentences those fixes added. The accounting screens around them are
 * still English by the 31 Aug decision (staff-first Bangla); a new sentence
 * still carries both languages so it never has to be found again later.
 */
export const books = {
    // ── Journal register and journal page (ACC-004, ACC-011) ─────────────
    'books.journal.reverse_title': { en: 'Reverse this journal?', bn: 'এই জার্নালটি উল্টে দেবেন?' },
    'books.journal.reverse_body': {
        en: 'A reversing journal dated today is posted at once, with every line on the other side. A reversal cannot itself be reversed: a mistake is then corrected with a new journal.',
        bn: 'আজকের তারিখে একটি উল্টো জার্নাল সঙ্গে সঙ্গে পোস্ট হবে, প্রতিটি লাইন উল্টো দিকে। উল্টো জার্নাল আর উল্টানো যায় না: ভুল হলে নতুন জার্নাল দিয়ে ঠিক করতে হবে।',
    },
    'books.journal.reverse_confirm': { en: 'Reverse it', bn: 'উল্টে দিন' },
    'books.journal.from_source': {
        en: 'Another screen made this entry (a voucher, payroll, sales, purchases or stock). Correct it on that screen.',
        bn: 'এই এন্ট্রি অন্য একটি স্ক্রিন থেকে তৈরি (ভাউচার, বেতন, বিক্রি, ক্রয় বা স্টক)। সেই স্ক্রিনেই ঠিক করুন।',
    },
    'books.journal.all_types': { en: 'All types', bn: 'সব ধরন' },

    // ── General ledger picker (ACC-007) ──────────────────────────────────
    'books.ledger.head_off': { en: '{label} (off)', bn: '{label} (বন্ধ)' },

    // ── Logins card, My accounts (FEA-005) ───────────────────────────────
    'books.logins.switch_business': {
        en: 'Switch to {business} to reveal this login.',
        bn: 'এই লগইন দেখতে {business} ব্যবসায় যান।',
    },
    'books.logins.other_business': {
        en: 'This login belongs to another business. Switch to that business to reveal it.',
        bn: 'এই লগইন অন্য একটি ব্যবসার। দেখতে সেই ব্যবসায় যান।',
    },
} satisfies Record<string, Entry>;
