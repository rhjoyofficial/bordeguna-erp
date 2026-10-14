import type { Entry } from './entry';

/**
 * The Relax zone (14 Oct 2026): the page everybody signed in can open, and
 * Settings > Relax zone where Owners and Managers keep the list. An item's own
 * title and description are data a manager typed and never come through here.
 */
export const relax = {
    'relax.nav': { en: 'Relax zone', bn: 'রিল্যাক্স জোন' },
    'relax.title': { en: 'Relax zone', bn: 'রিল্যাক্স জোন' },
    'relax.heading': { en: 'Take a short break', bn: 'একটু বিরতি নিন' },
    'relax.intro': {
        en: 'Something calm to listen to or watch for a few minutes. Nothing plays until you press play, and only one thing plays at a time.',
        bn: 'কয়েক মিনিট শোনার বা দেখার মতো শান্ত কিছু। আপনি চালু না করলে কিছুই বাজবে না, আর একবারে একটিই চলবে।',
    },
    'relax.empty': {
        en: 'Nothing here yet. A manager can add audio and videos from Settings > Relax zone.',
        bn: 'এখনও কিছু নেই। একজন ম্যানেজার সেটিংস > রিল্যাক্স জোন থেকে অডিও আর ভিডিও যোগ করতে পারেন।',
    },
    'relax.kind.audio': { en: 'Audio', bn: 'অডিও' },
    'relax.kind.video': { en: 'Video', bn: 'ভিডিও' },
    'relax.kind.youtube': { en: 'YouTube', bn: 'ইউটিউব' },
    'relax.play_named': { en: 'Play {title}', bn: '{title} চালান' },
    'relax.youtube_note': { en: 'Opens the YouTube player when you press play.', bn: 'চালু করলে ইউটিউব প্লেয়ার খুলবে।' },
    'relax.manage_link': { en: 'Manage the list', bn: 'তালিকা সাজান' },

    // ── Settings > Relax zone ────────────────────────────────────────────
    'relax.settings.description': {
        en: 'What the Relax zone offers. Shared by every business; everybody signed in can open it.',
        bn: 'রিল্যাক্স জোনে কী থাকবে। সব ব্যবসার জন্য একই; লগইন করা সবাই এটি খুলতে পারেন।',
    },
    'relax.settings.new': { en: 'Add item', bn: 'আইটেম যোগ করুন' },
    'relax.settings.edit': { en: 'Edit', bn: 'সম্পাদনা' },
    'relax.settings.edit_title': { en: 'Edit item', bn: 'আইটেম সম্পাদনা' },
    'relax.settings.none': { en: 'Nothing on the Relax zone yet.', bn: 'রিল্যাক্স জোনে এখনও কিছু নেই।' },
    'relax.settings.active': { en: 'On', bn: 'চালু' },
    'relax.settings.paused': { en: 'Paused', bn: 'বন্ধ রাখা' },
    'relax.settings.pause': { en: 'Pause', bn: 'বন্ধ রাখুন' },
    'relax.settings.resume': { en: 'Resume', bn: 'আবার চালু করুন' },
    'relax.settings.delete': { en: 'Delete', bn: 'মুছুন' },
    'relax.settings.delete_confirm': {
        en: 'Delete this item? Its uploaded file is deleted too.',
        bn: 'এই আইটেমটি মুছবেন? এর আপলোড করা ফাইলও মুছে যাবে।',
    },
    'relax.settings.move_up': { en: 'Move up', bn: 'উপরে নিন' },
    'relax.settings.move_down': { en: 'Move down', bn: 'নিচে নিন' },
    'relax.settings.open_page': { en: 'Open the Relax zone', bn: 'রিল্যাক্স জোন খুলুন' },
    'relax.settings.size': { en: '{size} MB', bn: '{size} মেগাবাইট' },
    'relax.settings.no_file': { en: 'No file', bn: 'ফাইল নেই' },
    'relax.settings.form_hint': {
        en: 'Files are checked by their contents, not their names.',
        bn: 'ফাইল তার নাম দিয়ে নয়, ভেতরের তথ্য দিয়ে যাচাই করা হয়।',
    },
    'relax.field.title': { en: 'Title', bn: 'শিরোনাম' },
    'relax.field.description': { en: 'Short description (optional)', bn: 'ছোট বর্ণনা (ঐচ্ছিক)' },
    'relax.field.kind': { en: 'What it is', bn: 'ধরন' },
    'relax.field.kind.audio': { en: 'Audio file (MP3, OGG, M4A or WAV, up to 20 MB)', bn: 'অডিও ফাইল (MP3, OGG, M4A বা WAV, সর্বোচ্চ 20 MB)' },
    'relax.field.kind.video': { en: 'Uploaded video (MP4 or WebM, up to 100 MB)', bn: 'আপলোড করা ভিডিও (MP4 বা WebM, সর্বোচ্চ 100 MB)' },
    'relax.field.kind.youtube': { en: 'YouTube video', bn: 'ইউটিউব ভিডিও' },
    'relax.field.file': { en: 'File', bn: 'ফাইল' },
    'relax.field.youtube': { en: 'YouTube address or video id', bn: 'ইউটিউব ঠিকানা বা ভিডিও আইডি' },
    'relax.field.sort_order': { en: 'Order (lower comes first)', bn: 'ক্রম (ছোট সংখ্যা আগে)' },
    'relax.field.active': { en: 'On', bn: 'চালু' },
    'relax.settings.file_kept': {
        en: 'The current file is kept unless you pick a new one.',
        bn: 'নতুন ফাইল না বাছলে বর্তমান ফাইলই থাকবে।',
    },
    'relax.settings.save': { en: 'Save', bn: 'সংরক্ষণ' },
    'relax.settings.saving': { en: 'Saving...', bn: 'সংরক্ষণ হচ্ছে...' },
    'relax.settings.cancel': { en: 'Cancel', bn: 'বাতিল' },
} satisfies Record<string, Entry>;
