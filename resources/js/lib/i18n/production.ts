import type { Entry } from './entry';

/**
 * MFC-009 (audit 14 Oct 2026): the confirmation before a production run is
 * reversed or a draft run or a recipe version is deleted. Each acted on one
 * click before; a reversal writes reversing stock movements and a journal
 * dated today and cannot be redone cleanly.
 */
export const production = {
    'production.reverse.title': { en: 'Reverse run {number}?', bn: 'রান {number} বাতিল করবেন?' },
    'production.reverse.description': {
        en: 'Every consumed portion goes back to stock and the produced batch is taken out again, with a reversing journal dated today. A reversed run cannot be confirmed again; it has to be entered as a new run.',
        bn: 'খরচ হওয়া সব অংশ স্টকে ফিরবে এবং তৈরি হওয়া ব্যাচ আবার বের হয়ে যাবে, সঙ্গে আজকের তারিখে একটি উল্টো জার্নাল। বাতিল করা রান আবার নিশ্চিত করা যায় না; নতুন রান হিসেবে লিখতে হবে।',
    },
    'production.reverse.confirm': { en: 'Reverse run', bn: 'রান বাতিল করুন' },
    'production.delete_draft.title': { en: 'Delete draft {number}?', bn: 'খসড়া {number} মুছবেন?' },
    'production.delete_draft.description': {
        en: 'The draft and its lines are removed. Nothing moved or posted, but what was typed is lost.',
        bn: 'খসড়া ও এর লাইনগুলো মুছে যাবে। কিছুই নড়েনি বা পোস্ট হয়নি, তবে যা লেখা হয়েছিল তা হারিয়ে যাবে।',
    },
    'production.delete_draft.confirm': { en: 'Delete draft', bn: 'খসড়া মুছুন' },
    'production.recipe_delete.title': { en: 'Delete this recipe version?', bn: 'এই রেসিপি সংস্করণ মুছবেন?' },
    'production.recipe_delete.description': {
        en: 'Version {version} for {product} is removed. No run was built from it.',
        bn: '{product}-এর সংস্করণ {version} মুছে যাবে। এটি দিয়ে কোনো রান তৈরি হয়নি।',
    },
    'production.recipe_delete.confirm': { en: 'Delete', bn: 'মুছুন' },
    'production.cancel': { en: 'Keep it', bn: 'রেখে দিন' },
    // P8/P9 (18 Oct 2026, practice run): the same run pressed twice, and recipe lines before the quantity.
    'production.repeat.again': { en: 'Record it again', bn: 'আবার লিখুন' },
    'production.repeat.open': { en: 'Open that run', bn: 'ওই রানটি খুলুন' },
    'production.recipe.waiting': {
        en: 'Type the output quantity and the recipe fills in the amounts.',
        bn: 'আউটপুটের পরিমাণ লিখলে রেসিপি পরিমাণগুলো বসিয়ে দেবে।',
    },
    // PR3 (18 Oct 2026): can make, two-way fill, other costs, live unit cost.
    'production.can_make.here': {
        en: 'Can make {quantity} {unit} here, limited by {component}.',
        bn: 'এখানে {quantity} {unit} বানানো যাবে, সীমা ঠিক করছে {component}।',
    },
    'production.can_make.none': {
        en: 'Cannot make any here: not enough {component}.',
        bn: 'এখানে একটিও বানানো যাবে না: {component} যথেষ্ট নেই।',
    },
    'production.can_make.button': { en: 'Make the most ({quantity})', bn: 'সর্বোচ্চ বানান ({quantity})' },
    'production.auto_fill.label': {
        en: 'Fill the rest for me: change the output or any recipe input and the others follow',
        bn: 'বাকিটা নিজে থেকে বসান: আউটপুট বা রেসিপির যেকোনো উপকরণ বদলালে বাকিগুলো মিলিয়ে বসবে',
    },
    'production.auto_fill.driven': {
        en: 'Output worked out from {component}: {quantity} {unit}. The other inputs follow it.',
        bn: '{component} থেকে আউটপুট হিসাব হয়েছে: {quantity} {unit}। বাকি উপকরণ সেই অনুযায়ী বসেছে।',
    },
    'production.costs.title': { en: 'Other costs (optional)', bn: 'অন্যান্য খরচ (ঐচ্ছিক)' },
    'production.costs.hint': {
        en: 'Labour, overhead and the like. Each is added to the cost of the goods made and taken out of the expense head you choose.',
        bn: 'মজুরি, ওভারহেড ইত্যাদি। প্রতিটি তৈরি পণ্যের খরচে যোগ হবে এবং আপনার বেছে নেওয়া খরচের খাত থেকে বাদ যাবে।',
    },
    'production.costs.kind': { en: 'For', bn: 'কিসের জন্য' },
    'production.costs.account': { en: 'Expense head', bn: 'খরচের খাত' },
    'production.costs.amount': { en: 'Amount (Tk)', bn: 'পরিমাণ (টাকা)' },
    'production.costs.note': { en: 'Note', bn: 'নোট' },
    'production.costs.add': { en: 'Add a cost', bn: 'খরচ যোগ করুন' },
    'production.costs.remove': { en: 'Remove', bn: 'বাদ দিন' },
    'production.costs.choose_account': { en: 'Choose a head', bn: 'খাত বাছুন' },
    'production.cost_preview.title': { en: 'Cost of what you make (estimate)', bn: 'যা বানাচ্ছেন তার খরচ (আনুমানিক)' },
    'production.cost_preview.line': {
        en: 'Materials Tk {materials} + other costs Tk {costs} = Tk {total}, so Tk {unit_cost} per {unit}.',
        bn: 'উপকরণ {materials} টাকা + অন্যান্য খরচ {costs} টাকা = {total} টাকা, অর্থাৎ প্রতি {unit} {unit_cost} টাকা।',
    },
    'production.cost_preview.waste': {
        en: 'Waste of Tk {waste} goes to the loss account, not into the goods.',
        bn: '{waste} টাকার অপচয় ক্ষতির হিসাবে যাবে, পণ্যের খরচে নয়।',
    },
    'production.cost_preview.basis': {
        en: 'Priced from the batches the run would take, oldest expiry first. The real figure is fixed when the run is confirmed.',
        bn: 'রান যে ব্যাচগুলো নেবে (আগে মেয়াদ শেষ হওয়াগুলো আগে) সেই দামে হিসাব। আসল হিসাব রান নিশ্চিত করার সময় ঠিক হবে।',
    },
    'production.cost_preview.short': {
        en: 'Some inputs are short here; the missing part is priced at the last batch cost.',
        bn: 'এখানে কিছু উপকরণ কম আছে; ঘাটতির অংশ শেষ ব্যাচের দামে ধরা হয়েছে।',
    },
    'production.recipes.can_make': {
        en: 'Can make now at {warehouse}: {quantity} {unit}, limited by {component}',
        bn: 'এখন {warehouse}-এ বানানো যাবে: {quantity} {unit}, সীমা ঠিক করছে {component}',
    },
    'production.recipes.make_now': { en: 'Make now', bn: 'এখন বানান' },
    'production.show.other_costs': { en: 'Other costs', bn: 'অন্যান্য খরচ' },
    'production.show.output_value': { en: 'Value of the goods made', bn: 'তৈরি পণ্যের মূল্য' },
} satisfies Record<string, Entry>;
