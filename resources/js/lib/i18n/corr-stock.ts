import type { Entry } from './entry';

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): putting stock documents right.
 * Stock adjustments (Edit, Withdraw, Reverse), opening stock (Correct into
 * Opening Balance Equity), transfers (Cancel, Receive part) and production
 * runs (Edit a draft, Correct, Reverse). Button words are the fixed ones of
 * FINAL-PLAN section 2. Latin digits; amounts and dates never translate.
 */
export const corrStock = {
    // ── Shared ──────────────────────────────────────────────────────────
    'corr.stock.edit': { en: 'Edit', bn: 'এডিট' },
    'corr.stock.withdraw': { en: 'Withdraw', bn: 'ফিরিয়ে নিন' },
    'corr.stock.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'corr.stock.correct': { en: 'Correct', bn: 'সংশোধন' },
    'corr.stock.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'corr.stock.go_back': { en: 'Go back', bn: 'ফিরে যান' },
    'corr.stock.preview_correct': { en: 'Preview and correct', bn: 'দেখে নিয়ে সংশোধন করুন' },
    'corr.stock.reason': { en: 'Reason', bn: 'কারণ' },
    'corr.stock.reason_pick': { en: 'Pick one (optional)', bn: 'একটি বেছে নিন (ঐচ্ছিক)' },
    'corr.stock.reason_words': { en: 'In your words', bn: 'নিজের ভাষায় লিখুন' },
    'corr.stock.reason_min': { en: 'Say why in at least 5 characters.', bn: 'কমপক্ষে 5 অক্ষরে কারণ লিখুন।' },
    'corr.stock.note': { en: 'Note', bn: 'নোট' },
    'corr.stock.batch': { en: 'Batch', bn: 'ব্যাচ' },
    'corr.stock.correction_of': { en: 'Correction of {number}', bn: '{number}-এর সংশোধন' },

    // ── Stock adjustments ───────────────────────────────────────────────
    'corr.stock.adjust.title': { en: 'Stock corrections', bn: 'স্টক সংশোধন' },
    'corr.stock.adjust.list_link': { en: 'Corrections list', bn: 'সংশোধনের তালিকা' },
    'corr.stock.adjust.explain': {
        en: 'Damage, loss, found stock and count differences. A waiting one can be edited or withdrawn by the person who raised it; an approved one is reversed back into the same batches.',
        bn: 'নষ্ট, হারানো, পাওয়া স্টক আর গণনার পার্থক্য। অপেক্ষায় থাকা সংশোধন যিনি তুলেছেন তিনি এডিট বা ফিরিয়ে নিতে পারবেন; অনুমোদিত সংশোধন একই ব্যাচে রিভার্স হয়।',
    },
    'corr.stock.adjust.back_to_stock': { en: 'Back to stock', bn: 'স্টকে ফিরে যান' },
    'corr.stock.adjust.all': { en: 'All', bn: 'সব' },
    'corr.stock.adjust.clear': { en: 'Clear', bn: 'মুছুন' },
    'corr.stock.adjust.none': { en: 'No stock corrections here.', bn: 'এখানে কোনো স্টক সংশোধন নেই।' },
    'corr.stock.adjust.status.waiting': { en: 'Waiting', bn: 'অপেক্ষায়' },
    'corr.stock.adjust.status.withdrawn': { en: 'Withdrawn', bn: 'ফিরিয়ে নেওয়া' },
    'corr.stock.adjust.status.approved': { en: 'Approved', bn: 'অনুমোদিত' },
    'corr.stock.adjust.status.rejected': { en: 'Rejected', bn: 'বাতিল হয়েছে' },
    'corr.stock.adjust.status.reversed': { en: 'Reversed', bn: 'রিভার্স হয়েছে' },
    'corr.stock.adjust.col_date': { en: 'Date', bn: 'তারিখ' },
    'corr.stock.adjust.col_product': { en: 'Product', bn: 'পণ্য' },
    'corr.stock.adjust.col_what': { en: 'What happened', bn: 'কী হয়েছিল' },
    'corr.stock.adjust.col_qty': { en: 'Quantity', bn: 'পরিমাণ' },
    'corr.stock.adjust.col_value': { en: 'Value', bn: 'মূল্য' },
    'corr.stock.adjust.col_status': { en: 'Status', bn: 'অবস্থা' },
    'corr.stock.adjust.when': { en: 'When did it happen?', bn: 'কবে হয়েছিল?' },
    'corr.stock.adjust.what_happened': { en: 'What happened?', bn: 'কী হয়েছিল?' },
    'corr.stock.adjust.how_many': { en: 'How many ({unit})', bn: 'কতগুলো ({unit})' },
    'corr.stock.adjust.cost_each': { en: 'Cost per unit (৳)', bn: 'প্রতি ইউনিটের দাম (৳)' },
    'corr.stock.adjust.why_change': { en: 'Why are you changing it?', bn: 'কেন বদলাচ্ছেন?' },
    'corr.stock.adjust.edit_title': { en: 'Edit {number}', bn: '{number} এডিট করুন' },
    'corr.stock.adjust.edit_waiting': {
        en: 'It is still waiting, so nothing has moved. Approval starts again from the first step with the new figures.',
        bn: 'এটি এখনও অপেক্ষায়, তাই কিছুই সরেনি। নতুন হিসাব নিয়ে অনুমোদন আবার প্রথম ধাপ থেকে শুরু হবে।',
    },
    'corr.stock.adjust.edit_withdrawn': {
        en: 'You withdrew it. Saving sends it for approval again.',
        bn: 'আপনি এটি ফিরিয়ে নিয়েছিলেন। সেভ করলে আবার অনুমোদনের জন্য যাবে।',
    },
    'corr.stock.adjust.save': { en: 'Save', bn: 'সেভ করুন' },
    'corr.stock.adjust.save_send': { en: 'Save and send again', bn: 'সেভ করে আবার পাঠান' },
    'corr.stock.adjust.withdraw_title': { en: 'Withdraw {number}?', bn: '{number} ফিরিয়ে নেবেন?' },
    'corr.stock.adjust.withdraw_explain': {
        en: 'It goes back to you as a draft, with your reason. Nothing has moved, so nothing is undone.',
        bn: 'এটি আপনার কারণসহ খসড়া হিসেবে আপনার কাছে ফিরে আসবে। কিছুই সরেনি, তাই কিছুই উল্টাতে হবে না।',
    },
    'corr.stock.adjust.reversed_on': { en: 'Reversed on {date} by {by}.', bn: '{date} তারিখে {by} রিভার্স করেছেন।' },

    // ── Opening stock ───────────────────────────────────────────────────
    'corr.stock.opening.show_corrections': { en: 'Show corrections', bn: 'সংশোধনগুলো দেখান' },
    'corr.stock.opening.value': { en: 'Value', bn: 'মূল্য' },
    'corr.stock.opening.corrected': { en: 'Corrected, see {number}', bn: 'সংশোধিত, {number} দেখুন' },
    'corr.stock.opening.correct_title': { en: 'Correct {number}', bn: '{number} সংশোধন করুন' },
    'corr.stock.opening.banner': {
        en: 'You are correcting {number} of {warehouse}, dated {date}. Change what was wrong, set a line to 0 to take it out, or add a product the count missed. The difference goes to Opening Balance Equity on {date}, never to Inventory Write-offs. {number} stays, marked Corrected.',
        bn: 'আপনি {warehouse}-এর {number} সংশোধন করছেন, তারিখ {date}। যা ভুল ছিল তা বদলান, কোনো লাইন বাদ দিতে 0 দিন, অথবা গণনায় বাদ পড়া পণ্য যোগ করুন। পার্থক্য {date} তারিখে Opening Balance Equity-তে যাবে, কখনও Inventory Write-offs-এ নয়। {number} থেকে যাবে, সংশোধিত চিহ্নসহ।',
    },
    'corr.stock.opening.col_product': { en: 'Product', bn: 'পণ্য' },
    'corr.stock.opening.col_qty': { en: 'Quantity', bn: 'পরিমাণ' },
    'corr.stock.opening.col_cost': { en: 'Cost per unit', bn: 'প্রতি ইউনিটের দাম' },
    'corr.stock.opening.col_expiry': { en: 'Expiry', bn: 'মেয়াদ' },
    'corr.stock.opening.on_hand': { en: '{quantity} {unit} in the batch now', bn: 'ব্যাচে এখন {quantity} {unit}' },
    'corr.stock.opening.was': { en: 'was {value}', bn: 'আগে ছিল {value}' },
    'corr.stock.opening.choose_product': { en: 'Choose a product', bn: 'একটি পণ্য বেছে নিন' },
    'corr.stock.opening.batch_auto': { en: 'automatic', bn: 'স্বয়ংক্রিয়' },
    'corr.stock.opening.remove_new': { en: 'Remove this new line', bn: 'এই নতুন লাইন সরান' },
    'corr.stock.opening.add_forgotten': { en: 'Add a product the count missed', bn: 'গণনায় বাদ পড়া পণ্য যোগ করুন' },
    'corr.stock.opening.totals': { en: 'Value before: {before}. After: about {after}.', bn: 'আগের মূল্য: {before}। পরে: প্রায় {after}।' },

    // ── Transfers ───────────────────────────────────────────────────────
    'corr.stock.transfer.cancelled': { en: 'Cancelled', bn: 'বাতিল' },
    'corr.stock.transfer.cancel_title': { en: 'Cancel transfer #{id}?', bn: 'ট্রান্সফার #{id} বাতিল করবেন?' },
    'corr.stock.transfer.cancel_explain': {
        en: 'The stock goes back onto the batches it left, on the day it left. The transfer stays, marked Cancelled, with your reason.',
        bn: 'স্টক যে ব্যাচ থেকে বেরিয়েছিল, যেদিন বেরিয়েছিল সেদিনের তারিখে সেখানেই ফিরে যাবে। ট্রান্সফারটি আপনার কারণসহ বাতিল চিহ্নে থেকে যাবে।',
    },
    'corr.stock.transfer.not_all': { en: 'Not all of it arrived?', bn: 'সবটা পৌঁছায়নি?' },
    'corr.stock.transfer.part_title': { en: 'Receive transfer #{id} short', bn: 'ট্রান্সফার #{id} কম গ্রহণ করুন' },
    'corr.stock.transfer.part_explain': {
        en: '{quantity} were sent. What arrived goes into stock; the rest is recorded as damage or loss with your reason, and is approved like any other.',
        bn: '{quantity} পাঠানো হয়েছিল। যা পৌঁছেছে তা স্টকে যাবে; বাকিটা আপনার কারণসহ নষ্ট বা হারানো হিসেবে রেকর্ড হবে, অন্য সংশোধনের মতোই অনুমোদন হবে।',
    },
    'corr.stock.transfer.how_many_arrived': { en: 'How many arrived?', bn: 'কতগুলো পৌঁছেছে?' },
    'corr.stock.transfer.what_happened': { en: 'What happened to the rest?', bn: 'বাকিগুলোর কী হয়েছে?' },
    'corr.stock.transfer.choose_loss': { en: 'Choose what happened', bn: 'কী হয়েছে বেছে নিন' },
    'corr.stock.transfer.part_confirm': { en: 'Receive part', bn: 'আংশিক গ্রহণ' },
    'corr.stock.transfer.short_by': { en: 'Short by {quantity}.', bn: '{quantity} কম এসেছে।' },

    // ── Production runs ─────────────────────────────────────────────────
    'corr.stock.run.editing': {
        en: 'You are editing the draft {number}. Nothing has moved yet; saving rewrites the draft.',
        bn: 'আপনি খসড়া {number} এডিট করছেন। এখনও কিছু সরেনি; সেভ করলে খসড়াটি নতুন করে লেখা হবে।',
    },
    'corr.stock.run.correcting': {
        en: 'You are correcting {number}. It is reversed on its own date (inputs back onto their batches, output out of its batch) and the right run is made, in one step. {number} stays, marked Reversed.',
        bn: 'আপনি {number} সংশোধন করছেন। এটি নিজের তারিখে রিভার্স হবে (কাঁচামাল নিজের ব্যাচে ফিরবে, তৈরি পণ্য ব্যাচ থেকে বেরোবে) এবং সঠিক রানটি তৈরি হবে, এক ধাপে। {number} রিভার্স চিহ্নসহ থেকে যাবে।',
    },
    'corr.stock.run.save_draft': { en: 'Save the draft', bn: 'খসড়া সেভ করুন' },
    'corr.stock.run.reversed_why': { en: 'Reversed on {date}:', bn: '{date} তারিখে রিভার্স হয়েছে:' },
    'corr.stock.run.corrected_by': { en: 'Corrected by {number}', bn: '{number} দিয়ে সংশোধিত' },
} satisfies Record<string, Entry>;
