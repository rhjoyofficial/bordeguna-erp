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

    // ── Payroll inputs ──────────────────────────────────────────────────
    'payroll_input.cancelled': { en: 'Cancelled', bn: 'বাতিল' },
    'payroll_input.cancel_overtime_title': { en: 'Cancel overtime #{id}?', bn: 'ওভারটাইম #{id} বাতিল করবেন?' },
    'payroll_input.cancel_bonus_title': { en: 'Cancel bonus award #{id}?', bn: 'বোনাস #{id} বাতিল করবেন?' },
    'payroll_input.cancel_explain': {
        en: 'It stays on the list, marked cancelled, and payroll leaves it out. A payroll that is already approved or paid is never changed.',
        bn: 'এটি তালিকায় বাতিল চিহ্নসহ থাকবে, বেতনে আর ধরা হবে না। অনুমোদিত বা পরিশোধিত বেতন কখনো বদলায় না।',
    },
    'payroll_input.cancel_revision_title': { en: 'Cancel salary revision #{id}?', bn: 'বেতন সংশোধন #{id} বাতিল করবেন?' },
    'payroll_input.cancel_revision_explain': {
        en: 'The salary before it comes back. The revision stays on the list, marked cancelled.',
        bn: 'আগের বেতন ফিরে আসবে। সংশোধনটি তালিকায় বাতিল চিহ্নসহ থাকবে।',
    },
    'payroll_input.edit': { en: 'Edit', bn: 'এডিট' },
    'payroll_input.save': { en: 'Save', bn: 'সংরক্ষণ' },
    'payroll_input.go_back': { en: 'Go back', bn: 'ফিরে যান' },
    'payroll_input.edit_adjustment_title': { en: 'Edit adjustment #{id}', bn: 'সমন্বয় #{id} এডিট' },
    'payroll_input.edit_loan_title': { en: 'Edit {number}', bn: '{number} এডিট' },
} satisfies Record<string, Entry>;
