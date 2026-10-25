import type { Entry } from './entry';

/**
 * Joy's answers of 25 Oct 2026 (initial plan, builder IP18): purchase cost
 * type defaults on Finance policy, the cashier float in the books, the staff
 * purchase of a not-for-sale item, and SSL Commerz settlement.
 */
export const ipAnswers = {
    // ── Finance policy > Purchase cost types (answer 3) ─────────────────
    'ipa.cost.title': { en: 'Purchase cost types', bn: 'ক্রয়ের খরচের ধরন' },
    'ipa.cost.intro': {
        en: 'What each extra cost of a purchase does by default: added to the stock value, or an expense under the head named here. A line on a purchase may still switch, with a reason. Documents already saved keep the default they were saved under.',
        bn: 'ক্রয়ের প্রতিটি বাড়তি খরচ স্বাভাবিকভাবে কী হবে: স্টকের মূল্যে যোগ, নাকি এখানে নাম দেওয়া খাতে খরচ। ক্রয়ের কোনো লাইন কারণ দিয়ে তবু বদলাতে পারবে। আগে সংরক্ষিত দলিল আগের নিয়মেই থাকবে।',
    },
    'ipa.cost.type': { en: 'Cost type', bn: 'খরচের ধরন' },
    'ipa.cost.default': { en: 'Default', bn: 'স্বাভাবিক নিয়ম' },
    'ipa.cost.head': { en: 'Expense head', bn: 'খরচের খাত' },
    'ipa.cost.capitalize': { en: 'Add to stock value', bn: 'স্টকের মূল্যে যোগ' },
    'ipa.cost.expense': { en: 'Expense', bn: 'খরচ' },
    'ipa.cost.mapped': { en: 'As mapped: {head}', bn: 'ম্যাপ অনুযায়ী: {head}' },
    'ipa.cost.named_on_line': { en: 'Named on each line', bn: 'প্রতিটি লাইনে নাম দিতে হবে' },
    'ipa.cost.edit': { en: 'Edit', bn: 'সম্পাদনা' },
    'ipa.cost.edit_title': { en: 'Default for {type}', bn: '{type}-এর স্বাভাবিক নিয়ম' },
    'ipa.cost.choose_head': { en: 'Choose a head', bn: 'খাত বাছুন' },
    'ipa.cost.head_hint': {
        en: 'Leave blank to use the mapped head; a type with no mapped head needs one here.',
        bn: 'খালি রাখলে ম্যাপ করা খাত ব্যবহার হবে; যে ধরনের ম্যাপ করা খাত নেই তার জন্য এখানে একটি দিতে হবে।',
    },
    'ipa.cost.save': { en: 'Save', bn: 'সংরক্ষণ' },
    'ipa.cost.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'ipa.cost.nav': { en: 'Purchase cost types', bn: 'ক্রয়ের খরচের ধরন' },
} satisfies Record<string, Entry>;
