import type { Entry } from './entry';

/**
 * Counterparties and the loan heads (loans plan v2, phase 1, 14 Oct 2026):
 * the picker on voucher and journal lines, and the Counterparties screens.
 */
export const counterparties = {
    // ── The picker on a loan line ────────────────────────────────────────
    'counterparties.picker.which': { en: 'Which counterparty?', bn: 'কোন প্রতিপক্ষ?' },
    'counterparties.picker.related': { en: '{name} (related party)', bn: '{name} (সম্পর্কিত পক্ষ)' },
    'counterparties.picker.new': { en: 'New counterparty', bn: 'নতুন প্রতিপক্ষ' },

    // ── The short note under a loan head in the ledger-head picker ──────
    'counterparties.hint.loan': {
        en: 'Names a counterparty. Money between our own businesses: Inter-business transfers.',
        bn: 'একজন প্রতিপক্ষের নাম লাগে। আমাদের নিজেদের ব্যবসার মধ্যে টাকা: আন্তঃব্যবসা স্থানান্তর।',
    },
    'counterparties.hint.related_only': {
        en: 'Related parties only. Money between our own businesses: Inter-business transfers.',
        bn: 'শুধু সম্পর্কিত পক্ষ। আমাদের নিজেদের ব্যবসার মধ্যে টাকা: আন্তঃব্যবসা স্থানান্তর।',
    },
    'counterparties.hint.not_related': {
        en: 'Not for a related party (use 11752). Money between our own businesses: Inter-business transfers.',
        bn: 'সম্পর্কিত পক্ষের জন্য নয় (11752 ব্যবহার করুন)। আমাদের নিজেদের ব্যবসার মধ্যে টাকা: আন্তঃব্যবসা স্থানান্তর।',
    },
    'counterparties.hint.owner_loan': {
        en: "Only for a documented owner loan agreed with the accountant; money the owner puts in is Owner's Capital 3100.",
        bn: 'শুধু হিসাবরক্ষকের সাথে ঠিক করা, লিখিত মালিক-ঋণের জন্য; মালিক যে টাকা ব্যবসায় দেন তা মালিকের মূলধন 3100।',
    },

    // ── The note under a line once a loan head is chosen ────────────────
    'counterparties.note.loan': {
        en: 'Every line on this head names its counterparty. Money between our own businesses is not a loan: use Finance & Accounts > Inter-business transfers.',
        bn: 'এই হেডের প্রতিটি লাইনে প্রতিপক্ষের নাম থাকে। আমাদের নিজেদের ব্যবসার মধ্যে টাকা ঋণ নয়: Finance & Accounts > Inter-business transfers ব্যবহার করুন।',
    },
    'counterparties.note.related_only': {
        en: 'Only a counterparty marked related can be chosen here. Money between our own businesses is not a loan: use Finance & Accounts > Inter-business transfers.',
        bn: 'এখানে শুধু সম্পর্কিত পক্ষ হিসেবে চিহ্নিত প্রতিপক্ষ বাছাই করা যায়। আমাদের নিজেদের ব্যবসার মধ্যে টাকা ঋণ নয়: Finance & Accounts > Inter-business transfers ব্যবহার করুন।',
    },
    'counterparties.note.not_related': {
        en: 'A loan to a related party goes on 11752 Loans to Related Parties. Money between our own businesses is not a loan: use Finance & Accounts > Inter-business transfers.',
        bn: 'সম্পর্কিত পক্ষকে দেওয়া ঋণ 11752 Loans to Related Parties-এ যায়। আমাদের নিজেদের ব্যবসার মধ্যে টাকা ঋণ নয়: Finance & Accounts > Inter-business transfers ব্যবহার করুন।',
    },
    'counterparties.note.owner_loan': {
        en: "Only for a documented owner loan agreed with the accountant; money the owner puts in is Owner's Capital 3100.",
        bn: 'শুধু হিসাবরক্ষকের সাথে ঠিক করা, লিখিত মালিক-ঋণের জন্য; মালিক যে টাকা ব্যবসায় দেন তা মালিকের মূলধন 3100।',
    },
} satisfies Record<string, Entry>;
