import type { Entry } from './entry';

/**
 * ⭐ Corrections wave 3, HRM (24 Oct 2026): putting HR records right. Leave and
 * movement Cancel (after approval, days given back), Undo separation (a second
 * person confirms), show cause notice Withdraw, a draft employee to the Recycle
 * bin, and deleting an employee document with a reason. Button words are the
 * fixed ones of FINAL-PLAN section 2. Latin digits; dates never translate.
 */
export const corrHrm = {
    'corr.hrm.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'corr.hrm.withdraw': { en: 'Withdraw', bn: 'ফিরিয়ে নিন' },
    'corr.hrm.delete': { en: 'Delete', bn: 'মুছুন' },
    'corr.hrm.undo_separation': { en: 'Undo separation', bn: 'চাকরি ছাড়া বাতিল করুন' },
    'corr.hrm.send_for_approval': { en: 'Send for approval', bn: 'অনুমোদনের জন্য পাঠান' },
    'corr.hrm.cancelled': { en: 'Cancelled', bn: 'বাতিল হয়েছে' },
    'corr.hrm.cancelled_on': { en: 'Cancelled {date}', bn: '{date} তারিখে বাতিল' },
    'corr.hrm.withdrawn': { en: 'Withdrawn', bn: 'ফিরিয়ে নেওয়া হয়েছে' },
    'corr.hrm.withdrawn_on_date': { en: 'Withdrawn on {date}', bn: '{date} তারিখে ফিরিয়ে নেওয়া হয়েছে' },
    'corr.hrm.withdrawn_on': { en: 'Withdrawal date', bn: 'ফিরিয়ে নেওয়ার তারিখ' },

    // ── Leave and movement ──────────────────────────────────────────────
    'corr.hrm.cancel_leave_title': { en: 'Cancel {record}?', bn: '{record} বাতিল করবেন?' },
    'corr.hrm.cancel_leave_text': {
        en: 'The leave stays on record, marked Cancelled, with your reason. Its days go back to the balance and each day is judged again from the punches.',
        bn: 'ছুটিটি রেকর্ডে থাকবে, বাতিল হিসেবে চিহ্নিত, আপনার কারণসহ। দিনগুলো ব্যালেন্সে ফিরে যাবে এবং প্রতিটি দিন পাঞ্চ দেখে আবার হিসাব হবে।',
    },
    'corr.hrm.cancel_leave_waits': {
        en: 'Payroll for some of these days is already approved. A second person must approve this cancellation; then the days go back and the pay difference goes to the next open payroll. Approved pay is never changed.',
        bn: 'এই দিনগুলোর কিছু দিনের বেতন ইতিমধ্যে অনুমোদিত। এই বাতিল আরেকজনকে অনুমোদন করতে হবে; তারপর দিনগুলো ফিরে যাবে এবং বেতনের পার্থক্য পরের খোলা পে-রোলে যাবে। অনুমোদিত বেতন কখনো বদলায় না।',
    },
    'corr.hrm.cancel_movement_title': { en: 'Cancel {record}?', bn: '{record} বাতিল করবেন?' },
    'corr.hrm.cancel_movement_text': {
        en: 'The movement stays on record, marked Cancelled, with your reason. The day is judged again without it.',
        bn: 'মুভমেন্টটি রেকর্ডে থাকবে, বাতিল হিসেবে চিহ্নিত, আপনার কারণসহ। দিনটি এটি ছাড়া আবার হিসাব হবে।',
    },
    'corr.hrm.cancel_movement_waits': {
        en: 'Payroll for this day is already approved. A second person must approve this cancellation; the pay difference then goes to the next open payroll.',
        bn: 'এই দিনের বেতন ইতিমধ্যে অনুমোদিত। এই বাতিল আরেকজনকে অনুমোদন করতে হবে; তারপর বেতনের পার্থক্য পরের খোলা পে-রোলে যাবে।',
    },

    // ── Employee ────────────────────────────────────────────────────────
    'corr.hrm.delete_employee_title': { en: 'Move {record} to the Recycle bin?', bn: '{record} রিসাইকেল বিনে সরাবেন?' },
    'corr.hrm.delete_employee_text': {
        en: 'Only a draft nobody used can be deleted. The Owner can restore it from the Recycle bin, and the employee code is never given to anyone else.',
        bn: 'শুধু অব্যবহৃত খসড়া মোছা যায়। মালিক রিসাইকেল বিন থেকে এটি ফিরিয়ে আনতে পারবেন, আর কর্মী কোডটি আর কাউকে দেওয়া হবে না।',
    },
    'corr.hrm.undo_title': { en: 'Undo the separation of {record}?', bn: '{record}-এর চাকরি ছাড়া বাতিল করবেন?' },
    'corr.hrm.undo_text': {
        en: 'A second person must approve this. Then the status, the assignment and the days after the leaving date come back. The ERP login stays off until somebody switches it on under Users.',
        bn: 'এটি আরেকজনকে অনুমোদন করতে হবে। তারপর অবস্থা, পদায়ন এবং চলে যাওয়ার তারিখের পরের দিনগুলো ফিরে আসবে। ইউজার পাতায় কেউ চালু না করা পর্যন্ত ইআরপি লগইন বন্ধ থাকবে।',
    },
    'corr.hrm.delete_document': { en: 'Delete', bn: 'মুছুন' },
    'corr.hrm.delete_document_title': { en: 'Delete {record}?', bn: '{record} মুছবেন?' },
    'corr.hrm.delete_document_text': {
        en: 'Say why. The deletion, who did it and why stay in the History of this employee.',
        bn: 'কারণ লিখুন। মোছা, কে মুছেছেন এবং কেন, তা এই কর্মীর ইতিহাসে থেকে যাবে।',
    },

    // ── Show cause notice ───────────────────────────────────────────────
    'corr.hrm.withdraw_notice_title': { en: 'Withdraw {record}?', bn: '{record} ফিরিয়ে নেবেন?' },
    'corr.hrm.withdraw_notice_text': {
        en: 'The notice stays on record, marked Withdrawn, with the date and your reason. If it reached the employee, they see it withdrawn; if not, it leaves their list.',
        bn: 'নোটিশটি রেকর্ডে থাকবে, ফিরিয়ে নেওয়া হিসেবে চিহ্নিত, তারিখ ও আপনার কারণসহ। কর্মীর কাছে পৌঁছে থাকলে তিনি এটি ফিরিয়ে নেওয়া দেখবেন; না পৌঁছালে তাঁর তালিকা থেকে সরে যাবে।',
    },
    'corr.hrm.notice_withdrawn_banner': {
        en: 'This notice was withdrawn on {date}.',
        bn: 'এই নোটিশটি {date} তারিখে ফিরিয়ে নেওয়া হয়েছে।',
    },
} satisfies Record<string, Entry>;
