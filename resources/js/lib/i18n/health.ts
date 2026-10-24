import type { Entry } from './entry';

/**
 * Business health, rebuilt 24 Oct 2026 (Joy: "only the necessary part, well
 * designed"): today's action signals. One label and one hint per signal key
 * the server sends (BusinessHealthSignals); the server's English is the
 * fallback for a key that is not here.
 */
export const health = {
    'health.subtitle': {
        en: "Today's signals. Each card opens the list where the work is done.",
        bn: 'আজকের সংকেত। প্রতিটি কার্ড সেই তালিকা খোলে যেখানে কাজটি করতে হয়।',
    },
    'health.all_clear': { en: 'All clear', bn: 'সব ঠিক আছে' },
    'health.all_clear_hint': { en: 'Nothing is waiting on anyone today.', bn: 'আজ কারও জন্য কিছুই অপেক্ষায় নেই।' },
    'health.clear': { en: 'Nothing waiting', bn: 'কিছু অপেক্ষায় নেই' },
    'health.open_list': { en: 'Open list', bn: 'তালিকা খুলুন' },
    'health.amount': { en: 'BDT {amount}', bn: '{amount} টাকা' },
    'health.signal.customers_overdue': { en: 'Customers overdue', bn: 'মেয়াদ পেরোনো বকেয়া গ্রাহক' },
    'health.hint.customers_overdue': {
        en: '{invoices} unpaid invoices are past their due date.',
        bn: '{invoices}টি অপরিশোধিত চালানের নির্ধারিত তারিখ পেরিয়ে গেছে।',
    },
    'health.signal.bills_due': { en: 'Supplier bills due within 7 days', bn: '7 দিনের মধ্যে দেয় সরবরাহকারী বিল' },
    'health.hint.bills_due': { en: '{late} of them are already past due.', bn: 'এর মধ্যে {late}টির তারিখ পেরিয়ে গেছে।' },
    'health.signal.low_stock': { en: 'Low stock items', bn: 'কম মজুদের পণ্য' },
    'health.hint.low_stock': { en: '{out} of them are out of stock.', bn: 'এর মধ্যে {out}টির মজুদ শেষ।' },
    'health.signal.batches_expiring': { en: 'Batches expiring within 30 days', bn: '30 দিনের মধ্যে মেয়াদ শেষ হওয়া ব্যাচ' },
    'health.hint.batches_expiring': { en: '{expired} of them are already past their date.', bn: 'এর মধ্যে {expired}টির মেয়াদ পেরিয়ে গেছে।' },
    'health.signal.money_low': { en: 'Money accounts at zero or below', bn: 'শূন্য বা তার নিচে থাকা টাকার হিসাব' },
    'health.hint.money_low': {
        en: 'No minimum level is set, so zero is the line.',
        bn: 'কোনো ন্যূনতম সীমা ঠিক করা নেই, তাই শূন্যই সীমা।',
    },
    'health.signal.approvals_waiting': { en: 'Approvals waiting for you', bn: 'আপনার অনুমোদনের অপেক্ষায়' },
    'health.hint.approvals_waiting': {
        en: 'Somebody cannot move until you decide.',
        bn: 'আপনি সিদ্ধান্ত না দেওয়া পর্যন্ত কেউ এগোতে পারছেন না।',
    },
    'health.signal.stock_reserved': { en: 'Stock reserved, not handed over', bn: 'সংরক্ষিত মজুদ, এখনো হস্তান্তর হয়নি' },
    'health.hint.stock_reserved': {
        en: 'Approved requests and confirmed orders whose goods are held and not yet given out; an order is valued at the average cost on hand.',
        bn: 'অনুমোদিত অনুরোধ ও নিশ্চিত অর্ডারের মাল আটকে রাখা আছে, এখনো দেওয়া হয়নি; অর্ডারের মাল হাতে থাকা মজুদের গড় খরচে ধরা হয়েছে।',
    },
} satisfies Record<string, Entry>;
