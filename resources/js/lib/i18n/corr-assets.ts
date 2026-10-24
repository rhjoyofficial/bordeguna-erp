import type { Entry } from './entry';

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): putting the things register and
 * the platform right. Things (Delete, Dispose, Undo disposal), hand-overs
 * (Reverse, Cancel), counts (Cancel), maintenance (Reopen), imports (Reverse
 * import), files and device logins to the Recycle bin, logins (Delete login,
 * Fix email, Deactivate, Reactivate) and the Recycle bin page. Button words
 * are the fixed ones of FINAL-PLAN section 2. Latin digits; tags, numbers and
 * dates never translate.
 */
export const corrAssets = {
    // ── Shared ──────────────────────────────────────────────────────────
    'corr.assets.delete': { en: 'Delete', bn: 'মুছুন' },
    'corr.assets.dispose': { en: 'Dispose', bn: 'বিদায় করুন' },
    'corr.assets.undo_disposal': { en: 'Undo disposal', bn: 'বিদায় বাতিল করুন' },
    'corr.assets.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'corr.assets.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'corr.assets.reopen': { en: 'Reopen', bn: 'আবার খুলুন' },
    'corr.assets.restore': { en: 'Restore', bn: 'ফিরিয়ে আনুন' },
    'corr.assets.reactivate': { en: 'Reactivate', bn: 'আবার চালু করুন' },
    'corr.assets.deactivate': { en: 'Deactivate', bn: 'বন্ধ করুন' },
    'corr.assets.stays': {
        en: '{action} {number}? The record stays, marked {state}, with your reason.',
        bn: '{number} {action} করবেন? রেকর্ডটি থাকবে, {state} চিহ্নসহ, আপনার কারণসহ।',
    },
    'corr.assets.to_bin': {
        en: 'Move {name} to the Recycle bin? Only the Owner can restore it.',
        bn: '{name} রিসাইকেল বিনে পাঠাবেন? শুধু মালিক ফিরিয়ে আনতে পারবেন।',
    },
    'corr.assets.state_reversed': { en: 'Reversed', bn: 'রিভার্সড' },
    'corr.assets.state_cancelled': { en: 'Cancelled', bn: 'বাতিল' },
    'corr.assets.state_disposed': { en: 'Disposed', bn: 'বিদায় করা' },
    'corr.assets.state_reopened': { en: 'Reopened', bn: 'আবার খোলা' },

    // ── Things ──────────────────────────────────────────────────────────
    'corr.assets.delete_thing_note': {
        en: 'Only a thing nobody ever used can be deleted. Its tag is never given to another thing.',
        bn: 'শুধু কখনো ব্যবহার হয়নি এমন জিনিস মোছা যায়। এর ট্যাগ আর কোনো জিনিসকে দেওয়া হবে না।',
    },
    'corr.assets.delete_selected': { en: 'Delete selected ({count})', bn: 'বাছাই করা মুছুন ({count})' },
    'corr.assets.delete_selected_title': { en: 'Move {count} things to the Recycle bin?', bn: '{count}টি জিনিস রিসাইকেল বিনে পাঠাবেন?' },
    'corr.assets.delete_selected_note': {
        en: 'All or nothing: if one of them was used, nothing is deleted and you are told which one.',
        bn: 'সব অথবা কিছুই না: একটিও ব্যবহার হয়ে থাকলে কিছুই মোছা হবে না, কোনটি তা জানানো হবে।',
    },
    'corr.assets.select': { en: 'Select', bn: 'বাছাই' },
    'corr.assets.dispose_note': {
        en: 'Dispose means the thing left the company. A thing registered by mistake is deleted instead.',
        bn: 'বিদায় মানে জিনিসটি প্রতিষ্ঠান থেকে চলে গেছে। ভুল করে নিবন্ধিত জিনিস মুছে ফেলুন, বিদায় নয়।',
    },
    'corr.assets.disposed_on': { en: 'Day it left', bn: 'যেদিন চলে গেছে' },
    'corr.assets.method': { en: 'How it left', bn: 'কীভাবে চলে গেছে' },
    'corr.assets.method_pick': { en: 'Choose how', bn: 'কীভাবে, বেছে নিন' },
    'corr.assets.method_sold': { en: 'Sold', bn: 'বিক্রি' },
    'corr.assets.method_scrapped': { en: 'Scrapped', bn: 'বাতিল মাল' },
    'corr.assets.method_donated': { en: 'Donated', bn: 'দান' },
    'corr.assets.method_lost': { en: 'Lost or stolen', bn: 'হারানো বা চুরি' },
    'corr.assets.disposed_banner': {
        en: 'Disposed on {date} ({method}) by {who}. Last with {holder}. Reason: {reason}',
        bn: '{date} তারিখে বিদায় ({method}), করেছেন {who}। শেষে ছিল {holder}-এর কাছে। কারণ: {reason}',
    },
    'corr.assets.undo_note': {
        en: 'Everything comes back as it was before the disposal: holder, place, status and maintenance plans.',
        bn: 'বিদায়ের আগে যেমন ছিল সব তেমন ফিরে আসবে: যার কাছে ছিল, জায়গা, অবস্থা ও রক্ষণাবেক্ষণ পরিকল্পনা।',
    },

    // ── Hand-overs, counts, maintenance ─────────────────────────────────
    'corr.assets.reverse_handover_note': {
        en: 'A new hand-over puts every thing back exactly as it was, on the same day. Both stay in the history.',
        bn: 'একটি নতুন হস্তান্তর একই দিনে সব জিনিস আগের মতো ফিরিয়ে দেবে। দুটোই ইতিহাসে থাকবে।',
    },
    'corr.assets.cancel_transfer_note': {
        en: 'The things never left their place. They stop being on their way.',
        bn: 'জিনিসগুলো তাদের জায়গা ছাড়েনি। এগুলো আর পথে আছে বলে দেখাবে না।',
    },
    'corr.assets.reversal_of': { en: 'Reversal of {number}', bn: '{number}-এর রিভার্স' },
    'corr.assets.reversed_by': { en: 'Reversed by {number}', bn: '{number} দিয়ে রিভার্স করা' },
    'corr.assets.reversed_badge': { en: 'Reversed', bn: 'রিভার্সড' },
    'corr.assets.cancel_count_note': {
        en: 'Nothing about the things changes. The place can be counted again.',
        bn: 'জিনিসগুলোর কিছুই বদলাবে না। জায়গাটি আবার গণনা করা যাবে।',
    },
    'corr.assets.count_cancelled': { en: 'Cancelled by {who} on {date}: {reason}', bn: '{date} তারিখে {who} বাতিল করেছেন: {reason}' },
    'corr.assets.reopen_note': {
        en: 'The record goes back to open. Complete it correctly or cancel it. The cost stays as it is.',
        bn: 'রেকর্ডটি আবার খোলা হবে। সঠিকভাবে শেষ করুন অথবা বাতিল করুন। খরচ যেমন আছে থাকবে।',
    },
    'corr.assets.reopened': { en: 'Reopened on {date}: {reason}', bn: '{date} তারিখে আবার খোলা হয়েছে: {reason}' },

    // ── Imports ─────────────────────────────────────────────────────────
    'corr.assets.imports': { en: 'Recent imports', bn: 'সাম্প্রতিক ইমপোর্ট' },
    'corr.assets.imports_none': { en: 'No sheet imported yet.', bn: 'এখনো কোনো শিট ইমপোর্ট হয়নি।' },
    'corr.assets.import_line': {
        en: '{number}: {created} registered, {updated} corrected, {when} by {who}',
        bn: '{number}: {created}টি নিবন্ধিত, {updated}টি সংশোধিত, {when}, করেছেন {who}',
    },
    'corr.assets.reverse_import': { en: 'Reverse import', bn: 'ইমপোর্ট রিভার্স' },
    'corr.assets.reverse_import_title': { en: 'Reverse import {number}?', bn: 'ইমপোর্ট {number} রিভার্স করবেন?' },
    'corr.assets.will_remove': { en: 'Goes to the Recycle bin ({count})', bn: 'রিসাইকেল বিনে যাবে ({count})' },
    'corr.assets.will_keep': { en: 'Kept, used since ({count})', bn: 'রাখা হবে, পরে ব্যবহার হয়েছে ({count})' },
    'corr.assets.updates_stay': {
        en: '{count} things the sheet corrected keep its changes.',
        bn: 'শিট যে {count}টি জিনিস সংশোধন করেছিল, সেগুলোর পরিবর্তন থাকবে।',
    },
    'corr.assets.import_reversed': { en: 'Reversed {when} by {who}: {reason}', bn: '{when} {who} রিভার্স করেছেন: {reason}' },

    // ── Files and device logins ─────────────────────────────────────────
    'corr.assets.file_delete_note': {
        en: 'The file cannot be opened while it is in the bin. Only the Owner can restore it.',
        bn: 'বিনে থাকা অবস্থায় ফাইলটি খোলা যাবে না। শুধু মালিক ফিরিয়ে আনতে পারবেন।',
    },
    'corr.assets.login_delete_note': {
        en: 'The stored password is hidden at once. Change the password at the provider too.',
        bn: 'সংরক্ষিত পাসওয়ার্ড সাথে সাথে লুকানো হবে। সেবাদাতার কাছেও পাসওয়ার্ড বদলান।',
    },

    // ── Logins (Settings > Team) ────────────────────────────────────────
    'corr.assets.fix_email': { en: 'Fix name or email', bn: 'নাম বা ইমেইল ঠিক করুন' },
    'corr.assets.fix_email_title': { en: 'Fix the login of {name}', bn: '{name}-এর লগইন ঠিক করুন' },
    'corr.assets.fix_email_before': {
        en: 'This login has never signed in, so the change happens now. Links sent to the old address stop working.',
        bn: 'এই লগইন কখনো সাইন ইন করেনি, তাই পরিবর্তন এখনই হবে। পুরোনো ঠিকানায় পাঠানো লিংক আর কাজ করবে না।',
    },
    'corr.assets.fix_email_after': {
        en: 'This login has signed in before. Only the Owner changes the email, and the new address must confirm it first.',
        bn: 'এই লগইন আগে সাইন ইন করেছে। শুধু মালিক ইমেইল বদলাতে পারেন, আর নতুন ঠিকানা থেকে আগে নিশ্চিত করতে হবে।',
    },
    'corr.assets.name': { en: 'Name', bn: 'নাম' },
    'corr.assets.email': { en: 'Email', bn: 'ইমেইল' },
    'corr.assets.save': { en: 'Save', bn: 'সংরক্ষণ' },
    'corr.assets.pending_email': { en: 'Waiting for {email} to confirm', bn: '{email} থেকে নিশ্চিতকরণের অপেক্ষা' },
    'corr.assets.delete_login': { en: 'Delete login', bn: 'লগইন মুছুন' },
    'corr.assets.delete_login_note': {
        en: 'Only a login that never signed in can be deleted. Its email stays reserved.',
        bn: 'শুধু কখনো সাইন ইন করেনি এমন লগইন মোছা যায়। এর ইমেইল সংরক্ষিত থাকবে।',
    },
    'corr.assets.deactivate_title': { en: 'Switch off {name}?', bn: '{name} বন্ধ করবেন?' },
    'corr.assets.deactivate_note': {
        en: 'They can no longer sign in. Everything they did stays.',
        bn: 'তিনি আর সাইন ইন করতে পারবেন না। তার করা সবকিছু থাকবে।',
    },
    'corr.assets.reactivate_title': { en: 'Reactivate {name}?', bn: '{name} আবার চালু করবেন?' },

    // ── Recycle bin ─────────────────────────────────────────────────────
    'corr.bin.title': { en: 'Recycle bin', bn: 'রিসাইকেল বিন' },
    'corr.bin.intro': {
        en: 'Deleted drafts and never-used records are kept here, hidden. Only the Owner can restore one. Nothing is ever deleted for good.',
        bn: 'মুছে ফেলা খসড়া ও কখনো ব্যবহার না হওয়া রেকর্ড এখানে লুকানো অবস্থায় থাকে। শুধু মালিক ফিরিয়ে আনতে পারেন। কিছুই চিরতরে মোছা হয় না।',
    },
    'corr.bin.empty': { en: 'The Recycle bin is empty.', bn: 'রিসাইকেল বিন খালি।' },
    'corr.bin.all': { en: 'Everything', bn: 'সব' },
    'corr.bin.type_asset_item': { en: 'Things', bn: 'জিনিস' },
    'corr.bin.type_file': { en: 'Files', bn: 'ফাইল' },
    'corr.bin.type_device_login': { en: 'Device logins', bn: 'ডিভাইস লগইন' },
    'corr.bin.type_user': { en: 'Logins', bn: 'লগইন' },
    'corr.bin.deleted': { en: 'Deleted {when} by {who}', bn: '{when} {who} মুছেছেন' },
    'corr.bin.days': { en: '{days} days in the bin', bn: '{days} দিন বিনে' },
    'corr.bin.restore_title': { en: 'Restore {name}?', bn: '{name} ফিরিয়ে আনবেন?' },
    'corr.bin.restore_note': {
        en: 'It comes back exactly as it was, and the restore is recorded with your reason.',
        bn: 'এটি ঠিক আগের মতো ফিরে আসবে, আর আপনার কারণসহ ফিরিয়ে আনা লেখা থাকবে।',
    },
    'corr.bin.switched_off': { en: 'Switched off', bn: 'বন্ধ করা' },
    'corr.bin.switched_off_note': {
        en: 'Used records are switched off, not deleted. Reactivate brings one back.',
        bn: 'ব্যবহৃত রেকর্ড মোছা হয় না, বন্ধ করা হয়। আবার চালু করলে ফিরে আসে।',
    },
    'corr.bin.switched_off_none': { en: 'Nothing is switched off.', bn: 'কিছুই বন্ধ নেই।' },
} satisfies Record<string, Entry>;
