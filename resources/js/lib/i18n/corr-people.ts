import type { Entry } from './entry';

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): putting people and asset money
 * right. Expense claims (Cancel approval, Reverse payment), payroll
 * adjustments (Cancel), staff loans (Cancel before the first instalment) and
 * fixed assets on the books (Reverse, Dispose). Button words are the fixed
 * ones of FINAL-PLAN section 2. Latin digits; amounts and dates never
 * translate.
 */
export const corrPeople = {
    // ── Shared ──────────────────────────────────────────────────────────
    'corr.people.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'corr.people.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'corr.people.dispose': { en: 'Dispose', bn: 'বিদায় করুন' },
    'corr.people.stays': {
        en: '{action} {number}? The record stays, marked {state}, with your reason.',
        bn: '{number} {action} করবেন? রেকর্ডটি থাকবে, {state} চিহ্নসহ, আপনার কারণসহ।',
    },
    'corr.people.state_cancelled': { en: 'Cancelled', bn: 'বাতিল' },
    'corr.people.state_reversed': { en: 'Reversed', bn: 'রিভার্স' },
    'corr.people.state_disposed': { en: 'Disposed', bn: 'বিদায় করা' },
    'corr.people.lands': { en: 'Date:', bn: 'তারিখ:' },
    'corr.people.cancelled_on': { en: 'Cancelled on {date}:', bn: '{date} তারিখে বাতিল:' },
    'corr.people.reversed_on': { en: 'Reversed on {date}:', bn: '{date} তারিখে রিভার্স:' },

    // ── Expense claims ──────────────────────────────────────────────────
    'corr.people.claim.cancel_approval': { en: 'Cancel approval', bn: 'অনুমোদন বাতিল' },
    'corr.people.claim.cancel_title': { en: 'Cancel the approval of {number}?', bn: '{number}-এর অনুমোদন বাতিল করবেন?' },
    'corr.people.claim.cancel_explain': {
        en: 'The expense booked at approval is reversed and the claim goes back to the employee as sent back, to fix and send again. Its number and spends stay.',
        bn: 'অনুমোদনের সময় খরচে যা লেখা হয়েছিল তা রিভার্স হবে, আর দাবিটি কর্মীর কাছে সংশোধনের জন্য ফেরত যাবে, ঠিক করে আবার পাঠাতে। নম্বর ও খরচগুলো থাকবে।',
    },
    'corr.people.claim.reverse_payment': { en: 'Reverse payment', bn: 'পেমেন্ট রিভার্স' },
    'corr.people.claim.reverse_title': { en: 'Reverse the payment of {number}?', bn: '{number}-এর পেমেন্ট রিভার্স করবেন?' },
    'corr.people.claim.reverse_explain': {
        en: 'The payment is undone (the money goes back into its account, or the salary line is dropped) and the claim waits to be paid again. The approval stays.',
        bn: 'পেমেন্টটি ফেরত হবে (টাকা তার অ্যাকাউন্টে ফিরবে, অথবা বেতনের লাইন বাদ যাবে) এবং দাবিটি আবার পরিশোধের অপেক্ষায় থাকবে। অনুমোদন থাকবে।',
    },

    // ── Payroll adjustments ─────────────────────────────────────────────
    'corr.people.adjustment.cancel_title': { en: 'Cancel adjustment #{id}?', bn: 'সমন্বয় #{id} বাতিল করবেন?' },
    'corr.people.adjustment.cancel_explain': {
        en: 'In a month that still takes inputs the line is dropped from pay. In a month already closed it stays as paid and an opposite line goes on the next open month.',
        bn: 'যে মাস এখনও ইনপুট নেয় সেখানে লাইনটি বেতন থেকে বাদ যাবে। যে মাস বন্ধ হয়ে গেছে সেখানে তা পরিশোধিত হিসেবে থাকবে, আর পরের খোলা মাসে উল্টো একটি লাইন যাবে।',
    },
    'corr.people.adjustment.cancelled': { en: 'Cancelled', bn: 'বাতিল' },
    'corr.people.adjustment.offset': { en: 'Opposite line: {line}', bn: 'উল্টো লাইন: {line}' },

    // ── Staff loans ─────────────────────────────────────────────────────
    'corr.people.loan.cancel': { en: 'Cancel loan', bn: 'ঋণ বাতিল' },
    'corr.people.loan.cancel_title': { en: 'Cancel {number}?', bn: '{number} বাতিল করবেন?' },
    'corr.people.loan.cancel_explain': {
        en: 'The payout is reversed into the account it left, the instalments are cancelled and nothing is taken from salary. For a different amount, request a new loan.',
        bn: 'পেমেন্টটি যে অ্যাকাউন্ট থেকে গিয়েছিল সেখানে রিভার্স হবে, কিস্তিগুলো বাতিল হবে এবং বেতন থেকে কিছু কাটা হবে না। অন্য পরিমাণের জন্য নতুন ঋণের অনুরোধ করুন।',
    },

    // ── Fixed assets ────────────────────────────────────────────────────
    'corr.people.asset.reversed': { en: 'Reversed', bn: 'রিভার্স' },
    'corr.people.asset.disposed': { en: 'Disposed', bn: 'বিদায় করা' },
    'corr.people.asset.dispose_title': { en: 'Dispose of {number}?', bn: '{number} বিদায় করবেন?' },
    'corr.people.asset.dispose_explain': {
        en: 'It left the business. Its cost and the depreciation written off come off the books, with the money received and the gain or loss, and it is never depreciated again.',
        bn: 'এটি ব্যবসা থেকে চলে গেছে। এর খরচ ও লেখা অবচয় হিসাব থেকে বাদ যাবে, পাওয়া টাকা ও লাভ বা ক্ষতিসহ, এবং আর কখনও অবচয় হবে না।',
    },
    'corr.people.asset.disposed_on': { en: 'Date it left', bn: 'যেদিন চলে গেছে' },
    'corr.people.asset.method': { en: 'How it left', bn: 'কীভাবে চলে গেছে' },
    'corr.people.asset.method_sold': { en: 'Sold', bn: 'বিক্রি' },
    'corr.people.asset.method_scrapped': { en: 'Scrapped', bn: 'ফেলে দেওয়া' },
    'corr.people.asset.method_donated': { en: 'Donated', bn: 'দান' },
    'corr.people.asset.method_lost': { en: 'Lost or stolen', bn: 'হারানো বা চুরি' },
    'corr.people.asset.proceeds': { en: 'Money received', bn: 'পাওয়া টাকা' },
    'corr.people.asset.money_account': { en: 'Into which account', bn: 'কোন অ্যাকাউন্টে' },
    'corr.people.asset.pick_account': { en: 'Pick an account', bn: 'অ্যাকাউন্ট বেছে নিন' },
    'corr.people.asset.book_value': { en: 'Book value: {amount}', bn: 'বইয়ের মূল্য: {amount}' },
    'corr.people.asset.gain': { en: 'Gain of {amount}. Book it to:', bn: '{amount} লাভ। কোথায় লিখবেন:' },
    'corr.people.asset.loss': { en: 'Loss of {amount}. Book it to:', bn: '{amount} ক্ষতি। কোথায় লিখবেন:' },
    'corr.people.asset.pick_head': { en: 'Pick a head', bn: 'একটি খাত বেছে নিন' },
    'corr.people.asset.no_gain_loss': { en: 'No gain and no loss.', bn: 'লাভও নেই, ক্ষতিও নেই।' },
    'corr.people.asset.reverse_hint': { en: 'Entered by mistake', bn: 'ভুল করে লেখা' },
} satisfies Record<string, Entry>;
