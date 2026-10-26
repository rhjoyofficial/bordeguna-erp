import type { Entry } from './entry';

/**
 * Early leave requests and the provisional-day follow-up (Joy's HRM queue,
 * 27 Oct 2026): My attendance, the payroll block and Needs attention.
 *
 * The two `attention.reason.*` entries mirror the English sentences
 * AttendanceReviewSource writes on the server, with the same {placeholders}.
 * Dates and names arrive already written and never translate.
 */
export const earlyLeave = {
    // ── My attendance: the request ─────────────────────────────────────
    'early.request': { en: 'Request early leave', bn: 'আগে ছুটির আবেদন' },
    'early.title_for': { en: 'Early leave on {date}', bn: '{date} তারিখে আগে ছুটি' },
    'early.date': { en: 'Day', bn: 'দিন' },
    'early.time': { en: 'Leaving at', bn: 'কখন বের হবেন' },
    'early.reason_label': { en: 'Reason', bn: 'কারণ' },
    'early.reason_placeholder': { en: 'Why you need to leave early', bn: 'কেন আগে যেতে হবে' },
    'early.send': { en: 'Send request', bn: 'আবেদন পাঠান' },
    'early.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'early.hint': {
        en: 'Your manager or the owner decides. Approved: no deduction for leaving early that day. Rejected: the day stays as the attendance rules judge it.',
        bn: 'আপনার ম্যানেজার বা মালিক সিদ্ধান্ত নেবেন। অনুমোদিত হলে সেদিন আগে যাওয়ার জন্য কোনো কাটা হবে না। প্রত্যাখ্যাত হলে হাজিরার নিয়ম অনুযায়ী দিনটি থাকবে।',
    },
    'early.my_requests': { en: 'My early leave requests', bn: 'আমার আগে ছুটির আবেদন' },
    'early.status.pending': { en: 'Waiting', bn: 'অপেক্ষায়' },
    'early.status.approved': { en: 'Approved', bn: 'অনুমোদিত' },
    'early.status.rejected': { en: 'Rejected', bn: 'প্রত্যাখ্যাত' },
    'early.status.returned': { en: 'Sent back', bn: 'ফেরত পাঠানো' },
    'early.status.cancelled': { en: 'Cancelled', bn: 'বাতিল' },

    // ── My attendance: days needing your action ─────────────────────────
    'early.needs_action': { en: 'Days needing your action', bn: 'যে দিনগুলোতে আপনার কিছু করার আছে' },
    'early.needs_action_hint': {
        en: 'These days of this payroll cycle wait for a review and nothing is filed for them yet. Ask for early leave, file leave, or ask for a correction.',
        bn: 'এই বেতন চক্রের এই দিনগুলো পর্যালোচনার অপেক্ষায়, আর এখনও কিছু জমা দেওয়া হয়নি। আগে ছুটি চান, ছুটির আবেদন করুন, বা সংশোধন চান।',
    },
    'early.file_leave': { en: 'File leave', bn: 'ছুটির আবেদন' },
    'early.reason.missing_punch': { en: 'A punch is missing.', bn: 'একটি পাঞ্চ নেই।' },
    'early.reason.early_departure': { en: 'Left before the shift ended.', bn: 'শিফট শেষ হওয়ার আগে চলে গেছেন।' },
    'early.reason.absent': { en: 'Absent, with no leave filed.', bn: 'অনুপস্থিত, কোনো ছুটির আবেদন নেই।' },
    'early.reason.holiday_work': { en: 'Worked on a day off without approval.', bn: 'অনুমোদন ছাড়া ছুটির দিনে কাজ।' },
    'early.reason.review': { en: 'Waiting for HR review.', bn: 'এইচআর পর্যালোচনার অপেক্ষায়।' },

    // ── Payroll: the block ──────────────────────────────────────────────
    'early.review_days': { en: 'Review these days', bn: 'এই দিনগুলো দেখুন' },
    'early.review_all_days': { en: 'Review all blocked days', bn: 'আটকে থাকা সব দিন দেখুন' },

    // ── Needs attention ─────────────────────────────────────────────────
    'attention.reason.early_leave_pending': {
        en: '{count} early leave requests are waiting for a decision: {dates}.',
        bn: '{count}টি আগে ছুটির আবেদন সিদ্ধান্তের অপেক্ষায়: {dates}।',
    },
    'attention.reason.attendance_provisional': {
        en: '{count} attendance days of this payroll cycle still await review: {dates}.',
        bn: 'এই বেতন চক্রের {count}টি হাজিরার দিন এখনও পর্যালোচনার অপেক্ষায়: {dates}।',
    },
} satisfies Record<string, Entry>;
