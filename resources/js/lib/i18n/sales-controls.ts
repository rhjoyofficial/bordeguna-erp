import type { Entry } from './entry';

/**
 * ⭐ Sales controls (Joy's initial plan B14, B15, B16, 25 Oct 2026): the credit
 * limit override on the same screen (Q11 = A), the Discount % box per line with
 * caps by role (Q12 = A), the price groups and the minimum selling price
 * (Q13, Q16 = A). Latin digits; money never translates.
 */
export const salesControls = {
    // ── B15: Discount % per line ────────────────────────────────────────
    'salesctl.disc.heading': { en: 'Discount', bn: 'ছাড়' },
    'salesctl.disc.percent': { en: 'Discount %', bn: 'ছাড় %' },
    'salesctl.disc.taka': { en: 'Discount Tk', bn: 'ছাড় টাকা' },
    'salesctl.disc.cap': { en: 'Your limit: {percent}% a line', bn: 'আপনার সীমা: প্রতি লাইনে {percent}%' },
    'salesctl.disc.no_cap': { en: 'No limit for the Owner', bn: 'মালিকের কোনো সীমা নেই' },
    'salesctl.disc.over': { en: 'Over your limit of {percent}%', bn: 'আপনার {percent}% সীমার বেশি' },
    'salesctl.disc.summary': { en: 'Discounts', bn: 'ছাড়' },
    // ── B14: Credit limit override ──────────────────────────────────────
    'salesctl.credit.title': { en: 'Over the credit limit', bn: 'ক্রেডিট সীমার বেশি' },
    'salesctl.credit.intro': {
        en: 'A Manager or the Owner can let it through: they choose their own name, type their own password and say why. It is written on the record and in its History.',
        bn: 'একজন ম্যানেজার বা মালিক এটি অনুমতি দিতে পারেন: নিজের নাম বেছে নিন, নিজের পাসওয়ার্ড লিখুন এবং কারণ বলুন। এটি রেকর্ডে ও তার ইতিহাসে লেখা থাকবে।',
    },
    'salesctl.credit.override': { en: 'Override', bn: 'অনুমতি দিন' },
    'salesctl.credit.who': { en: 'Manager or Owner', bn: 'ম্যানেজার বা মালিক' },
    'salesctl.credit.choose': { en: 'Choose who allows it', bn: 'কে অনুমতি দিচ্ছেন বেছে নিন' },
    'salesctl.credit.password': { en: 'Their own password', bn: 'তাদের নিজের পাসওয়ার্ড' },
    'salesctl.credit.reason': { en: 'Reason', bn: 'কারণ' },
    'salesctl.credit.reason_hint': { en: 'At least 5 letters, for example: pays every Friday', bn: 'কমপক্ষে ৫ অক্ষর, যেমন: প্রতি শুক্রবার টাকা দেন' },
    'salesctl.credit.cancel': { en: 'Do not override', bn: 'অনুমতি দেবেন না' },
    'salesctl.credit.cash_only': {
        en: 'No credit limit is set, so this customer buys for cash only.',
        bn: 'কোনো ক্রেডিট সীমা দেওয়া নেই, তাই এই কাস্টমার শুধু নগদে কিনবেন।',
    },
    'salesctl.credit.overridden': { en: 'Credit limit overridden', bn: 'ক্রেডিট সীমা পার হওয়ার অনুমতি' },
    'salesctl.credit.overridden_by': { en: 'Allowed by {name} on {date}: {reason}', bn: '{name} অনুমতি দিয়েছেন, {date}: {reason}' },
    // ── B16: Price groups and the minimum selling price ─────────────────
    'salesctl.floor.label': { en: 'Minimum selling price', bn: 'সর্বনিম্ন বিক্রয়মূল্য' },
    'salesctl.floor.hint': {
        en: 'Optional. Selling below it needs a Manager or the Owner and a reason.',
        bn: 'ঐচ্ছিক। এর নিচে বিক্রি করতে ম্যানেজার বা মালিক এবং একটি কারণ লাগবে।',
    },
    'salesctl.floor.none': { en: 'No minimum', bn: 'সর্বনিম্ন নেই' },
    'salesctl.floor.save': { en: 'Save minimum', bn: 'সর্বনিম্ন সংরক্ষণ' },
    'salesctl.floor.below': { en: 'Below the minimum of {price}', bn: 'সর্বনিম্ন {price} এর নিচে' },
    'salesctl.floor.reason': { en: 'Why below the minimum?', bn: 'সর্বনিম্নের নিচে কেন?' },
    'salesctl.floor.column': { en: 'Minimum', bn: 'সর্বনিম্ন' },
} satisfies Record<string, Entry>;
