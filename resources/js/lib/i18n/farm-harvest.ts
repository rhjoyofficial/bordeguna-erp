import type { Entry } from './entry';

/**
 * ⭐ Farm harvest (24 Oct 2026): Inventory > Production > Farm harvest. The
 * season's cost on a Crops in Progress head becomes paddy stock, or a loss
 * when the crop fails (with the Owner's approval).
 */
export const farmHarvest = {
    'farm.nav': { en: 'Farm harvest', bn: 'ফসল তোলা' },
    'farm.title': { en: 'Farm harvest', bn: 'ফসল তোলা' },
    'farm.explain': {
        en: "What was spent growing a crop waits on a Crops in Progress head. At harvest it becomes the cost of the paddy in stock; if the crop fails, it is written off as a loss with the Owner's approval.",
        bn: 'ফসল ফলাতে যা খরচ হয়েছে তা Crops in Progress খাতে জমা থাকে। ফসল তোলার দিন সেটা স্টকের ধানের খরচ হয়ে যায়; ফসল নষ্ট হলে মালিকের অনুমোদনে ক্ষতি হিসেবে বাদ দেওয়া হয়।',
    },
    'farm.heads': { en: 'Crops in progress', bn: 'চলমান ফসল' },
    'farm.heads_none': {
        en: 'No Crops in Progress head yet. Open one in Accounts > Ledgers with code {code} (or under it).',
        bn: 'এখনো কোনো Crops in Progress খাত নেই। Accounts > Ledgers-এ {code} কোড দিয়ে (বা তার নিচে) একটি খুলুন।',
    },
    'farm.holds': { en: 'Holds Tk {amount}', bn: 'জমা আছে {amount} টাকা' },

    'farm.record': { en: 'Record harvest', bn: 'ফসল তোলা লিখুন' },
    'farm.record_title': { en: 'Record a harvest', bn: 'ফসল তোলা লিখুন' },
    'farm.record_explain': {
        en: 'The amount comes off the head and goes into stock as the cost of what was harvested.',
        bn: 'টাকাটা খাত থেকে কমে স্টকে যাবে, যা তোলা হয়েছে তার খরচ হিসেবে।',
    },
    'farm.date': { en: 'Harvest date', bn: 'ফসল তোলার তারিখ' },
    'farm.head': { en: 'Crops in Progress head', bn: 'Crops in Progress খাত' },
    'farm.head_pick': { en: 'Choose the head', bn: 'খাত বাছুন' },
    'farm.warehouse': { en: 'Warehouse', bn: 'গুদাম' },
    'farm.product': { en: 'What was harvested', bn: 'কী তোলা হলো' },
    'farm.product_pick': { en: 'Choose the product', bn: 'পণ্য বাছুন' },
    'farm.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'farm.unit': { en: 'Unit', bn: 'একক' },
    'farm.batch': { en: 'Batch no. (optional)', bn: 'ব্যাচ নম্বর (ঐচ্ছিক)' },
    'farm.batch_hint': { en: 'Left blank, the ERP numbers it.', bn: 'খালি রাখলে ERP নিজেই নম্বর দেবে।' },
    'farm.expiry': { en: 'Expiry date', bn: 'মেয়াদ শেষের তারিখ' },
    'farm.amount': { en: 'Amount to move (Tk)', bn: 'কত টাকা যাবে (টাকা)' },
    'farm.amount_hint': {
        en: 'Left blank, everything the head holds on that day. Type less for a part harvest.',
        bn: 'খালি রাখলে সেই দিন খাতে যা আছে সবটা। অল্প অংশ তুললে কম লিখুন।',
    },
    'farm.byproducts': { en: 'By-products (straw, husk)', bn: 'উপজাত (খড়, তুষ)' },
    'farm.byproducts_hint': {
        en: 'Each takes the share of the cost you give it; the main product takes the rest.',
        bn: 'প্রতিটি যতটুকু খরচের ভাগ দেবেন ততটুকু নেবে; বাকিটা মূল পণ্যের।',
    },
    'farm.byproduct_add': { en: 'Add a by-product', bn: 'উপজাত যোগ করুন' },
    'farm.byproduct_remove': { en: 'Remove', bn: 'বাদ দিন' },
    'farm.share': { en: 'Share of cost (Tk)', bn: 'খরচের ভাগ (টাকা)' },
    'farm.note': { en: 'Note', bn: 'নোট' },
    'farm.save': { en: 'Save harvest', bn: 'সংরক্ষণ করুন' },
    'farm.go_back': { en: 'Go back', bn: 'ফিরে যান' },

    'farm.harvests': { en: 'Harvests', bn: 'ফসল তোলা' },
    'farm.harvests_none': { en: 'No harvest recorded yet.', bn: 'এখনো কোনো ফসল তোলা লেখা হয়নি।' },
    'farm.col_date': { en: 'Date', bn: 'তারিখ' },
    'farm.col_head': { en: 'Head', bn: 'খাত' },
    'farm.col_came_in': { en: 'Came into stock', bn: 'স্টকে এলো' },
    'farm.col_amount': { en: 'Amount', bn: 'টাকা' },
    'farm.col_status': { en: 'Status', bn: 'অবস্থা' },
    'farm.each': { en: 'Tk {cost} each', bn: 'প্রতিটি {cost} টাকা' },
    'farm.posted': { en: 'Posted', bn: 'পোস্ট হয়েছে' },
    'farm.reversed': { en: 'Reversed', bn: 'রিভার্স হয়েছে' },
    'farm.reversed_on': { en: 'Reversed on {date} by {by}.', bn: '{date} তারিখে {by} রিভার্স করেছেন।' },
    'farm.reverse': { en: 'Reverse', bn: 'রিভার্স' },

    'farm.write_off': { en: 'Crop failure write-off', bn: 'ফসল নষ্টের ক্ষতি' },
    'farm.write_off_title': { en: 'Write off a failed crop', bn: 'নষ্ট ফসলের খরচ বাদ দিন' },
    'farm.write_off_explain': {
        en: 'The amount comes off the head as a loss once the Owner approves it. Nothing posts before that.',
        bn: 'মালিক অনুমোদন দিলে টাকাটা খাত থেকে ক্ষতি হিসেবে বাদ যাবে। তার আগে কিছুই পোস্ট হবে না।',
    },
    'farm.write_off_date': { en: 'Date of the loss', bn: 'ক্ষতির তারিখ' },
    'farm.loss_head': { en: 'Loss head', bn: 'ক্ষতির খাত' },
    'farm.loss_head_pick': { en: 'Choose the loss head', bn: 'ক্ষতির খাত বাছুন' },
    'farm.write_off_amount': { en: 'Amount lost (Tk)', bn: 'ক্ষতির টাকা' },
    'farm.why': { en: 'What happened', bn: 'কী হয়েছিল' },
    'farm.send': { en: 'Send for approval', bn: 'অনুমোদনের জন্য পাঠান' },
    'farm.write_offs': { en: 'Crop failure write-offs', bn: 'ফসল নষ্টের ক্ষতি' },
    'farm.write_offs_none': { en: 'No write-off raised yet.', bn: 'এখনো কোনো ক্ষতি লেখা হয়নি।' },
    'farm.status.waiting': { en: 'Waiting for the Owner', bn: 'মালিকের অপেক্ষায়' },
    'farm.status.approved': { en: 'Approved', bn: 'অনুমোদিত' },
    'farm.status.rejected': { en: 'Rejected', bn: 'বাতিল' },
    'farm.status.reversed': { en: 'Reversed', bn: 'রিভার্স হয়েছে' },
    'farm.decide': { en: 'Approve or reject', bn: 'অনুমোদন বা বাতিল' },
    'farm.decide_title': { en: 'Decide {number}', bn: '{number} সিদ্ধান্ত দিন' },
    'farm.decide_note': { en: 'Note', bn: 'নোট' },
    'farm.self_note': {
        en: 'You raised this yourself. Approving is allowed; write a short note, it is kept in the approval history.',
        bn: 'এটা আপনি নিজেই লিখেছেন। অনুমোদন করা যায়; ছোট একটা নোট লিখুন, অনুমোদনের ইতিহাসে থাকবে।',
    },
    'farm.approve': { en: 'Approve', bn: 'অনুমোদন' },
    'farm.reject': { en: 'Reject', bn: 'বাতিল' },
} satisfies Record<string, Entry>;
