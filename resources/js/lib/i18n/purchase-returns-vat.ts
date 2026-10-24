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
    // ⭐ B9: the Return popup on the goods receipt and the supplier bill.
    'pret.button': { en: 'Return', bn: 'ফেরত' },
    'pret.used_up': {
        en: 'Nothing from this delivery is left in stock to send back: it has been used or sold.',
        bn: 'এই ডেলিভারির কিছুই স্টকে নেই যা ফেরত দেওয়া যায়: ব্যবহার বা বিক্রি হয়ে গেছে।',
    },
    'pret.title': { en: 'Return goods to the supplier ({po})', bn: 'সরবরাহকারীকে মাল ফেরত ({po})' },
    'pret.intro': {
        en: 'The goods go back from the batches this delivery made. A batch already used up cannot go back.',
        bn: 'এই ডেলিভারির ব্যাচ থেকেই মাল ফেরত যাবে। যে ব্যাচ শেষ হয়ে গেছে তা ফেরত দেওয়া যায় না।',
    },
    'pret.date': { en: 'Date returned', bn: 'ফেরতের তারিখ' },
    'pret.col_item': { en: 'Item', bn: 'পণ্য' },
    'pret.col_batch': { en: 'Batch', bn: 'ব্যাচ' },
    'pret.col_can': { en: 'Can go back', bn: 'ফেরত দেওয়া যায়' },
    'pret.col_qty': { en: 'Return now', bn: 'এখন ফেরত' },
    'pret.none_left': { en: 'used up', bn: 'শেষ' },
    'pret.landed_hint': {
        en: 'Carries Tk {amount} per {unit} of extra cost (carrying, labour...): written off as a loss.',
        bn: 'প্রতি {unit}-এ ৳ {amount} বাড়তি খরচ (পরিবহন, মজুরি...) আছে: ক্ষতি হিসেবে লেখা হবে।',
    },
    'pret.reason': { en: 'Why the goods go back', bn: 'কেন মাল ফেরত যাচ্ছে' },
    'pret.value': { en: 'Value at the order price', bn: 'অর্ডারের দামে মূল্য' },
    'pret.reduces_bill': { en: 'Reduces what the bill still owes', bn: 'বিলের বাকি থেকে কমবে' },
    'pret.paid_back': { en: 'Already paid: the supplier owes us', bn: 'আগেই পরিশোধিত: সরবরাহকারী আমাদের দেবে' },
    'pret.landed_loss': { en: 'Extra cost written off (loss)', bn: 'বাড়তি খরচ বাদ (ক্ষতি)' },
    'pret.unbilled_hint': {
        en: 'Not billed yet: nothing is owed back, and the bill, when it comes, is for less.',
        bn: 'এখনও বিল হয়নি: কিছু ফেরত পাওনা নেই, বিল এলে তা কম হবে।',
    },
    'pret.no_credit_permission': {
        en: 'These goods were billed; recording the credit needs the credit note permission (Finance or the Owner).',
        bn: 'এই মালের বিল হয়েছে; ক্রেডিট লিখতে ক্রেডিট নোটের অনুমতি লাগবে (ফাইন্যান্স বা মালিক)।',
    },
    'pret.cn_number': { en: "Supplier's debit note number (optional)", bn: 'সরবরাহকারীর ডেবিট নোট নম্বর (ঐচ্ছিক)' },
    'pret.settle_title': { en: 'The supplier owes back Tk {amount}', bn: 'সরবরাহকারী ৳ {amount} ফেরত দেবে' },
    'pret.settle_advance': {
        en: 'Keep it as an advance: offered on the next purchase from him',
        bn: 'অগ্রিম হিসেবে রাখুন: তার কাছ থেকে পরের কেনায় কাটা যাবে',
    },
    'pret.settle_refund': { en: 'He pays it back now (refund)', bn: 'এখনই ফেরত দিচ্ছেন (রিফান্ড)' },
    'pret.no_refund_permission': { en: '(needs the advance permission)', bn: '(অগ্রিমের অনুমতি লাগবে)' },
    'pret.refund_into': { en: 'Into which account', bn: 'কোন হিসাবে' },
    'pret.refund_amount': { en: 'Amount', bn: 'পরিমাণ' },
    'pret.refund_add': { en: 'Another account', bn: 'আরেকটি হিসাব' },
    'pret.refund_remove': { en: 'Remove this line', bn: 'এই লাইন বাদ দিন' },
    'pret.refund_left': {
        en: 'Not refunded here: Tk {amount}, kept as an advance.',
        bn: 'এখানে ফেরত হয়নি: ৳ {amount}, অগ্রিম হিসেবে থাকবে।',
    },
    'pret.kept_advance': { en: 'kept as an advance, Tk {open} still open', bn: 'অগ্রিম হিসেবে রাখা, ৳ {open} এখনও খোলা' },
    'pret.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'pret.save': { en: 'Record the return', bn: 'ফেরত লিখুন' },
    // Employee statement (R63): his Employee cash account beside his other ledgers.
    'estmt.cash_tile': { en: 'Company cash: {account}', bn: 'কোম্পানির নগদ: {account}' },
    'estmt.cash_holds': { en: 'holds company cash of', bn: 'কোম্পানির নগদ হাতে আছে' },
    'estmt.cash_owed': { en: 'is owed by the company', bn: 'কোম্পানির কাছে পাওনা' },
} satisfies Record<string, Entry>;
