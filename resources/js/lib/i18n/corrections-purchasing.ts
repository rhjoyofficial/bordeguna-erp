import type { Entry } from './entry';

/**
 * ⭐ Corrections wave 1, purchase documents (19 Oct 2026): the buttons and
 * dialogs of a purchase order (Withdraw, Edit, Cancel, Copy, Fix details) and
 * a goods receipt (Fix batch details, Correct, Reverse). The button words are
 * fixed (FINAL-PLAN 2). Numbers, dates and money never translate.
 */
export const correctionsPurchasing = {
    // ── Shared ──────────────────────────────────────────────────────────
    'corr.reason': { en: 'Reason', bn: 'কারণ' },
    'corr.reason_pick': { en: 'Pick one (optional)', bn: 'একটি বাছুন (ঐচ্ছিক)' },
    'corr.reason_words': { en: 'In your words', bn: 'আপনার ভাষায়' },
    'corr.reason_hint': { en: 'Say why in at least 5 characters.', bn: 'অন্তত 5 অক্ষরে কারণ লিখুন।' },
    'corr.go_back': { en: 'Go back', bn: 'ফিরে যান' },
    'corr.history': { en: 'History', bn: 'ইতিহাস' },
    'corr.edited': { en: 'Edited', bn: 'সংশোধিত' },
    'corr.reversed': { en: 'Reversed', bn: 'বাতিল (রিভার্স)' },
    'corr.corrected': { en: 'Corrected', bn: 'সংশোধন করা হয়েছে' },
    'corr.correction_of': { en: 'Correction of {label}', bn: '{label}-এর সংশোধন' },
    'corr.see': { en: 'see {label}', bn: '{label} দেখুন' },
    'corr.show_corrections': { en: 'Show corrections', bn: 'সংশোধনগুলো দেখান' },
    'corr.stays': { en: 'The record stays, marked {state}, with your reason.', bn: 'রেকর্ডটি থাকবে, {state} চিহ্নসহ, আপনার কারণসহ।' },

    // ── Purchase order ──────────────────────────────────────────────────
    'corr.po.withdraw': { en: 'Withdraw', bn: 'ফেরত নিন' },
    'corr.po.withdraw_title': { en: 'Withdraw {number}?', bn: '{number} ফেরত নেবেন?' },
    'corr.po.withdraw_text': {
        en: 'It comes back to you as a draft, with the same number. Edit it and save to send it for approval again.',
        bn: 'এটি একই নম্বরে খসড়া হিসেবে আপনার কাছে ফিরবে। সম্পাদনা করে সেভ করলে আবার অনুমোদনে যাবে।',
    },
    'corr.po.edit': { en: 'Edit', bn: 'সম্পাদনা' },
    'corr.po.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'corr.po.cancel_title': { en: 'Cancel {number}?', bn: '{number} বাতিল করবেন?' },
    'corr.po.cancel_text': {
        en: 'The record stays, marked Cancelled, with your reason. No more goods can be received against it. Copy opens a new one.',
        bn: 'রেকর্ডটি থাকবে, বাতিল চিহ্নসহ, আপনার কারণসহ। এর বিপরীতে আর মাল গ্রহণ করা যাবে না। কপি দিয়ে নতুন একটি খোলা যায়।',
    },
    'corr.po.keep': { en: 'Keep order', bn: 'অর্ডার রাখুন' },
    'corr.po.copy': { en: 'Copy', bn: 'কপি' },
    'corr.po.fix_details': { en: 'Fix details', bn: 'বিবরণ ঠিক করুন' },
    'corr.po.fix_title': { en: 'Fix details of {number}', bn: '{number}-এর বিবরণ ঠিক করুন' },
    'corr.po.fix_text': {
        en: 'Dates and the note only: nothing received, billed or paid changes. Before and after go to the History.',
        bn: 'শুধু তারিখ ও নোট: গ্রহণ, বিল বা পরিশোধ কিছুই বদলাবে না। আগে-পরে ইতিহাসে থাকবে।',
    },
    'corr.po.order_date': { en: 'Order date', bn: 'অর্ডারের তারিখ' },
    'corr.po.expected_date': { en: 'Expected date', bn: 'প্রত্যাশিত তারিখ' },
    'corr.po.note': { en: 'Note', bn: 'নোট' },
    'corr.po.supplier': { en: 'Supplier (a clerical slip, only while unbilled)', bn: 'সরবরাহকারী (শুধু লেখার ভুল, বিল হওয়ার আগে পর্যন্ত)' },
    'corr.po.supplier_same': { en: 'Keep the supplier', bn: 'সরবরাহকারী একই রাখুন' },
    'corr.po.save_fix': { en: 'Fix details', bn: 'বিবরণ ঠিক করুন' },
    'corr.po.copy_banner': {
        en: 'A new order filled from {number}. Nothing of {number} changes; this one gets its own number when saved.',
        bn: '{number} থেকে পূরণ করা নতুন অর্ডার। {number}-এর কিছুই বদলাবে না; সেভ করলে এটি নিজস্ব নম্বর পাবে।',
    },
    'corr.po.edit_reason': {
        en: 'This order is approved. Say why it is changing: approval runs again if the total goes up or the supplier or items change.',
        bn: 'এই অর্ডারটি অনুমোদিত। কেন বদলাচ্ছে লিখুন: মোট বাড়লে বা সরবরাহকারী বা পণ্য বদলালে আবার অনুমোদন লাগবে।',
    },

    // ── Goods receipt ───────────────────────────────────────────────────
    'corr.grn.fix_batch': { en: 'Fix batch details', bn: 'ব্যাচের বিবরণ ঠিক করুন' },
    'corr.grn.fix_title': { en: 'Fix batch details of {label}', bn: '{label}-এর ব্যাচের বিবরণ ঠিক করুন' },
    'corr.grn.fix_text': {
        en: 'For the same goods typed wrong at the door. FEFO and the expired-stock check follow the new dates. A different lot is a Correct.',
        bn: 'একই মাল, গ্রহণের সময় ভুল লেখা হলে। FEFO ও মেয়াদোত্তীর্ণ মালের হিসাব নতুন তারিখ মেনে চলবে। ভিন্ন লট হলে সংশোধন করুন।',
    },
    'corr.grn.challan': { en: 'Supplier challan no.', bn: 'সরবরাহকারীর চালান নং' },
    'corr.grn.note': { en: 'Note', bn: 'নোট' },
    'corr.grn.batch_number': { en: 'Batch no.', bn: 'ব্যাচ নং' },
    'corr.grn.mfg': { en: 'Manufactured', bn: 'উৎপাদন' },
    'corr.grn.expiry': { en: 'Expiry', bn: 'মেয়াদ' },
    'corr.grn.correct': { en: 'Correct', bn: 'সংশোধন' },
    'corr.grn.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'corr.grn.correct_title': { en: 'Correct {label}', bn: '{label} সংশোধন' },
    'corr.grn.correct_banner': {
        en: 'You are correcting {label}. Change the date only, and the same batch moves to that day (allowed when billed, paid or partly sold). Change a quantity or tick "Different lot", and the goods go back out and come in again (only while untouched and unbilled).',
        bn: 'আপনি {label} সংশোধন করছেন। শুধু তারিখ বদলালে একই ব্যাচ সেই দিনে সরে যাবে (বিল, পরিশোধ বা আংশিক বিক্রি হলেও চলে)। পরিমাণ বদলালে বা "ভিন্ন লট" টিক দিলে মাল বের হয়ে আবার ঢুকবে (শুধু অক্ষত ও বিলহীন থাকলে)।',
    },
    'corr.grn.received_on': { en: 'Received on', bn: 'গ্রহণের তারিখ' },
    'corr.grn.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'corr.grn.different_lot': { en: 'Different lot', bn: 'ভিন্ন লট' },
    'corr.grn.preview_correct': { en: 'Preview and correct', bn: 'দেখে সংশোধন করুন' },
    'corr.grn.price_note': {
        en: "The cost is the order's price. A wrong price is fixed on the bill, or by reversing this receipt, editing the order and receiving again.",
        bn: 'দাম অর্ডারের দাম। ভুল দাম বিলে ঠিক হয়, অথবা এই গ্রহণ রিভার্স করে, অর্ডার সম্পাদনা করে আবার গ্রহণ করে।',
    },
} satisfies Record<string, Entry>;
