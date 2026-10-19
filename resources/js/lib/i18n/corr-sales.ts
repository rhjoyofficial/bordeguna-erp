import type { Entry } from './entry';

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): the sales documents' correction buttons,
 * dialogs and the two Correct pages (orders, challans, invoices, receipts,
 * the counter's Void sale). Latin digits; money and dates never translate.
 */
export const corrSales = {
    // ── Shared ──────────────────────────────────────────────────────────
    'corr.sales.reason': { en: 'Reason', bn: 'কারণ' },
    'corr.sales.reason_pick': { en: 'Pick one (optional)', bn: 'একটি বেছে নিন (ঐচ্ছিক)' },
    'corr.sales.reason_words': { en: 'In your words', bn: 'নিজের ভাষায় লিখুন' },
    'corr.sales.reason_hint': { en: 'Say why in at least 5 characters.', bn: 'কমপক্ষে 5 অক্ষরে কারণ লিখুন।' },
    'corr.sales.go_back': { en: 'Go back', bn: 'ফিরে যান' },
    'corr.sales.working': { en: 'Working...', bn: 'কাজ চলছে...' },
    'corr.sales.amount': { en: 'Amount', bn: 'পরিমাণ (টাকা)' },
    'corr.sales.reversed': { en: 'Reversed', bn: 'রিভার্স করা' },
    'corr.sales.preview_correct': { en: 'Preview and correct', bn: 'দেখে নিন ও সংশোধন করুন' },
    'corr.sales.stays_note': {
        en: 'Nothing is deleted: the old record stays, marked, and the History shows who did what and why.',
        bn: 'কিছুই মুছে ফেলা হয় না: পুরনো রেকর্ড চিহ্নিত হয়ে থাকে, আর ইতিহাসে দেখা যায় কে, কী ও কেন করেছেন।',
    },

    // ── Sales order ─────────────────────────────────────────────────────
    'corr.sales.order.edit': { en: 'Edit', bn: 'এডিট' },
    'corr.sales.order.edit_undelivered': { en: 'Edit undelivered part', bn: 'বাকি অংশ এডিট' },
    'corr.sales.order.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'corr.sales.order.close': { en: 'Close short', bn: 'কম রেখে বন্ধ' },
    'corr.sales.order.copy': { en: 'Copy', bn: 'কপি' },
    'corr.sales.order.cancel_title': { en: 'Cancel {number}?', bn: '{number} বাতিল করবেন?' },
    'corr.sales.order.cancel_text': {
        en: 'The order stays, marked Cancelled, with your reason. Nothing was delivered, so no stock or money moves.',
        bn: 'অর্ডারটি থেকে যাবে, বাতিল চিহ্নসহ, আপনার কারণসহ। কিছুই ডেলিভারি হয়নি, তাই স্টক বা টাকা নড়বে না।',
    },
    'corr.sales.order.close_title': { en: 'Close {number} short?', bn: '{number} কম রেখে বন্ধ করবেন?' },
    'corr.sales.order.close_text': {
        en: 'What was delivered stays; the rest stops being owed. The order stays, marked Closed, with your reason.',
        bn: 'যা ডেলিভারি হয়েছে তা থাকবে; বাকিটা আর পাওনা থাকবে না। অর্ডারটি বন্ধ চিহ্নসহ, আপনার কারণসহ থাকবে।',
    },
    'corr.sales.order.editing': { en: 'Editing {number}', bn: '{number} এডিট করা হচ্ছে' },
    'corr.sales.order.editing_note': {
        en: 'Change what was wrong and save. If the total goes up, or the customer or the items change, the order goes for approval again.',
        bn: 'যা ভুল তা বদলে সেভ করুন। মোট বাড়লে, বা কাস্টমার বা পণ্য বদলালে, অর্ডারটি আবার অনুমোদনে যাবে।',
    },
    'corr.sales.order.editing_undelivered': {
        en: 'Part of this order has left: delivered lines keep their product and price, and no line can go below what left. The customer and the order date stay.',
        bn: 'এই অর্ডারের কিছু অংশ চলে গেছে: ডেলিভারি হওয়া লাইনের পণ্য ও দাম বদলাবে না, আর কোনো লাইন চলে যাওয়া পরিমাণের কম হবে না। কাস্টমার ও অর্ডারের তারিখ একই থাকবে।',
    },
    'corr.sales.order.copying': { en: 'New order, copied from {number}', bn: 'নতুন অর্ডার, {number} থেকে কপি' },
    'corr.sales.order.save': { en: 'Save changes', bn: 'পরিবর্তন সেভ করুন' },
    'corr.sales.order.delivered_already': { en: '{quantity} already left', bn: '{quantity} ইতিমধ্যে চলে গেছে' },

    // ── Challan ─────────────────────────────────────────────────────────
    'corr.sales.challan.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'corr.sales.challan.reversed_note': {
        en: 'Reversed: its goods are back in stock and its quantities back on the order.',
        bn: 'রিভার্স করা হয়েছে: এর পণ্য স্টকে ফিরে গেছে, আর পরিমাণ অর্ডারে ফিরে গেছে।',
    },

    // ── Sales invoice ───────────────────────────────────────────────────
    'corr.sales.invoice.credit_note': { en: 'Credit note', bn: 'ক্রেডিট নোট' },
    'corr.sales.invoice.correct': { en: 'Correct', bn: 'সংশোধন' },
    'corr.sales.invoice.void_sale': { en: 'Void sale', bn: 'বিক্রি বাতিল' },
    'corr.sales.invoice.price_credit': { en: 'Price credit', bn: 'দামে ক্রেডিট' },
    'corr.sales.invoice.extra_charge': { en: 'Extra charge', bn: 'বাড়তি চার্জ' },
    'corr.sales.invoice.corrections': { en: 'Corrections', bn: 'সংশোধন' },
    'corr.sales.invoice.credited_banner': {
        en: 'This invoice was taken back whole by a credit note. It stays exactly as issued and owes nothing.',
        bn: 'একটি ক্রেডিট নোটে এই ইনভয়েস পুরোটা ফেরত নেওয়া হয়েছে। এটি যেমন দেওয়া হয়েছিল তেমনই থাকবে, আর কিছু পাওনা নেই।',
    },
    'corr.sales.invoice.corrected_to': { en: 'Corrected: see {number}', bn: 'সংশোধিত: দেখুন {number}' },
    'corr.sales.invoice.extra_on': { en: 'Extra charge on {number}', bn: '{number}-এর উপর বাড়তি চার্জ' },
    'corr.sales.invoice.credit_notes': { en: 'Credit notes', bn: 'ক্রেডিট নোট' },
    'corr.sales.invoice.extra_charges': { en: 'Extra charges', bn: 'বাড়তি চার্জ' },
    'corr.sales.invoice.kind_full': { en: 'Full', bn: 'পুরো' },
    'corr.sales.invoice.kind_price': { en: 'Price only', bn: 'শুধু দাম' },
    'corr.sales.invoice.price_credit_title': { en: 'Price credit on {number}', bn: '{number}-এ দামে ক্রেডিট' },
    'corr.sales.invoice.price_credit_text': {
        en: 'The customer keeps the goods and owes less. A credit note with its own CN number cites this invoice; the invoice itself does not change.',
        bn: 'কাস্টমার পণ্য রাখবেন আর কম টাকা দেবেন। নিজস্ব CN নম্বরসহ একটি ক্রেডিট নোট এই ইনভয়েসের উল্লেখ করবে; ইনভয়েসটি নিজে বদলাবে না।',
    },
    'corr.sales.invoice.credit_date': { en: 'Credit note date', bn: 'ক্রেডিট নোটের তারিখ' },
    'corr.sales.invoice.issue_credit': { en: 'Issue credit note', bn: 'ক্রেডিট নোট দিন' },
    'corr.sales.invoice.extra_title': { en: 'Extra charge on {number}', bn: '{number}-এ বাড়তি চার্জ' },
    'corr.sales.invoice.extra_text': {
        en: 'The customer owes more than this invoice billed. A new invoice of its own is raised on it; this invoice does not change.',
        bn: 'এই ইনভয়েসে যা বিল হয়েছে কাস্টমারের তার চেয়ে বেশি পাওনা। এর উপর নিজস্ব একটি নতুন ইনভয়েস হবে; এই ইনভয়েস বদলাবে না।',
    },
    'corr.sales.invoice.charge_date': { en: 'Charge date', bn: 'চার্জের তারিখ' },
    'corr.sales.invoice.charge_for': { en: 'What it is for', bn: 'কীসের জন্য' },
    'corr.sales.invoice.raise_extra': { en: 'Raise extra charge', bn: 'বাড়তি চার্জ দিন' },
    'corr.sales.invoice.correct_title': { en: 'Correct {number}', bn: '{number} সংশোধন' },
    'corr.sales.invoice.correct_banner': {
        en: 'You are correcting {number}. Change what was wrong. A credit note takes {number} back whole, and the new invoice bills the same challans; payments on it move to the new invoice.',
        bn: 'আপনি {number} সংশোধন করছেন। যা ভুল তা বদলান। একটি ক্রেডিট নোট {number} পুরোটা ফেরত নেবে, আর নতুন ইনভয়েস একই চালান বিল করবে; এর উপর থাকা পেমেন্ট নতুন ইনভয়েসে যাবে।',
    },
    'corr.sales.invoice.invoice_date': { en: 'Invoice date', bn: 'ইনভয়েসের তারিখ' },
    'corr.sales.invoice.due_date': { en: 'Due date', bn: 'পরিশোধের শেষ তারিখ' },
    'corr.sales.invoice.discount_whole': { en: 'Discount on the whole bill', bn: 'পুরো বিলে ছাড়' },
    'corr.sales.invoice.note': { en: 'Note', bn: 'নোট' },
    'corr.sales.invoice.col_item': { en: 'Item', bn: 'পণ্য' },
    'corr.sales.invoice.col_challan': { en: 'Challan', bn: 'চালান' },
    'corr.sales.invoice.col_qty': { en: 'Quantity', bn: 'পরিমাণ' },
    'corr.sales.invoice.col_price': { en: 'Unit price', bn: 'একক দাম' },
    'corr.sales.invoice.col_discount': { en: 'Discount', bn: 'ছাড়' },
    'corr.sales.invoice.col_total': { en: 'Line total', bn: 'লাইনের মোট' },
    'corr.sales.invoice.new_total': { en: 'New goods total', bn: 'নতুন পণ্যের মোট' },
    'corr.sales.invoice.delivery_again': {
        en: 'The delivery charge is billed again by itself: {list}.',
        bn: 'ডেলিভারি চার্জ নিজে থেকেই আবার বিল হবে: {list}।',
    },
    'corr.sales.invoice.no_price_change': {
        en: 'Your role may change the date, the quantities and the note; a different price or discount needs the price-override permission.',
        bn: 'আপনার ভূমিকায় তারিখ, পরিমাণ ও নোট বদলানো যায়; ভিন্ন দাম বা ছাড়ের জন্য দাম বদলের অনুমতি লাগবে।',
    },
    'corr.sales.invoice.badge_credited': { en: 'Credited', bn: 'ক্রেডিট দেওয়া' },

    // ── Customer receipt ────────────────────────────────────────────────
    'corr.sales.receipt.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'corr.sales.receipt.correct': { en: 'Correct', bn: 'সংশোধন' },
    'corr.sales.receipt.reallocate': { en: 'Re-allocate', bn: 'অন্য ইনভয়েসে সরান' },
    'corr.sales.receipt.reallocate_title': { en: 'Move money from {label} to another invoice', bn: '{label}-এর টাকা অন্য ইনভয়েসে সরান' },
    'corr.sales.receipt.reallocate_text': {
        en: "Only between this customer's invoices. No money moves in the books: only which invoice it pays.",
        bn: 'শুধু এই কাস্টমারের ইনভয়েসগুলোর মধ্যে। হিসাবে কোনো টাকা নড়বে না: শুধু কোন ইনভয়েস শোধ হবে তা বদলাবে।',
    },
    'corr.sales.receipt.from_invoice': { en: 'From invoice', bn: 'যে ইনভয়েস থেকে' },
    'corr.sales.receipt.to_invoice': { en: 'To invoice', bn: 'যে ইনভয়েসে' },
    'corr.sales.receipt.choose_invoice': { en: 'Choose an invoice', bn: 'একটি ইনভয়েস বেছে নিন' },
    'corr.sales.receipt.move': { en: 'Move the money', bn: 'টাকা সরান' },
    'corr.sales.receipt.correct_title': { en: 'Correct {label}', bn: '{label} সংশোধন' },
    'corr.sales.receipt.correct_banner': {
        en: 'You are correcting {label}. Change what was wrong. It stays, marked Corrected, and the right receipt takes its place on the date you type.',
        bn: 'আপনি {label} সংশোধন করছেন। যা ভুল তা বদলান। এটি সংশোধিত চিহ্নসহ থাকবে, আর আপনার লেখা তারিখে সঠিক রসিদ এর জায়গা নেবে।',
    },
    'corr.sales.receipt.received_on': { en: 'Date the money arrived', bn: 'টাকা আসার তারিখ' },
    'corr.sales.receipt.customer': { en: 'Customer', bn: 'কাস্টমার' },
    'corr.sales.receipt.into': { en: 'Received into', bn: 'যে অ্যাকাউন্টে এসেছে' },
    'corr.sales.receipt.reference': { en: 'Reference', bn: 'রেফারেন্স' },
    'corr.sales.receipt.pays': { en: 'Which invoices it pays', bn: 'কোন ইনভয়েস শোধ করে' },
    'corr.sales.receipt.owes': { en: 'owes {amount}', bn: '{amount} বাকি' },
    'corr.sales.receipt.unallocated': { en: 'Not on any invoice: {amount}', bn: 'কোনো ইনভয়েসে নেই: {amount}' },
    'corr.sales.receipt.released': { en: 'It had paid: {list}', bn: 'এটি শোধ করেছিল: {list}' },
} satisfies Record<string, Entry>;
