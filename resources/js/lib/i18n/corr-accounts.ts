import type { Entry } from './entry';

/**
 * Corrections wave 2, accounts (B7, 24 Oct 2026): vouchers, money and
 * inter-business transfers, manual journals, party adjustments, cheques, bank
 * statement imports, accruals, the duplicate warning and Withdraw.
 * Latin digits; amounts, dates and record numbers never translate.
 */
export const corrAccounts = {
    // ── The buttons ──────────────────────────────────────────────────────
    'corr.acc.correct': { en: 'Correct', bn: 'সংশোধন' },
    'corr.acc.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'corr.acc.fix_details': { en: 'Fix details', bn: 'তথ্য ঠিক করুন' },
    'corr.acc.withdraw': { en: 'Withdraw', bn: 'ফিরিয়ে নিন' },
    'corr.acc.copy': { en: 'Copy', bn: 'কপি' },
    'corr.acc.undo_status': { en: 'Undo status', bn: 'অবস্থা বাতিল করুন' },
    'corr.acc.remove_import': { en: 'Remove import', bn: 'ইমপোর্ট সরান' },
    'corr.acc.remove_line': { en: 'Remove', bn: 'সরান' },
    'corr.acc.reverse_month': { en: 'Reverse this month', bn: 'এই মাস রিভার্স করুন' },
    'corr.acc.preview_and_correct': { en: 'Preview and correct', bn: 'দেখে নিয়ে সংশোধন করুন' },
    'corr.acc.save_anyway': { en: 'Save anyway', bn: 'তবুও সংরক্ষণ করুন' },
    'corr.acc.go_back': { en: 'Go back', bn: 'ফিরে যান' },
    'corr.acc.save': { en: 'Save', bn: 'সংরক্ষণ' },

    // ── Labels on a record ───────────────────────────────────────────────
    'corr.acc.reversed': { en: 'Reversed', bn: 'রিভার্স করা' },
    'corr.acc.corrected': { en: 'Corrected', bn: 'সংশোধিত' },
    'corr.acc.withdrawn': { en: 'Withdrawn', bn: 'ফিরিয়ে নেওয়া' },
    'corr.acc.correction_of': { en: 'Correction of {number}', bn: '{number}-এর সংশোধন' },
    'corr.acc.corrected_into': { en: 'Corrected. See {number}.', bn: 'সংশোধিত। দেখুন {number}।' },
    'corr.acc.reversed_on': { en: 'Reversed on {date} by {by}: {reason}', bn: '{date} তারিখে {by} রিভার্স করেছেন: {reason}' },
    'corr.acc.show_corrections': { en: 'Show corrections', bn: 'সংশোধনগুলো দেখান' },
    'corr.acc.reversed_copy_hint': {
        en: 'Reversed. Copy opens a new one filled in from it, to fix and post.',
        bn: 'রিভার্স করা হয়েছে। কপি চাপলে এর তথ্য দিয়ে নতুন একটি খুলবে, ঠিক করে পোস্ট করুন।',
    },

    // ── Correct forms ────────────────────────────────────────────────────
    'corr.acc.banner': {
        en: 'You are correcting {number} ({amount}). Change what was wrong. {number} stays, marked Corrected, and a new record takes its place.',
        bn: 'আপনি {number} ({amount}) সংশোধন করছেন। যা ভুল ছিল তা বদলান। {number} থেকে যাবে, "সংশোধিত" চিহ্নসহ, আর তার জায়গায় নতুন রেকর্ড হবে।',
    },
    'corr.acc.correct_title': { en: 'Correct {number}', bn: '{number} সংশোধন' },
    'corr.acc.date': { en: 'Date', bn: 'তারিখ' },
    'corr.acc.from': { en: 'From', bn: 'যেখান থেকে' },
    'corr.acc.to': { en: 'To', bn: 'যেখানে' },
    'corr.acc.amount': { en: 'Amount', bn: 'পরিমাণ (টাকা)' },
    'corr.acc.fee': { en: 'Fee', bn: 'চার্জ' },
    'corr.acc.fee_head': { en: 'Fee head', bn: 'চার্জের খাত' },
    'corr.acc.reference': { en: 'Reference', bn: 'রেফারেন্স' },
    'corr.acc.note': { en: 'Note', bn: 'নোট' },
    'corr.acc.choose': { en: 'Choose', bn: 'বেছে নিন' },

    // ── Fix details ──────────────────────────────────────────────────────
    'corr.acc.fix_title': { en: 'Fix details of {number}', bn: '{number}-এর তথ্য ঠিক করুন' },
    'corr.acc.fix_help': {
        en: 'Text only: no amount, date, head or journal changes. The old and new text and your reason are kept in its history.',
        bn: 'শুধু লেখা: টাকা, তারিখ, খাত বা জার্নাল বদলাবে না। আগের ও নতুন লেখা আর আপনার কারণ ইতিহাসে থাকবে।',
    },
    'corr.acc.narration': { en: 'Narration', bn: 'বিবরণ' },
    'corr.acc.paid_to': { en: 'Paid to / Received from (spelling)', bn: 'যাকে দেওয়া / যার থেকে পাওয়া (বানান)' },
    'corr.acc.party_fixed': {
        en: 'This name is a supplier, customer or employee from the list. Fix it on their own record.',
        bn: 'এই নামটি তালিকার সরবরাহকারী, গ্রাহক বা কর্মীর। তাদের নিজের রেকর্ডে ঠিক করুন।',
    },
    'corr.acc.cheque_bank': { en: 'Cheque bank / branch', bn: 'চেকের ব্যাংক / শাখা' },
    'corr.acc.line_text': { en: 'Line {line}: {account}', bn: 'লাইন {line}: {account}' },

    // ── Withdraw ─────────────────────────────────────────────────────────
    'corr.acc.withdraw_title': { en: 'Withdraw {number}?', bn: '{number} ফিরিয়ে নেবেন?' },
    'corr.acc.withdraw_help': {
        en: 'It comes back to you as it was, and nothing is approved. You can change it and send it again.',
        bn: 'এটি যেমন ছিল তেমনই আপনার কাছে ফিরে আসবে, কিছুই অনুমোদিত হবে না। বদলে আবার পাঠাতে পারবেন।',
    },
    'corr.acc.sent_by_me': { en: 'Sent by me, waiting', bn: 'আমার পাঠানো, অপেক্ষায়' },
    'corr.acc.sent_by_me_help': {
        en: 'Requests you sent that nobody has decided yet. Withdraw one to take it back.',
        bn: 'আপনার পাঠানো অনুরোধ, যার এখনো সিদ্ধান্ত হয়নি। ফেরত নিতে ফিরিয়ে নিন চাপুন।',
    },
    'corr.acc.open': { en: 'Open', bn: 'খুলুন' },

    // ── Cheques, bank imports, accruals ──────────────────────────────────
    'corr.acc.undo_title': { en: 'Undo the status of cheque {number}?', bn: 'চেক {number}-এর অবস্থা বাতিল করবেন?' },
    'corr.acc.undo_help_cleared': {
        en: 'The cheque becomes pending again. No journal moves.',
        bn: 'চেকটি আবার অপেক্ষমাণ হবে। কোনো জার্নাল বদলাবে না।',
    },
    'corr.acc.undo_help_bounced': {
        en: 'The payment is posted again as a new voucher on its own date, with the same cheque. The old voucher stays Reversed.',
        bn: 'পেমেন্টটি একই চেকসহ নিজের তারিখে নতুন ভাউচার হিসেবে আবার পোস্ট হবে। পুরোনো ভাউচার রিভার্স করাই থাকবে।',
    },
    'corr.acc.remove_import_title': { en: 'Remove import {name}?', bn: 'ইমপোর্ট {name} সরাবেন?' },
    'corr.acc.remove_import_help': {
        en: 'Its {count} statement lines are taken out. Nothing in the books moves. The same file can be imported again.',
        bn: 'এর {count}টি স্টেটমেন্ট লাইন সরানো হবে। হিসাবের খাতায় কিছু বদলাবে না। একই ফাইল আবার ইমপোর্ট করা যাবে।',
    },
    'corr.acc.remove_line_title': { en: 'Remove this statement line?', bn: 'এই স্টেটমেন্ট লাইন সরাবেন?' },
    'corr.acc.remove_line_help': {
        en: 'Only the bank copy goes. Nothing in the books moves.',
        bn: 'শুধু ব্যাংকের কপি সরবে। হিসাবের খাতায় কিছু বদলাবে না।',
    },
    'corr.acc.import_matched': {
        en: 'A line of this import is matched to the books. Unmatch it first.',
        bn: 'এই ইমপোর্টের একটি লাইন খাতার সাথে মেলানো আছে। আগে মেলানো বাতিল করুন।',
    },
    'corr.acc.imports': { en: 'Imports', bn: 'ইমপোর্টসমূহ' },
    'corr.acc.lines_count': { en: '{count} lines, {matched} matched', bn: '{count}টি লাইন, {matched}টি মেলানো' },
    'corr.acc.reverse_month_title': { en: 'Reverse {month} of {name}?', bn: '{name}-এর {month} রিভার্স করবেন?' },
    'corr.acc.reverse_month_help': {
        en: 'That month is undone on its own date, or on the first open day if it is closed. The schedule stops; set up a corrected one if it is still needed.',
        bn: 'মাসটি নিজের তারিখে, বা বন্ধ থাকলে প্রথম খোলা দিনে, বাতিল হবে। শিডিউলটি থেমে যাবে; দরকার হলে সঠিক একটি নতুন করে বানান।',
    },

    // ── The duplicate warning ────────────────────────────────────────────
    'corr.acc.possible_duplicate': { en: 'Possible duplicate.', bn: 'সম্ভবত একই জিনিস আবার।' },
    'corr.acc.saved_by': { en: 'was saved {ago} by {by}', bn: '{ago} {by} সংরক্ষণ করেছেন' },
    'corr.acc.hidden_duplicate': {
        en: 'A similar record exists that you do not have access to see. Ask the accountant before saving.',
        bn: 'একই রকম একটি রেকর্ড আছে যা আপনি দেখতে পারেন না। সংরক্ষণের আগে হিসাবরক্ষককে জিজ্ঞেস করুন।',
    },
    'corr.acc.is_different': { en: 'Is this a different one?', bn: 'এটি কি আলাদা একটি?' },
} satisfies Record<string, Entry>;
