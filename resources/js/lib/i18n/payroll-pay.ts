import type { Entry } from './entry';

/**
 * Paying salaries (12 Oct 2026): the paid date a batch confirmation now has to
 * ask for, the owner's "correct paid date" action, and the payroll screen's
 * "post the missing accounting entry". Dates and amounts never come through
 * here.
 */
export const payrollPay = {
    'payroll_pay.paid_on_label': {
        en: 'Paid on (the day the bank or cash actually paid)',
        bn: 'পরিশোধের তারিখ (যেদিন ব্যাংক বা ক্যাশ থেকে আসলে টাকা গেছে)',
    },
    'payroll_pay.paid_on_hint': { en: 'Use the date on the bank statement, not today.', bn: 'ব্যাংক স্টেটমেন্টের তারিখ দিন, আজকের তারিখ নয়।' },
    'payroll_pay.paid_on_needed': { en: 'Choose the paid date first.', bn: 'আগে পরিশোধের তারিখ বেছে নিন।' },
    'payroll_pay.paid_dates': { en: 'Paid on {dates}', bn: 'পরিশোধের তারিখ {dates}' },
    'payroll_pay.correct_date': { en: 'Correct paid date', bn: 'পরিশোধের তারিখ ঠিক করুন' },
    'payroll_pay.correct_title_batch': { en: 'Correct the paid date of {reference}', bn: '{reference}-এর পরিশোধের তারিখ ঠিক করুন' },
    'payroll_pay.correct_title_payment': { en: 'Correct the paid date of this payment', bn: 'এই পরিশোধের তারিখ ঠিক করুন' },
    'payroll_pay.correct_description': {
        en: 'Each payment posted on the wrong date is reversed on that same date, then posted again on the new date with the same amounts. Nothing is deleted, and both entries stay in the books.',
        bn: 'ভুল তারিখে পোস্ট হওয়া প্রতিটি পরিশোধ সেই একই তারিখে উল্টে দেওয়া হয়, তারপর নতুন তারিখে একই অঙ্কে আবার পোস্ট হয়। কিছুই মুছে ফেলা হয় না, দুটি এন্ট্রিই হিসাবে থাকে।',
    },
    'payroll_pay.new_date': { en: 'New paid date', bn: 'নতুন পরিশোধের তারিখ' },
    'payroll_pay.reason': { en: 'Reason', bn: 'কারণ' },
    'payroll_pay.reason_placeholder': { en: 'Bank paid earlier than it was confirmed here', bn: 'এখানে নিশ্চিত করার আগেই ব্যাংক টাকা দিয়েছে' },
    'payroll_pay.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'payroll_pay.save_correction': { en: 'Correct the date', bn: 'তারিখ ঠিক করুন' },
    'payroll_pay.accrual_missing_title': {
        en: 'The salary cost of this payroll is not in the books',
        bn: 'এই বেতনের খরচ হিসাবের খাতায় ওঠেনি',
    },
    'payroll_pay.accrual_missing_body': {
        en: 'It was approved before accounting was switched on, so the salary expense and the amount owed to staff were never posted. Post it now. It is dated the last day of the payroll period.',
        bn: 'হিসাব চালু হওয়ার আগে এটি অনুমোদিত হয়েছিল, তাই বেতনের খরচ আর কর্মীদের পাওনা কখনো পোস্ট হয়নি। এখনই পোস্ট করুন। এটি বেতন সময়ের শেষ দিনের তারিখে বসবে।',
    },
    'payroll_pay.accrual_post': { en: 'Post the missing accounting entry', bn: 'বাদ পড়া হিসাবের এন্ট্রি পোস্ট করুন' },
} satisfies Record<string, Entry>;
