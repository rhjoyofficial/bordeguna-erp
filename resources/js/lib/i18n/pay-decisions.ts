import type { Entry } from './entry';

/**
 * Pay decisions (Joy, 27 Oct 2026): the pay on an approved leave, decided apart
 * from approving it (HRM, Leave and the approval card), and a worked day made
 * unpaid for a rule break (HRM, Attendance, Daily). Numbers and dates arrive
 * already written and never translate.
 */
export const payDecisions = {
    // ── The pay on a leave ─────────────────────────────────────────────
    'pay.button': { en: 'Pay decision', bn: 'বেতনের সিদ্ধান্ত' },
    'pay.title': { en: 'Pay on this leave', bn: 'এই ছুটির বেতন' },
    'pay.intro': {
        en: 'Approving the leave says {name} may be away. This decides only whether those {days} day(s) are paid. Now: {now}.',
        bn: 'ছুটি অনুমোদন মানে {name} অনুপস্থিত থাকতে পারবেন। এখানে শুধু ঠিক হয় ওই {days} দিনের বেতন হবে কি না। এখন: {now}।',
    },
    'pay.paid': { en: 'Paid (all days)', bn: 'বেতনসহ (সব দিন)' },
    'pay.unpaid': { en: 'Unpaid (all days)', bn: 'বেতন ছাড়া (সব দিন)' },
    'pay.part': { en: 'Part unpaid', bn: 'কিছু দিন বেতন ছাড়া' },
    'pay.unpaid_days': { en: 'Unpaid days', bn: 'বেতন ছাড়া দিন' },
    'pay.reason': { en: 'Reason', bn: 'কারণ' },
    'pay.reason_placeholder': { en: 'Why the pay is decided this way', bn: 'কেন বেতন এভাবে ঠিক হলো' },
    'pay.balance_note': {
        en: 'For {type}: days made unpaid go back to the balance. Days made paid come from the balance while it has them; the rest the company pays as a grant.',
        bn: '{type}-এর জন্য: বেতন ছাড়া করা দিন ব্যালান্সে ফিরে যায়। বেতনসহ করা দিন ব্যালান্সে থাকলে সেখান থেকে কাটা হয়; বাকিটা কোম্পানি অনুদান হিসেবে দেয়।',
    },
    'pay.grant_note': {
        en: 'No balance moves for {type}. Paid days on it are a company grant.',
        bn: '{type}-এ কোনো ব্যালান্স বদলায় না। এতে বেতনসহ দিন কোম্পানির অনুদান।',
    },
    'pay.payroll_note': {
        en: 'Payroll reads this for the Leave Without Pay line. Recalculate the payroll for those days afterwards.',
        bn: 'বেতন হিসাবের "বেতন ছাড়া ছুটি" লাইন এখান থেকেই পড়ে। পরে ওই দিনগুলোর বেতন আবার হিসাব করুন।',
    },
    'pay.save': { en: 'Save pay decision', bn: 'সিদ্ধান্ত রাখুন' },
    'pay.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'pay.decided_by': { en: 'Decided by {by}, {at}', bn: 'সিদ্ধান্ত নিয়েছেন {by}, {at}' },
    'pay.history': { en: 'Pay history', bn: 'বেতনের ইতিহাস' },
    'pay.history_line': { en: '{from} to {to}', bn: '{from} থেকে {to}' },
    'pay.at_approval': { en: 'at approval', bn: 'অনুমোদনের সময়' },
    'pay.quota_back': { en: '{days} day(s) back to the balance', bn: '{days} দিন ব্যালান্সে ফেরত' },
    'pay.quota_drawn': { en: '{days} day(s) from the balance', bn: '{days} দিন ব্যালান্স থেকে' },
    'pay.granted': { en: '{days} day(s) paid by the company', bn: '{days} দিন কোম্পানি দিচ্ছে' },

    // ── The approval card ──────────────────────────────────────────────
    'pay.card_label': { en: 'Pay if approved', bn: 'অনুমোদন হলে বেতন' },
    'pay.card_as_rules': { en: 'As the leave type and balance say', bn: 'ছুটির ধরন ও ব্যালান্স অনুযায়ী' },
    'pay.card_reason_needed': { en: 'Reason for this pay choice', bn: 'এই বেতনের সিদ্ধান্তের কারণ' },

    // ── A worked day made unpaid (rule break) ─────────────────────────
    'unpaid_day.button': { en: 'Make unpaid', bn: 'বেতন ছাড়া করুন' },
    'unpaid_day.title': { en: 'Make {date} unpaid for {name}', bn: '{name}-এর {date} তারিখ বেতন ছাড়া করুন' },
    'unpaid_day.intro': {
        en: 'They came to work, but the office does not pay this day, for example for breaking a rule. A Penalty of one day of their pay goes on the payroll for that day, after approval like any other adjustment.',
        bn: 'তিনি কাজে এসেছিলেন, কিন্তু অফিস এই দিনের বেতন দেবে না, যেমন নিয়ম ভাঙার জন্য। ওই দিনের বেতন হিসাবে এক দিনের বেতনের সমান জরিমানা (Penalty) বসবে, অন্য সমন্বয়ের মতোই অনুমোদনের পরে।',
    },
    'unpaid_day.full': { en: 'Full day', bn: 'পুরো দিন' },
    'unpaid_day.half': { en: 'Half day', bn: 'অর্ধেক দিন' },
    'unpaid_day.reason': { en: 'Reason', bn: 'কারণ' },
    'unpaid_day.reason_placeholder': { en: 'Which rule was broken', bn: 'কোন নিয়ম ভাঙা হয়েছে' },
    'unpaid_day.save': { en: 'Make unpaid', bn: 'বেতন ছাড়া করুন' },
    'unpaid_day.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'unpaid_day.shown': { en: 'Unpaid: {reason}', bn: 'বেতন ছাড়া: {reason}' },
    'unpaid_day.waiting': { en: 'waiting for approval', bn: 'অনুমোদনের অপেক্ষায়' },
    'unpaid_day.open': { en: 'Penalty #{id}, {amount}', bn: 'জরিমানা #{id}, {amount}' },
} satisfies Record<string, Entry>;
