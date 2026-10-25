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

    // ── Cashier float in the books (answer 5) ────────────────────────────
    'ipa.float.from': { en: 'Float from', bn: 'শুরুর টাকা কোথা থেকে' },
    'ipa.float.choose': { en: 'Choose an account', bn: 'একটি অ্যাকাউন্ট বাছুন' },
    'ipa.float.in_till': { en: 'Already in the till (left by the last shift)', bn: 'আগে থেকেই ক্যাশে আছে (আগের শিফট রেখে গেছে)' },
    'ipa.float.from_hint': {
        en: 'Posted as a money transfer into the till today, so the books show the float.',
        bn: 'আজকের তারিখে ক্যাশে টাকা স্থানান্তর হিসেবে পোস্ট হবে, তাই হিসাবে শুরুর টাকা দেখাবে।',
    },
    'ipa.float.hand_over_to': { en: 'Hand over counted cash to', bn: 'গোনা টাকা কাকে বুঝিয়ে দেবেন' },
    'ipa.float.keep_in_till': { en: 'Keep it in the till', bn: 'ক্যাশেই রাখুন' },
    'ipa.float.hand_over_hint': {
        en: 'Optional. Posts a money transfer of the counted cash out of the till today.',
        bn: 'ঐচ্ছিক। গোনা টাকা আজকের তারিখে ক্যাশ থেকে স্থানান্তর হিসেবে পোস্ট হবে।',
    },
    'ipa.float.posted_as': { en: 'Posted as {number} from {account}', bn: '{account} থেকে {number} হিসেবে পোস্ট হয়েছে' },
    'ipa.float.not_posted': {
        en: 'This float was typed before floats were posted: it is not in the books as a transfer.',
        bn: 'এই শুরুর টাকা পোস্ট শুরুর আগে লেখা হয়েছিল: হিসাবে কোনো স্থানান্তর নেই।',
    },
    // ── Staff purchase of a not-for-sale item (answer 6) ─────────────────
    'ipa.custom.button': { en: 'Sell a not-for-sale item', bn: 'বিক্রির জন্য নয় এমন পণ্য বিক্রি' },
    'ipa.custom.title': { en: 'Not-for-sale item at a custom price', bn: 'নিজের ঠিক করা দামে বিক্রির জন্য নয় এমন পণ্য' },
    'ipa.custom.hint': {
        en: 'Only for an item whose Can be sold switch is off. The price and the reason are kept in History. It is then approved and handed over like any staff purchase.',
        bn: 'শুধু যে পণ্যের "বিক্রি করা যায়" বন্ধ তার জন্য। দাম ও কারণ ইতিহাসে থাকবে। তারপর অন্য স্টাফ কেনাকাটার মতোই অনুমোদন ও হস্তান্তর হবে।',
    },
    'ipa.custom.employee': { en: 'Employee', bn: 'কর্মী' },
    'ipa.custom.product': { en: 'Item', bn: 'পণ্য' },
    'ipa.custom.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'ipa.custom.price': { en: 'Price per unit (Tk)', bn: 'প্রতি এককের দাম (টাকা)' },
    'ipa.custom.cost': { en: 'Stock cost {amount}', bn: 'স্টকের খরচ {amount}' },
    'ipa.custom.reason': { en: 'Reason for this price', bn: 'এই দামের কারণ' },
    'ipa.custom.payment': { en: 'Payment', bn: 'পরিশোধ' },
    'ipa.custom.pay_cash': { en: 'Cash', bn: 'নগদ' },
    'ipa.custom.pay_mfs': { en: 'Mobile money', bn: 'মোবাইল ব্যাংকিং' },
    'ipa.custom.pay_bank': { en: 'Bank', bn: 'ব্যাংক' },
    'ipa.custom.pay_salary': { en: 'Salary deduction', bn: 'বেতন থেকে কাটা' },
    'ipa.custom.pay_credit': { en: 'Pay later', bn: 'পরে পরিশোধ' },
    'ipa.custom.choose': { en: 'Choose', bn: 'বাছুন' },
    'ipa.custom.save': { en: 'Write the request', bn: 'আবেদন লিখুন' },
    'ipa.custom.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'ipa.custom.none': { en: 'No stocked item has its Can be sold switch off.', bn: '"বিক্রি করা যায়" বন্ধ এমন কোনো মজুত পণ্য নেই।' },
    'ipa.custom.line_label': { en: 'custom price: {reason}', bn: 'নিজের ঠিক করা দাম: {reason}' },

    'ipa.float.handed_over': { en: 'Handed over to {account} as {number}', bn: '{number} হিসেবে {account}-কে বুঝিয়ে দেওয়া হয়েছে' },
} satisfies Record<string, Entry>;
