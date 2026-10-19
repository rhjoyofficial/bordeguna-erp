import type { Entry } from './entry';

/**
 * Corrections wave 1, B2 (20 Oct 2026): the correction buttons and Correct
 * forms of supplier bills, payments, credit notes and purchase returns.
 * Latin digits; amounts and dates never translate.
 */
export const corrSupplier = {
    'corr.sup.correct': { en: 'Correct', bn: 'সংশোধন' },
    'corr.sup.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'corr.sup.fix_details': { en: 'Fix details', bn: 'তথ্য ঠিক করুন' },
    'corr.sup.preview_and_correct': { en: 'Preview and correct', bn: 'দেখে নিয়ে সংশোধন করুন' },
    'corr.sup.go_back': { en: 'Go back', bn: 'ফিরে যান' },
    'corr.sup.save': { en: 'Save', bn: 'সংরক্ষণ' },
    'corr.sup.correction_of': { en: 'Correction of {number}', bn: '{number}-এর সংশোধন' },
    'corr.sup.corrected_into': { en: 'Corrected. See {number}.', bn: 'সংশোধিত। দেখুন {number}।' },
    'corr.sup.banner': {
        en: 'You are correcting {number} ({amount}). Change what was wrong. {number} stays, marked Corrected, and a new record takes its place.',
        bn: 'আপনি {number} ({amount}) সংশোধন করছেন। যা ভুল ছিল তা বদলান। {number} থেকে যাবে, "সংশোধিত" চিহ্নসহ, আর তার জায়গায় নতুন রেকর্ড হবে।',
    },
    'corr.sup.paid_moves': {
        en: '{amount} is already paid or credited on this bill. It moves to the corrected bill; no payment is reversed.',
        bn: 'এই বিলে {amount} আগেই পরিশোধ বা ক্রেডিট হয়েছে। তা সংশোধিত বিলে চলে যাবে; কোনো পেমেন্ট রিভার্স হবে না।',
    },
    'corr.sup.bill_date': { en: 'Bill date', bn: 'বিলের তারিখ' },
    'corr.sup.due_date': { en: 'Due date', bn: 'পরিশোধের শেষ তারিখ' },
    'corr.sup.due_date_help': { en: 'Leave empty to work it out from the terms.', bn: 'শর্ত থেকে হিসাব করতে খালি রাখুন।' },
    'corr.sup.bill_number': { en: "Supplier's bill number", bn: 'সরবরাহকারীর বিল নম্বর' },
    'corr.sup.note': { en: 'Note', bn: 'নোট' },
    'corr.sup.goods': { en: 'Goods on the bill', bn: 'বিলের পণ্য' },
    'corr.sup.services': { en: 'Service lines', bn: 'সেবার লাইন' },
    'corr.sup.product': { en: 'Product', bn: 'পণ্য' },
    'corr.sup.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'corr.sup.up_to': { en: 'up to {quantity}', bn: 'সর্বোচ্চ {quantity}' },
    'corr.sup.unit_price': { en: 'Unit price', bn: 'একক দাম' },
    'corr.sup.line_total': { en: 'Line total', bn: 'লাইনের মোট' },
    'corr.sup.remove': { en: 'Remove', bn: 'বাদ দিন' },
    'corr.sup.add_service': { en: 'Add a service line', bn: 'সেবার লাইন যোগ করুন' },
    'corr.sup.expense_head': { en: 'Expense head', bn: 'খরচের খাত' },
    'corr.sup.choose_head': { en: 'Choose a head', bn: 'খাত বেছে নিন' },
    'corr.sup.description': { en: 'What it was for', bn: 'কিসের জন্য' },
    'corr.sup.amount': { en: 'Amount', bn: 'পরিমাণ (টাকা)' },
    'corr.sup.new_total': { en: 'New total', bn: 'নতুন মোট' },
    'corr.sup.paid_on': { en: 'Date paid', bn: 'পরিশোধের তারিখ' },
    'corr.sup.paid_from': { en: 'Paid from', bn: 'যে অ্যাকাউন্ট থেকে' },
    'corr.sup.choose_account': { en: 'Choose an account', bn: 'অ্যাকাউন্ট বেছে নিন' },
    'corr.sup.reference': { en: 'Reference', bn: 'রেফারেন্স' },
    'corr.sup.bills_paid': { en: 'Which bills, and how much of each', bn: 'কোন বিল, প্রতিটিতে কত' },
    'corr.sup.bill': { en: 'Bill', bn: 'বিল' },
    'corr.sup.open': { en: 'Open', bn: 'বাকি' },
    'corr.sup.credit_date': { en: 'Credit note date', bn: 'ক্রেডিট নোটের তারিখ' },
    'corr.sup.credit_number': { en: "Supplier's credit note number", bn: 'সরবরাহকারীর ক্রেডিট নোট নম্বর' },
    'corr.sup.bills_credited': { en: 'Which bills it reduces, and by how much', bn: 'কোন বিল কতটা কমাবে' },
    'corr.sup.fix_title': { en: 'Fix details of {number}', bn: '{number}-এর তথ্য ঠিক করুন' },
    'corr.sup.fix_help': {
        en: 'Text only: no amount, date or journal changes. The old and new values and your reason are kept in its history.',
        bn: 'শুধু লেখা: টাকা, তারিখ বা জার্নাল বদলাবে না। আগের ও নতুন তথ্য আর আপনার কারণ ইতিহাসে থাকবে।',
    },
    'corr.sup.reason': { en: 'Reason', bn: 'কারণ' },
    'corr.sup.reason_pick': { en: 'Pick one (optional)', bn: 'একটি বেছে নিন (ঐচ্ছিক)' },
    'corr.sup.reason_words': { en: 'In your words', bn: 'আপনার ভাষায়' },
    'corr.sup.reversed': { en: 'Reversed {date}', bn: 'রিভার্স হয়েছে {date}' },
    'corr.sup.return_reverse_help': {
        en: 'The goods go back into the same batches at the same cost, and the order can be billed for them again.',
        bn: 'পণ্য একই ব্যাচে একই দামে ফেরত যাবে, আর অর্ডারে আবার এগুলোর বিল করা যাবে।',
    },
} satisfies Record<string, Entry>;
