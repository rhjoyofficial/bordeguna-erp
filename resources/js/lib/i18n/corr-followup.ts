import type { Entry } from './entry';

/**
 * ⭐ Corrections follow-up (B13, 24 Oct 2026): Withdraw for every approval
 * type, duplicate warnings on parties, products and people, opening documents
 * kept when removed, the Owner's closed-month journal, Reactivate and Delete on
 * customers and suppliers, and the Owner's merges.
 */
export const corrFollowup = {
    // Withdraw
    'corr.fu.withdrawn': { en: 'Withdrawn', bn: 'ফিরিয়ে নেওয়া' },
    'corr.fu.withdraw_draft': {
        en: 'Withdrawing brings it back to you as a draft. Nothing was approved. Change it and send it again.',
        bn: 'ফিরিয়ে নিলে এটা খসড়া হয়ে আপনার কাছে ফিরে আসবে। কিছুই অনুমোদন হয়নি। ঠিক করে আবার পাঠান।',
    },
    'corr.fu.withdraw_cancelled': {
        en: 'Withdrawing closes it as cancelled. Nothing was approved and nothing changes. Raise a new one if you still need it.',
        bn: 'ফিরিয়ে নিলে এটা বাতিল হয়ে বন্ধ হবে। কিছুই অনুমোদন হয়নি, কিছুই বদলাবে না। দরকার হলে নতুন করে পাঠান।',
    },

    // Opening receivable and payable: Reverse, kept and marked
    'corr.fu.opening_reverse_title': { en: 'Reverse {number}?', bn: '{number} রিভার্স করবেন?' },
    'corr.fu.opening_reverse_help': {
        en: 'The opening balance stays, marked Reversed, with your reason. Its journal is undone and the party owes nothing on it any more.',
        bn: 'ওপেনিং ব্যালান্সটা থেকে যাবে, রিভার্স চিহ্ন আর আপনার কারণসহ। এর জার্নাল উল্টে যাবে, এর ওপর আর কোনো পাওনা থাকবে না।',
    },
    'corr.fu.reverse_on': { en: 'Reverse on', bn: 'কোন তারিখে রিভার্স' },
    'corr.fu.its_own_date': { en: 'Its own date ({date})', bn: 'নিজের তারিখে ({date})' },
    'corr.fu.today': { en: 'Today', bn: 'আজ' },
    'corr.fu.month_closed_today': {
        en: 'Its month is closed, so the reversal goes on today.',
        bn: 'ওই মাস বন্ধ, তাই রিভার্স আজকের তারিখে হবে।',
    },

    // Customer and supplier: Reactivate, Delete to the bin
    'corr.fu.record_title': { en: 'This record', bn: 'এই রেকর্ড' },
    'corr.fu.reactivate': { en: 'Reactivate', bn: 'আবার চালু করুন' },
    'corr.fu.delete': { en: 'Delete', bn: 'মুছুন' },
    'corr.fu.delete_title': { en: 'Move {name} to the Recycle bin?', bn: '{name} রিসাইকেল বিনে পাঠাবেন?' },
    'corr.fu.delete_help': {
        en: 'It was never used, so it can go to the Recycle bin. Only the Owner can restore it. Nothing is deleted for good.',
        bn: 'এটা কখনো ব্যবহার হয়নি, তাই রিসাইকেল বিনে যেতে পারে। শুধু মালিক ফিরিয়ে আনতে পারবেন। কিছুই চিরতরে মোছা হয় না।',
    },
    'corr.fu.merged_into': { en: 'Merged into', bn: 'যার সাথে মেলানো হয়েছে:' },
} satisfies Record<string, Entry>;
