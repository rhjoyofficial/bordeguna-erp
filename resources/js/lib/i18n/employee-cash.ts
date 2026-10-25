import type { Entry } from './entry';

/**
 * Initial plan B3 (25 Oct 2026, Joy's Q1 = A): Employee cash accounts
 * ("Petty Cash - Sifat"). Numbers, dates and money never translate.
 */
export const employeeCash = {
    'ecash.title': { en: 'Company cash with this employee', bn: 'এই কর্মীর কাছে কোম্পানির নগদ' },
    'ecash.hint': {
        en: 'Money given to him with a Contra voucher, and what he paid from it. Below zero means he paid from his own pocket and the company owes him.',
        bn: 'কন্ট্রা ভাউচারে দেওয়া টাকা, আর তা থেকে যা দিয়েছেন। শূন্যের নিচে মানে তিনি নিজের পকেট থেকে দিয়েছেন, কোম্পানি তাঁর কাছে ঋণী।',
    },
    'ecash.holds': { en: 'holds Tk {amount}', bn: 'হাতে আছে ৳ {amount}' },
    'ecash.owed': { en: 'company owes Tk {amount}', bn: 'কোম্পানি পাওনা দেবে ৳ {amount}' },
    'ecash.even': { en: 'nothing held, nothing owed', bn: 'হাতে কিছু নেই, পাওনাও নেই' },
    'ecash.no_lines': { en: 'Nothing has moved through it yet.', bn: 'এখনও কোনো লেনদেন হয়নি।' },
    'ecash.custodian': { en: 'Held by', bn: 'যার কাছে' },
    'ecash.pick_employee': { en: 'Pick the employee', bn: 'কর্মী বাছুন' },
    'ecash.dialog_hint': {
        en: 'Fill it with a Contra voucher (Accounting > Vouchers > Contra), from the bank or office cash into this account. He pays purchases and expenses from it; it may go below zero, which is what the company owes him.',
        bn: 'কন্ট্রা ভাউচার দিয়ে (হিসাব > ভাউচার > কন্ট্রা) ব্যাংক বা অফিসের নগদ থেকে এই অ্যাকাউন্টে টাকা দিন। তিনি এখান থেকে কেনাকাটা ও খরচ দেবেন; শূন্যের নিচে যেতে পারে, যা কোম্পানির দেনা।',
    },
} satisfies Record<string, Entry>;
