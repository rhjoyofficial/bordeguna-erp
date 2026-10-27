import type { Entry } from './entry';

/**
 * My deductions (Joy, 27 Oct 2026): the "Coming on your next salary" list on
 * My payslips and My profile. Months, reasons and names arrive already written
 * and never translate.
 */
export const myDeductions = {
    'my_adj.heading': { en: 'Coming on your next salary', bn: 'আপনার পরের বেতনে যা আসছে' },
    'my_adj.hint': {
        en: 'Approved by the office and not on a payslip yet. Once that month is paid you see it on the payslip. Items still waiting for a decision are not shown.',
        bn: 'অফিস অনুমোদন করেছে, এখনো পে-স্লিপে আসেনি। সেই মাসের বেতন হলে পে-স্লিপে দেখবেন। যেগুলোর সিদ্ধান্ত এখনো হয়নি সেগুলো এখানে দেখানো হয় না।',
    },
    'my_adj.none': { en: 'Nothing extra is coming on your salary.', bn: 'আপনার বেতনে বাড়তি কিছু আসছে না।' },
    'my_adj.month': { en: 'Salary month', bn: 'বেতনের মাস' },
    'my_adj.type': { en: 'Type', bn: 'ধরন' },
    'my_adj.amount': { en: 'Amount', bn: 'টাকা' },
    'my_adj.reason': { en: 'Reason', bn: 'কারণ' },
    'my_adj.decided': { en: 'Decided', bn: 'সিদ্ধান্ত' },
    'my_adj.by': { en: 'by {name}', bn: '{name}' },
    'my_adj.deducted': { en: 'Deducted', bn: 'কাটা হবে' },
    'my_adj.added': { en: 'Added', bn: 'যোগ হবে' },
    'my_adj.type.addition': { en: 'Addition', bn: 'যোগ' },
    'my_adj.type.deduction': { en: 'Deduction', bn: 'কর্তন' },
    'my_adj.type.arrear': { en: 'Arrear', bn: 'বকেয়া' },
    'my_adj.type.reimbursement': { en: 'Reimbursement', bn: 'খরচ ফেরত' },
    'my_adj.type.penalty': { en: 'Penalty', bn: 'জরিমানা' },
    'my_adj.type.recovery': { en: 'Recovery', bn: 'আদায়' },
    'my_adj.type.expense_claim_settlement': { en: 'Expense claim settlement', bn: 'খরচের দাবি পরিশোধ' },
} satisfies Record<string, Entry>;
