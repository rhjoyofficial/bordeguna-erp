import type { Entry } from './entry';

/**
 * ⭐ Suppliers and customers as ledgers on vouchers, Tally style (20 Oct
 * 2026): the "Paid to / Received from" picker, bill-wise details, tax held
 * back, the customer ledger and the Sundry Creditors / Debtors pages.
 * Numbers, dates, money and document numbers never translate.
 */
export const partyLedgers = {
    // ── Paid to / Received from ─────────────────────────────────────────
    'pl.someone_else': { en: 'Someone else (type the name)', bn: 'অন্য কেউ (নাম লিখুন)' },
    'pl.choose_party': { en: 'Choose who', bn: 'কাকে বাছুন' },
    'pl.group_suppliers': { en: 'Suppliers (Sundry Creditors)', bn: 'সরবরাহকারী (সান্ড্রি ক্রেডিটরস)' },
    'pl.group_customers': { en: 'Customers (Sundry Debtors)', bn: 'গ্রাহক (সান্ড্রি ডেটরস)' },
    'pl.group_employees': { en: 'Employees', bn: 'কর্মী' },
    'pl.name_placeholder': { en: 'Name as it should print on the voucher', bn: 'ভাউচারে যে নাম ছাপা হবে' },
    'pl.party_hint': {
        en: 'Picking a supplier, customer or employee puts their ledger on the first line.',
        bn: 'সরবরাহকারী, গ্রাহক বা কর্মী বাছলে প্রথম লাইনে তার লেজার বসে যায়।',
    },

    // ── Bill-wise details ───────────────────────────────────────────────
    'pl.bill_wise': { en: 'Bill-wise details', bn: 'বিল অনুযায়ী বিবরণ' },
    'pl.loading': { en: 'Loading what is open…', bn: 'খোলা হিসাব আনা হচ্ছে…' },
    'pl.oldest_first': { en: 'Oldest first (automatic)', bn: 'পুরনোটা আগে (স্বয়ংক্রিয়)' },
    'pl.choose_myself': { en: 'Choose bills myself', bn: 'নিজে বিল বাছব' },
    'pl.use_oldest': { en: 'Back to oldest first', bn: 'আবার পুরনোটা আগে' },
    'pl.col_against': { en: 'Against', bn: 'যার বিপরীতে' },
    'pl.col_date': { en: 'Date', bn: 'তারিখ' },
    'pl.col_open': { en: 'Open', bn: 'বাকি' },
    'pl.col_this': { en: 'This voucher', bn: 'এই ভাউচারে' },
    'pl.advance_row': { en: 'Advance (no bill yet)', bn: 'অগ্রিম (এখনও বিল নেই)' },
    'pl.not_covered': {
        en: '{amount} is more than is open. Lower the amount; posting will refuse it.',
        bn: '{amount} খোলা হিসাবের চেয়ে বেশি। পরিমাণ কমান; পোস্ট করলে এটি নেবে না।',
    },
    'pl.over_line': {
        en: 'The amounts here come to more than the line. Make the line amount match.',
        bn: 'এখানের পরিমাণ লাইনের চেয়ে বেশি। লাইনের পরিমাণ মিলিয়ে দিন।',
    },
    'pl.nothing_open_payment': { en: 'No open bill: all of it is an advance.', bn: 'কোনো খোলা বিল নেই: পুরোটাই অগ্রিম।' },
    'pl.nothing_open_receipt': { en: 'No open invoice: all of it is an advance.', bn: 'কোনো খোলা ইনভয়েস নেই: পুরোটাই অগ্রিম।' },
    'pl.nothing_open_supplier_refund': {
        en: 'They owe us nothing back and hold no advance of ours.',
        bn: 'তারা আমাদের কিছু ফেরত দেবে না, আমাদের কোনো অগ্রিমও তাদের কাছে নেই।',
    },
    'pl.nothing_open_customer_refund': {
        en: 'Nothing of theirs can be refunded: no advance, return or paid order.',
        bn: 'তাদের ফেরতযোগ্য কিছু নেই: কোনো অগ্রিম, রিটার্ন বা পরিশোধিত অর্ডার নেই।',
    },
    'pl.nothing_open_adjustment': { en: 'No open invoice or bill to adjust.', bn: 'সমন্বয়ের মতো কোনো খোলা ইনভয়েস বা বিল নেই।' },
    'pl.mode_supplier_refund': {
        en: 'Money back from the supplier: what they owe us, then advances they return.',
        bn: 'সরবরাহকারী থেকে টাকা ফেরত: তারা যা দেনা, তারপর ফেরত দেওয়া অগ্রিম।',
    },
    'pl.mode_customer_refund': {
        en: 'Money back to the customer: their advance, a return, or an order they paid for.',
        bn: 'গ্রাহককে টাকা ফেরত: তাদের অগ্রিম, রিটার্ন, বা পরিশোধিত অর্ডার।',
    },
    'pl.mode_adjustment': {
        en: 'Posted as a write-off, discount or set-off against these documents.',
        bn: 'এই কাগজগুলোর বিপরীতে রাইট-অফ, ছাড় বা সেট-অফ হিসেবে পোস্ট হবে।',
    },

    // ── Tax held back ───────────────────────────────────────────────────
    'pl.tds': { en: 'TDS held back', bn: 'উৎসে কর (TDS) কর্তন' },
    'pl.vds': { en: 'VDS held back', bn: 'উৎসে ভ্যাট (VDS) কর্তন' },
    'pl.no_tax': { en: 'None', bn: 'নেই' },
    'pl.tax_line': { en: '{kind} {amount}', bn: '{kind} {amount}' },
    'pl.tax_total': { en: 'Tax held back', bn: 'কর্তিত কর' },
    'pl.bank_pays': { en: 'Paid from the account', bn: 'হিসাব থেকে পরিশোধ' },

    // ── Ledgers and groups ──────────────────────────────────────────────
    'pl.customer_ledger': { en: 'Customer ledger', bn: 'গ্রাহক লেজার' },
    'pl.choose_customer': { en: 'Choose a customer to see their ledger.', bn: 'লেজার দেখতে একজন গ্রাহক বাছুন।' },
    'pl.sundry_creditors': { en: 'Sundry Creditors', bn: 'সান্ড্রি ক্রেডিটরস' },
    'pl.sundry_debtors': { en: 'Sundry Debtors', bn: 'সান্ড্রি ডেটরস' },
    'pl.group_creditors_hint': {
        en: 'Every supplier as a ledger: Accounts Payable and advances paid, with the supplier named on each line. Click a name for their ledger.',
        bn: 'প্রত্যেক সরবরাহকারী একটি লেজার: দেনা ও দেওয়া অগ্রিম, প্রতিটি লাইনে সরবরাহকারীর নাম। লেজার দেখতে নামে চাপুন।',
    },
    'pl.group_debtors_hint': {
        en: 'Every customer as a ledger: Accounts Receivable and advances received, with the customer named on each line. Click a name for their ledger.',
        bn: 'প্রত্যেক গ্রাহক একটি লেজার: পাওনা ও নেওয়া অগ্রিম, প্রতিটি লাইনে গ্রাহকের নাম। লেজার দেখতে নামে চাপুন।',
    },
    'pl.col_party': { en: 'Ledger', bn: 'লেজার' },
    'pl.col_opening': { en: 'Opening', bn: 'প্রারম্ভিক' },
    'pl.col_debit': { en: 'Debit', bn: 'ডেবিট' },
    'pl.col_credit': { en: 'Credit', bn: 'ক্রেডিট' },
    'pl.col_closing': { en: 'Closing', bn: 'সমাপনী' },
    'pl.group_total': { en: 'Group total', bn: 'গ্রুপের মোট' },
    'pl.ties_out': { en: 'The group total equals the control accounts in the ledger.', bn: 'গ্রুপের মোট লেজারের কন্ট্রোল হিসাবের সমান।' },
    'pl.not_tied': {
        en: 'The group total does not equal the control accounts: {amount} sits on them with no party named. Run the reconciliation.',
        bn: 'গ্রুপের মোট কন্ট্রোল হিসাবের সমান নয়: {amount} কোনো পক্ষের নাম ছাড়া আছে। রিকনসিলিয়েশন চালান।',
    },
    'pl.show_all': { en: 'Show parties with no balance too', bn: 'ব্যালান্স ছাড়া পক্ষগুলোও দেখান' },
    'pl.open_group_creditors': { en: 'See every supplier (Sundry Creditors)', bn: 'সব সরবরাহকারী দেখুন (সান্ড্রি ক্রেডিটরস)' },
    'pl.open_group_debtors': { en: 'See every customer (Sundry Debtors)', bn: 'সব গ্রাহক দেখুন (সান্ড্রি ডেটরস)' },
    'pl.nobody': { en: 'Nobody has a balance or a posting in this period.', bn: 'এই সময়ে কারও ব্যালান্স বা পোস্টিং নেই।' },
} satisfies Record<string, Entry>;
