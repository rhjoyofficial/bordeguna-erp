import type { Entry } from './entry';

/**
 * PUR-003 (owner decision 14 Oct 2026): reversing a supplier payment, a
 * supplier credit note or an expense voucher. Only the sentences that decision
 * added; the Purchasing screens around them are still English by the 31 Aug
 * staff-first decision.
 */
export const reversals = {
    'reverse.payment.title': { en: 'Reverse this payment', bn: 'এই পেমেন্ট রিভার্স করুন' },
    'reverse.credit_note.title': { en: 'Reverse this credit note', bn: 'এই ক্রেডিট নোট রিভার্স করুন' },
    'reverse.expense.title': { en: 'Reverse this expense voucher', bn: 'এই খরচের ভাউচার রিভার্স করুন' },
    'reverse.payment.explain': {
        en: 'A mirror journal undoes it. The bills this payment settled are open again. Nothing is deleted.',
        bn: 'একটি উল্টো জার্নাল এটি বাতিল করবে। এই পেমেন্টে যে বিলগুলো শোধ হয়েছিল সেগুলো আবার খোলা হবে। কিছুই মুছে ফেলা হয় না।',
    },
    'reverse.credit_note.explain': {
        en: 'A mirror journal undoes it. The bills it credited owe again, and the return can be credited again. Nothing is deleted.',
        bn: 'একটি উল্টো জার্নাল এটি বাতিল করবে। যে বিলগুলোতে ক্রেডিট দেওয়া হয়েছিল সেগুলোতে আবার টাকা বাকি থাকবে, আর রিটার্নটিতে আবার ক্রেডিট নেওয়া যাবে। কিছুই মুছে ফেলা হয় না।',
    },
    'reverse.expense.explain': {
        en: 'A mirror journal undoes it. The money comes back to the account it was paid from. Nothing is deleted.',
        bn: 'একটি উল্টো জার্নাল এটি বাতিল করবে। টাকা যে অ্যাকাউন্ট থেকে দেওয়া হয়েছিল সেখানে ফিরে আসবে। কিছুই মুছে ফেলা হয় না।',
    },
    // Supplier advances (ruling P2, 18 Oct 2026).
    'reverse.advance.title': { en: 'Reverse this advance', bn: 'এই অগ্রিম রিভার্স করুন' },
    'reverse.advance.explain': {
        en: 'A mirror journal undoes it: the money comes back to the account it was paid from. Refused while any of it is applied to a bill or refunded. Nothing is deleted.',
        bn: 'একটি উল্টো জার্নাল এটি বাতিল করবে: টাকা যে অ্যাকাউন্ট থেকে দেওয়া হয়েছিল সেখানে ফিরে আসবে। এর কোনো অংশ বিলে লাগানো বা ফেরত নেওয়া থাকলে করা যাবে না। কিছুই মুছে ফেলা হয় না।',
    },
    'reverse.advance_application.title': { en: 'Reverse this advance application', bn: 'বিলে লাগানো এই অগ্রিম রিভার্স করুন' },
    'reverse.advance_application.explain': {
        en: 'A mirror journal undoes it. The bill owes this amount again and the advance has it open again. Nothing is deleted.',
        bn: 'একটি উল্টো জার্নাল এটি বাতিল করবে। বিলে এই টাকা আবার বাকি থাকবে, আর অগ্রিমে এই টাকা আবার খোলা থাকবে। কিছুই মুছে ফেলা হয় না।',
    },
    'reverse.advance_refund.title': { en: 'Reverse this refund', bn: 'এই ফেরত রিভার্স করুন' },
    'reverse.advance_refund.explain': {
        en: 'A mirror journal undoes it. The advance is open again by the refunded amount. Nothing is deleted.',
        bn: 'একটি উল্টো জার্নাল এটি বাতিল করবে। ফেরত নেওয়া টাকা অগ্রিমে আবার খোলা থাকবে। কিছুই মুছে ফেলা হয় না।',
    },
    'reverse.reason': { en: 'Why is it being reversed?', bn: 'কেন রিভার্স করা হচ্ছে?' },
    'reverse.on_original_date': {
        en: "Date the reversal {date}, the document's own date (only while that month is open). Otherwise it is dated today.",
        bn: 'রিভার্সের তারিখ হবে মূল তারিখ {date} (শুধু সেই মাস খোলা থাকলে)। না হলে আজকের তারিখ হবে।',
    },
    'reverse.submit': { en: 'Reverse', bn: 'রিভার্স করুন' },
    'reverse.badge': { en: 'Reversed', bn: 'রিভার্স করা হয়েছে' },
    'reverse.done_by': {
        en: 'Reversed on {date} by {name}. Reason: {reason}',
        bn: '{date} তারিখে {name} রিভার্স করেছেন। কারণ: {reason}',
    },
    'reverse.released': { en: 'Bills it had settled: {bills}', bn: 'যে বিলগুলো শোধ হয়েছিল: {bills}' },
} satisfies Record<string, Entry>;
