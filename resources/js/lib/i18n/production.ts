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
} satisfies Record<string, Entry>;
