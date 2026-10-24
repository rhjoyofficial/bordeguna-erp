import type { Entry } from './entry';

/**
 * Joy's initial plan, stock slices (24 Oct 2026): B6 loss reasons with their
 * own heads, B7 the Not for sale (quarantine) warehouse with Move back to
 * stock, and B8 the batch every production input is taken from.
 */
export const ipStock = {
    // ── B6: loss reasons ─────────────────────────────────────────────────
    'ips.loss.head': { en: 'The loss goes to {head}.', bn: 'ক্ষতি যাবে {head} খাতে।' },
    'ips.loss.q_spoiled': { en: 'How many spoiled', bn: 'কতগুলো নষ্ট (পচে) গেছে' },
    'ips.loss.q_damaged_return': { en: 'How many came back damaged', bn: 'কতগুলো ভাঙা/নষ্ট অবস্থায় ফেরত এসেছে' },
    'ips.loss.report_title': { en: 'Losses by reason', bn: 'কারণ অনুযায়ী ক্ষতি' },
    'ips.loss.report_hint': {
        en: "Approved stock corrections in this period, at each batch's own cost. A minus amount is stock that turned up.",
        bn: 'এই সময়ের অনুমোদিত স্টক সংশোধন, প্রতিটি ব্যাচের নিজের দামে। মাইনাস মানে স্টক পাওয়া গেছে।',
    },
    'ips.loss.from': { en: 'From', bn: 'থেকে' },
    'ips.loss.to': { en: 'To', bn: 'পর্যন্ত' },
    'ips.loss.col_reason': { en: 'Reason', bn: 'কারণ' },
    'ips.loss.col_head': { en: 'Head', bn: 'খাত' },
    'ips.loss.col_count': { en: 'Corrections', bn: 'সংশোধন' },
    'ips.loss.col_amount': { en: 'Loss', bn: 'ক্ষতি' },
    'ips.loss.total': { en: 'Total', bn: 'মোট' },
    'ips.loss.none': { en: 'No approved stock corrections in this period.', bn: 'এই সময়ে কোনো অনুমোদিত স্টক সংশোধন নেই।' },

    // ── B7: Not for sale (quarantine) ──────────────────────────────────────
    'ips.q.badge': { en: 'Not for sale', bn: 'বিক্রির জন্য নয়' },
    'ips.q.write_off': { en: 'Write off', bn: 'বাদ দিন' },
    'ips.q.flag': { en: 'Not for sale (damaged goods, quarantine)', bn: 'বিক্রির জন্য নয় (নষ্ট মাল, আলাদা রাখা)' },
    'ips.q.flag_hint': {
        en: 'Nothing is sold, used in production, issued to staff or moved on from it. Reusable goods go back with Move back to stock; the rest is written off as Damaged returns.',
        bn: 'এখান থেকে কিছু বিক্রি, উৎপাদনে ব্যবহার, স্টাফকে দেওয়া বা অন্য গুদামে পাঠানো যাবে না। ব্যবহারযোগ্য মাল "স্টকে ফেরত দিন" দিয়ে ফেরত যাবে; বাকিটা "ফেরত আসা নষ্ট মাল" হিসেবে বাদ যাবে।',
    },
    'ips.back.button': { en: 'Move back to stock', bn: 'স্টকে ফেরত দিন' },
    'ips.back.title': { en: 'Move {product} back to stock', bn: '{product} স্টকে ফেরত দিন' },
    'ips.back.date': { en: 'Date', bn: 'তারিখ' },
    'ips.back.batch': { en: 'Batch', bn: 'ব্যাচ' },
    'ips.back.choose_batch': { en: 'Choose the batch', bn: 'ব্যাচ বেছে নিন' },
    'ips.back.quantity': { en: 'How many go back', bn: 'কতগুলো ফেরত যাবে' },
    'ips.back.to': { en: 'Back to warehouse', bn: 'কোন গুদামে ফেরত যাবে' },
    'ips.back.why': { en: 'Why it can be sold again', bn: 'কেন আবার বিক্রি করা যাবে' },
    'ips.back.why_hint': {
        en: 'Same batch, same cost and expiry. Nothing posts to the books: it is still stock.',
        bn: 'একই ব্যাচ, একই দাম ও মেয়াদ। হিসাবে কিছু পোস্ট হয় না: এটা এখনো স্টক।',
    },
    'ips.back.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'ips.back.submit': { en: 'Move back', bn: 'ফেরত দিন' },
    'ips.ret.col_quarantine': { en: 'Damaged, to quarantine', bn: 'নষ্ট, আলাদা গুদামে' },
    'ips.ret.hint': {
        en: 'Damaged, to quarantine: the goods stay stock at the cost they left at, in the Not for sale warehouse, until they are moved back to stock or written off as Damaged returns.',
        bn: 'নষ্ট, আলাদা গুদামে: মাল যে দামে বেরিয়েছিল সেই দামে স্টক হিসেবেই "বিক্রির জন্য নয়" গুদামে থাকবে, যতক্ষণ না স্টকে ফেরত যায় বা "ফেরত আসা নষ্ট মাল" হিসেবে বাদ যায়।',
    },
    'ips.ret.warehouse': { en: 'Quarantine warehouse', bn: 'আলাদা রাখার গুদাম' },
    'ips.ret.choose': { en: 'Choose the warehouse', bn: 'গুদাম বেছে নিন' },

    // ── B8: production run batch choice ───────────────────────────────────
    'ips.run.inputs_hint': {
        en: 'Pick the batch for every input, from this warehouse. The finished batch costs exactly what you pick. Stock in another warehouse is not offered: transfer it here first.',
        bn: 'প্রতিটি উপকরণের ব্যাচ এই গুদাম থেকে বেছে নিন। তৈরি ব্যাচের খরচ হবে ঠিক যা বেছেছেন তার দাম। অন্য গুদামের স্টক এখানে আসে না: আগে এখানে ট্রান্সফার করুন।',
    },
    'ips.run.batches_hint': {
        en: 'Expired and on-hold batches are shown but cannot be picked.',
        bn: 'মেয়াদোত্তীর্ণ ও আটকে রাখা ব্যাচ দেখা যায়, কিন্তু বাছা যায় না।',
    },
    'ips.run.no_batches': {
        en: 'No batch of this product in this warehouse. Transfer it here first.',
        bn: 'এই গুদামে এই পণ্যের কোনো ব্যাচ নেই। আগে এখানে ট্রান্সফার করুন।',
    },
    'ips.run.batch': { en: 'Batch', bn: 'ব্যাচ' },
    'ips.run.choose_batch': { en: 'Choose a batch', bn: 'ব্যাচ বেছে নিন' },
    'ips.run.quantity': { en: 'How much from this batch', bn: 'এই ব্যাচ থেকে কতটুকু' },
    'ips.run.remove_batch': { en: 'Remove', bn: 'সরান' },
    'ips.run.pick_batch': { en: 'Pick the batch', bn: 'ব্যাচ বাছুন' },
    'ips.run.add_batch': { en: 'Add another batch', bn: 'আরেকটি ব্যাচ যোগ করুন' },
    'ips.run.from_batches': { en: 'From batch {batches}', bn: 'ব্যাচ {batches} থেকে' },
    'ips.run.picked': { en: 'Picked {picked} of {needed} {unit}', bn: '{needed} {unit} এর মধ্যে {picked} বাছা হয়েছে' },
} satisfies Record<string, Entry>;
