import type { Entry } from './entry';

/**
 * ⭐ Corrections wave 3, CRM (24 Oct 2026): the buttons and dialogs of a call,
 * visit or note (Edit, Delete within 24 hours, Correct after), a lead
 * (Reverse conversion, Reopen, Delete), a deal (Reopen), a phone book contact
 * (Delete) and an applied phone book file (Reverse import). The button words
 * are fixed (FINAL-PLAN 2). Numbers, dates and money never translate.
 */
export const corrCrm = {
    // ── Shared ──────────────────────────────────────────────────────────
    'corr.crm.edit': { en: 'Edit', bn: 'সম্পাদনা' },
    'corr.crm.delete': { en: 'Delete', bn: 'মুছুন' },
    'corr.crm.correct': { en: 'Correct', bn: 'সংশোধন' },
    'corr.crm.reopen': { en: 'Reopen', bn: 'আবার খুলুন' },
    'corr.crm.save': { en: 'Save', bn: 'সংরক্ষণ' },
    'corr.crm.recently_deleted': { en: 'Recently deleted', bn: 'সম্প্রতি মুছে ফেলা' },
    'corr.crm.bin_note': {
        en: 'It moves to the Recycle bin. Only the Owner can restore it.',
        bn: 'এটি রিসাইকেল বিনে যাবে। শুধু মালিক ফিরিয়ে আনতে পারবেন।',
    },

    // ── Calls, visits and notes ─────────────────────────────────────────
    'corr.crm.activity.when': { en: 'When', bn: 'কখন' },
    'corr.crm.activity.direction': { en: 'Which way', bn: 'কোন দিকে' },
    'corr.crm.activity.summary': { en: 'What happened, in one line', bn: 'কী হয়েছে, এক লাইনে' },
    'corr.crm.activity.body': { en: 'Notes', bn: 'নোট' },
    'corr.crm.activity.outcome': { en: 'Outcome', bn: 'ফলাফল' },
    'corr.crm.activity.minutes': { en: 'Minutes', bn: 'মিনিট' },
    'corr.crm.activity.edit_title': { en: 'Edit {label}', bn: '{label} সম্পাদনা' },
    'corr.crm.activity.edit_note': {
        en: 'You can change your own entry for 24 hours. History keeps what it said before.',
        bn: 'নিজের এন্ট্রি 24 ঘণ্টা পর্যন্ত বদলানো যায়। আগে কী লেখা ছিল তা ইতিহাসে থাকে।',
    },
    'corr.crm.activity.correct_title': { en: 'Correct {label}', bn: '{label} সংশোধন' },
    'corr.crm.activity.correct_note': {
        en: 'A new entry replaces it. The old one stays, struck through, with your reason. The kind, the place and the photos stay as recorded.',
        bn: 'একটি নতুন এন্ট্রি এর জায়গা নেবে। পুরনোটি কাটা দাগসহ আপনার কারণসহ থাকবে। ধরন, জায়গা ও ছবি যেমন ছিল তেমন থাকবে।',
    },
    'corr.crm.activity.delete_title': { en: 'Delete {label}?', bn: '{label} মুছবেন?' },
    'corr.crm.activity.corrected_badge': { en: 'Corrected', bn: 'সংশোধিত' },
    'corr.crm.activity.edited_badge': { en: 'Edited', bn: 'বদলানো হয়েছে' },
    'corr.crm.activity.correction_badge': { en: 'Correction', bn: 'সংশোধন' },
    'corr.crm.activity.corrected_why': { en: 'Corrected: {reason}', bn: 'সংশোধনের কারণ: {reason}' },

    // ── Leads ───────────────────────────────────────────────────────────
    'corr.crm.lead.reverse': { en: 'Reverse conversion', bn: 'রূপান্তর ফেরত' },
    'corr.crm.lead.reverse_title': { en: 'Reverse the conversion of {name}?', bn: '{name}-এর রূপান্তর ফেরত নেবেন?' },
    'corr.crm.lead.reverse_note': {
        en: 'The lead goes back to the status it had before. A customer or supplier this conversion created is switched off, never deleted; one that existed before is only unlinked.',
        bn: 'লিডটি আগের অবস্থায় ফিরে যাবে। এই রূপান্তরে তৈরি গ্রাহক বা সরবরাহকারী বন্ধ হবে, মোছা হবে না; আগে থেকে থাকা হলে শুধু সংযোগ খুলে যাবে।',
    },
    'corr.crm.lead.reopen_title': { en: 'Reopen {name}?', bn: '{name} আবার খুলবেন?' },
    'corr.crm.lead.reopen_note': {
        en: 'It goes back to the status it had before it was lost. The lost date and reason are cleared; History keeps them.',
        bn: 'হারানোর আগের অবস্থায় ফিরে যাবে। হারানোর তারিখ ও কারণ মুছে যাবে; ইতিহাসে থাকবে।',
    },
    'corr.crm.lead.delete_title': { en: 'Move {name} to the Recycle bin?', bn: '{name} রিসাইকেল বিনে পাঠাবেন?' },
    'corr.crm.lead.delete_note': {
        en: 'Only a lead nobody worked can be deleted. Its open follow-ups are cancelled with it. Only the Owner can restore it.',
        bn: 'যে লিডে কেউ কাজ করেনি শুধু সেটিই মোছা যায়। এর খোলা ফলো-আপ বাতিল হবে। শুধু মালিক ফিরিয়ে আনতে পারবেন।',
    },

    // ── Deals ───────────────────────────────────────────────────────────
    'corr.crm.deal.reopen_title': { en: 'Reopen {name}?', bn: '{name} আবার খুলবেন?' },
    'corr.crm.deal.reopen_note': {
        en: 'It goes back on the board in the stage it was in. No message is sent.',
        bn: 'আগের ধাপে বোর্ডে ফিরে যাবে। কোনো বার্তা পাঠানো হবে না।',
    },

    // ── Phone book ──────────────────────────────────────────────────────
    'corr.crm.contact.delete_title': { en: 'Move {name} to the Recycle bin?', bn: '{name} রিসাইকেল বিনে পাঠাবেন?' },
    'corr.crm.imports.title': { en: 'Recent imports', bn: 'সাম্প্রতিক ইমপোর্ট' },
    'corr.crm.imports.line': { en: '{created} created, {updated} updated', bn: '{created}টি নতুন, {updated}টি বদলানো' },
    'corr.crm.imports.reverse': { en: 'Reverse import', bn: 'ইমপোর্ট ফেরত' },
    'corr.crm.imports.reversed': { en: 'Reversed', bn: 'ফেরত নেওয়া হয়েছে' },
    'corr.crm.imports.reverse_title': { en: 'Reverse this import?', bn: 'এই ইমপোর্ট ফেরত নেবেন?' },
    'corr.crm.imports.reverse_note': {
        en: 'Only rows nobody changed since are taken back. The import stays in the list, marked Reversed.',
        bn: 'এরপর যেগুলো কেউ বদলায়নি শুধু সেগুলো ফেরত যাবে। ইমপোর্টটি তালিকায় থাকবে, ফেরত চিহ্নসহ।',
    },
    'corr.crm.imports.remove': { en: 'To the Recycle bin', bn: 'রিসাইকেল বিনে যাবে' },
    'corr.crm.imports.restore': { en: 'Put back as before', bn: 'আগের মতো ফেরত যাবে' },
    'corr.crm.imports.kept': { en: 'Kept', bn: 'রাখা হবে' },
    'corr.crm.imports.loading': { en: 'Working out what this does...', bn: 'কী হবে তা দেখা হচ্ছে...' },
} satisfies Record<string, Entry>;
