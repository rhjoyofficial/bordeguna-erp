import type { Entry } from './entry';

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): used documents get Reverse
 * with a reason, never a hard delete. Received stock transfers, posted landed
 * costs and owner withdrawals, payroll inputs (Cancel after approval, Edit
 * while waiting) and leave types (Delete when never used). Latin digits.
 */
export const reverseGaps = {
    // ── Stock transfers ─────────────────────────────────────────────────
    'corr.stock.transfer.reversed': { en: 'Reversed', bn: 'রিভার্স হয়েছে' },
    'corr.stock.transfer.reversed_on': { en: 'Reversed on {date} by {by}.', bn: '{date} তারিখে {by} রিভার্স করেছেন।' },
    'corr.stock.transfer.label': { en: 'Transfer #{id}', bn: 'ট্রান্সফার #{id}' },

    // ── Owner withdrawals ───────────────────────────────────────────────
    'owner_withdrawal.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'owner_withdrawal.reversed': { en: 'Reversed', bn: 'রিভার্স হয়েছে' },
    'owner_withdrawal.reversed_on': { en: 'Reversed on {date} by {by}.', bn: '{date} তারিখে {by} রিভার্স করেছেন।' },

    // ── Landed costs ────────────────────────────────────────────────────
    'landed.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'landed.reversed': { en: 'Reversed', bn: 'রিভার্স হয়েছে' },
    'landed.reversed_on': { en: 'Reversed on {date} by {by}.', bn: '{date} তারিখে {by} রিভার্স করেছেন।' },

    // ── Leave types ─────────────────────────────────────────────────────
    'leave_type.switch_off': { en: 'Switch off', bn: 'বন্ধ করুন' },
    'leave_type.switch_off_title': { en: 'Switch off {name}?', bn: '{name} বন্ধ করবেন?' },
    'leave_type.switch_off_text': {
        en: 'Nobody can ask for this leave any more. Its requests and balances stay as they are.',
        bn: 'এই ছুটি আর কেউ চাইতে পারবে না। এর আবেদন ও ব্যালেন্স যেমন আছে তেমনই থাকবে।',
    },
    'leave_type.reactivate': { en: 'Reactivate', bn: 'আবার চালু করুন' },
    'leave_type.delete': { en: 'Delete', bn: 'মুছুন' },
    'leave_type.delete_title': { en: 'Delete {name}?', bn: '{name} মুছবেন?' },
    'leave_type.delete_text': {
        en: 'Nobody has used it. It goes to the Recycle bin, and the Owner can restore it.',
        bn: 'কেউ এটি ব্যবহার করেনি। এটি রিসাইকেল বিনে যাবে, মালিক ফিরিয়ে আনতে পারবেন।',
    },
} satisfies Record<string, Entry>;
