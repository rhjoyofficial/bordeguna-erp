import type { Entry } from './entry';

/**
 * FEA-003/004 (owner decision 14 Oct 2026): an opening fixed asset carries
 * what the accountant's opening trial balance says about it. Only the
 * sentences that decision added; the register around them is still English
 * by the 31 Aug staff-first decision.
 */
export const openingAssets = {
    'asset.opening.accumulated': {
        en: 'Depreciation written off by cutover',
        bn: 'কাটওভার পর্যন্ত লেখা অবচয়',
    },
    'asset.opening.remaining_life': {
        en: 'Life left at cutover (months)',
        bn: 'কাটওভারে বাকি আয়ু (মাস)',
    },
    'asset.opening.remaining_life_auto': {
        en: 'Empty: worked out from its age',
        bn: 'খালি থাকলে বয়স থেকে হিসাব হবে',
    },
    'asset.opening.hint': {
        en: "For something owned on or before the cutover ({date}). Take the figures from the accountant's opening trial balance. If it gives only the net book value, enter that as the cost and leave the depreciation at 0. Nothing is posted: the opening balances already hold it. Only the Owner records these.",
        bn: 'কাটওভারের ({date}) দিন বা তার আগে থেকে থাকা জিনিসের জন্য। হিসাবরক্ষকের ওপেনিং ট্রায়াল ব্যালান্স থেকে অঙ্কগুলো নিন। সেখানে শুধু নিট বুক ভ্যালু থাকলে সেটাই খরচ হিসেবে লিখুন এবং অবচয় 0 রাখুন। কিছুই পোস্ট হবে না: ওপেনিং ব্যালান্সে এটা আগেই আছে। শুধু মালিক এগুলো লেখেন।',
    },
    'asset.opening.badge': {
        en: 'Opening: {amount} written off before cutover, {months} months left then',
        bn: 'ওপেনিং: কাটওভারের আগে {amount} লেখা হয়েছে, তখন {months} মাস বাকি ছিল',
    },
} satisfies Record<string, Entry>;
