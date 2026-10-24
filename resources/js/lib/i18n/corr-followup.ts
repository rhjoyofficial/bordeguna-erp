import type { Entry } from './entry';

/**
 * ⭐ Corrections follow-up (B13, 24 Oct 2026): Withdraw for every approval
 * type, duplicate warnings on parties, products and people, opening documents
 * kept when removed, the Owner's closed-month journal, Reactivate and Delete on
 * customers and suppliers, and the Owner's merges.
 */
export const corrFollowup = {
    // Withdraw
    'corr.fu.withdrawn': { en: 'Withdrawn', bn: 'ফিরিয়ে নেওয়া' },
    'corr.fu.withdraw_draft': {
        en: 'Withdrawing brings it back to you as a draft. Nothing was approved. Change it and send it again.',
        bn: 'ফিরিয়ে নিলে এটা খসড়া হয়ে আপনার কাছে ফিরে আসবে। কিছুই অনুমোদন হয়নি। ঠিক করে আবার পাঠান।',
    },
    'corr.fu.withdraw_cancelled': {
        en: 'Withdrawing closes it as cancelled. Nothing was approved and nothing changes. Raise a new one if you still need it.',
        bn: 'ফিরিয়ে নিলে এটা বাতিল হয়ে বন্ধ হবে। কিছুই অনুমোদন হয়নি, কিছুই বদলাবে না। দরকার হলে নতুন করে পাঠান।',
    },

    // Opening receivable and payable: Reverse, kept and marked
    'corr.fu.opening_reverse_title': { en: 'Reverse {number}?', bn: '{number} রিভার্স করবেন?' },
    'corr.fu.opening_reverse_help': {
        en: 'The opening balance stays, marked Reversed, with your reason. Its journal is undone and the party owes nothing on it any more.',
        bn: 'ওপেনিং ব্যালান্সটা থেকে যাবে, রিভার্স চিহ্ন আর আপনার কারণসহ। এর জার্নাল উল্টে যাবে, এর ওপর আর কোনো পাওনা থাকবে না।',
    },
    'corr.fu.reverse_on': { en: 'Reverse on', bn: 'কোন তারিখে রিভার্স' },
    'corr.fu.its_own_date': { en: 'Its own date ({date})', bn: 'নিজের তারিখে ({date})' },
    'corr.fu.today': { en: 'Today', bn: 'আজ' },
    'corr.fu.month_closed_today': {
        en: 'Its month is closed, so the reversal goes on today.',
        bn: 'ওই মাস বন্ধ, তাই রিভার্স আজকের তারিখে হবে।',
    },

    // Customer and supplier: Reactivate, Delete to the bin
    'corr.fu.record_title': { en: 'This record', bn: 'এই রেকর্ড' },
    'corr.fu.reactivate': { en: 'Reactivate', bn: 'আবার চালু করুন' },
    'corr.fu.delete': { en: 'Delete', bn: 'মুছুন' },
    'corr.fu.delete_title': { en: 'Move {name} to the Recycle bin?', bn: '{name} রিসাইকেল বিনে পাঠাবেন?' },
    'corr.fu.delete_help': {
        en: 'It was never used, so it can go to the Recycle bin. Only the Owner can restore it. Nothing is deleted for good.',
        bn: 'এটা কখনো ব্যবহার হয়নি, তাই রিসাইকেল বিনে যেতে পারে। শুধু মালিক ফিরিয়ে আনতে পারবেন। কিছুই চিরতরে মোছা হয় না।',
    },
    'corr.fu.merged_into': { en: 'Merged into', bn: 'যার সাথে মেলানো হয়েছে:' },

    // Merges (Owner only, dry run first)
    'corr.fu.merge': { en: 'Merge', bn: 'একত্র করুন' },
    'corr.fu.merge_customer_title': { en: 'Merge {name} into another customer?', bn: '{name} অন্য একজন কাস্টমারের সাথে একত্র করবেন?' },
    'corr.fu.merge_supplier_title': { en: 'Merge {name} into another supplier?', bn: '{name} অন্য একজন সাপ্লায়ারের সাথে একত্র করবেন?' },
    'corr.fu.merge_help': {
        en: 'Every document, ledger line, statement and phone book link of {name} moves to the one you keep. {name} is switched off and points at it. Run the dry run first and read what will move.',
        bn: '{name}-এর সব ডকুমেন্ট, লেজারের লাইন, স্টেটমেন্ট আর ফোনবুকের লিংক যেটা রাখবেন সেখানে চলে যাবে। {name} বন্ধ হয়ে সেটার দিকে নির্দেশ করবে। আগে ড্রাই রান চালিয়ে দেখুন কী কী সরবে।',
    },
    'corr.fu.keep_which': { en: 'Keep this one', bn: 'এটা রাখুন' },
    'corr.fu.choose': { en: 'Choose', bn: 'বাছাই করুন' },
    'corr.fu.dry_run': { en: 'Dry run', bn: 'ড্রাই রান' },
    'corr.fu.dry_run_result': { en: 'Dry run: nothing has changed yet', bn: 'ড্রাই রান: এখনো কিছুই বদলায়নি' },
    'corr.fu.nothing_moves': { en: 'Nothing points at it; only the record is switched off.', bn: 'কিছুই এর সাথে যুক্ত নেই; শুধু রেকর্ডটা বন্ধ হবে।' },
    'corr.fu.rows_move': { en: '{rows} in {table}', bn: '{table}-এ {rows}টি' },
    'corr.fu.merge_failed': { en: 'It could not be done. Nothing changed.', bn: 'করা গেল না। কিছুই বদলায়নি।' },
    'corr.fu.balance_gone_before': { en: 'Balance of the one merged away, now', bn: 'যেটা মেলানো হবে, তার ব্যালান্স এখন' },
    'corr.fu.balance_kept_before': { en: 'Balance of the one kept, now', bn: 'যেটা রাখা হবে, তার ব্যালান্স এখন' },
    'corr.fu.balance_kept_after': { en: 'Balance of the one kept, after', bn: 'যেটা রাখা হবে, তার ব্যালান্স পরে' },
    'corr.fu.balance_gone_after': { en: 'Balance of the one merged away, after', bn: 'যেটা মেলানো হবে, তার ব্যালান্স পরে' },
    'corr.fu.retire': { en: 'Move balance and retire', bn: 'ব্যালান্স সরিয়ে বন্ধ করুন' },
    'corr.fu.retire_title': { en: 'Move the balance of {name} and retire it?', bn: '{name}-এর ব্যালান্স সরিয়ে এটা বন্ধ করবেন?' },
    'corr.fu.retire_help': {
        en: 'One journal on the day you pick moves its balance to the head you keep. Every past entry stays where it is. Then it is switched off and points at the kept head. Run the dry run first.',
        bn: 'আপনার বেছে নেওয়া দিনে একটা জার্নাল এর ব্যালান্স রাখা খাতে সরাবে। আগের সব এন্ট্রি যেমন আছে তেমনই থাকবে। তারপর এটা বন্ধ হয়ে রাখা খাতের দিকে নির্দেশ করবে। আগে ড্রাই রান চালান।',
    },
    'corr.fu.keep_head': { en: 'Move the balance to', bn: 'ব্যালান্স সরবে এখানে' },
    'corr.fu.move_on': { en: 'Move on', bn: 'কোন তারিখে সরবে' },
    'corr.fu.moves_on': { en: 'The journal is dated {date}.', bn: 'জার্নালের তারিখ {date}।' },
    'corr.fu.balance_from_before': { en: 'This head now', bn: 'এই খাত এখন' },
    'corr.fu.balance_to_before': { en: 'The kept head now', bn: 'রাখা খাত এখন' },
    'corr.fu.balance_from_after': { en: 'This head after', bn: 'এই খাত পরে' },
    'corr.fu.balance_to_after': { en: 'The kept head after', bn: 'রাখা খাত পরে' },
} satisfies Record<string, Entry>;
