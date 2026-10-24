import type { Entry } from './entry';

/**
 * Rule #6 (Joy, 14 Oct 2026): a quote priced at the retail list because the
 * buyer's group has no list says so on the quote list and on the print. Only
 * the sentences that rule added; the quotation screens around them are still
 * English by the 31 Aug decision (staff-first Bangla).
 */
export const quotes = {
    'quotes.retail_fallback_badge': { en: 'Retail price used', bn: 'খুচরা দাম ধরা হয়েছে' },
    'quotes.retail_fallback_hint': {
        en: "At least one line is priced at the retail list: the buyer's group has no price list.",
        bn: 'অন্তত একটি লাইনে খুচরা তালিকার দাম ধরা হয়েছে: এই ক্রেতার দলের কোনো দামের তালিকা নেই।',
    },
    'quotes.retail_fallback_line': { en: 'Retail price (no price list for this buyer)', bn: 'খুচরা দাম (এই ক্রেতার জন্য দামের তালিকা নেই)' },
    'quotes.retail_fallback_note': {
        en: 'Lines marked "retail price" are quoted at our retail list, because no price list is set for this buyer yet.',
        bn: '"খুচরা দাম" চিহ্নিত লাইনগুলো আমাদের খুচরা তালিকার দামে দেওয়া হয়েছে, কারণ এই ক্রেতার জন্য এখনও কোনো দামের তালিকা ঠিক করা হয়নি।',
    },
    // ⭐ B13 (24 Oct 2026, gap S21): New and Edit in a popup, Delete a draft, Terms, the internal note.
    'quotes.new': { en: 'New quotation', bn: 'নতুন কোটেশন' },
    'quotes.edit': { en: 'Edit', bn: 'এডিট' },
    'quotes.edit_title': { en: 'Edit quotation {number}', bn: 'কোটেশন {number} এডিট' },
    'quotes.form_hint': {
        en: 'Saved as a draft. Nothing is reserved and nothing is posted.',
        bn: 'ড্রাফট হিসেবে সেভ হবে। কিছু আটকে রাখা হয় না, হিসাবে কিছু পোস্ট হয় না।',
    },
    'quotes.edit_sent_hint': {
        en: 'This quote was sent. Saving keeps the sent copy, marked Revised, and saves the change as a new draft (R1).',
        bn: 'এই কোটেশন পাঠানো হয়েছে। সেভ করলে পাঠানো কপিটি "Revised" চিহ্ন নিয়ে থেকে যাবে, আর পরিবর্তনটি নতুন ড্রাফট (R1) হিসেবে সেভ হবে।',
    },
    'quotes.valid_until': { en: 'Valid until', bn: 'মেয়াদ' },
    'quotes.quote_for': { en: 'Quote for', bn: 'কার জন্য' },
    'quotes.a_customer': { en: 'A customer', bn: 'একজন কাস্টমার' },
    'quotes.a_lead': { en: 'A lead', bn: 'একজন লিড' },
    'quotes.customer': { en: 'Customer', bn: 'কাস্টমার' },
    'quotes.lead': { en: 'Lead', bn: 'লিড' },
    'quotes.terms': { en: 'Terms (printed for the customer)', bn: 'শর্তাবলি (কাস্টমারের কপিতে ছাপা হবে)' },
    'quotes.terms_placeholder': { en: 'Delivery, payment, validity...', bn: 'ডেলিভারি, পেমেন্ট, মেয়াদ...' },
    'quotes.internal_note': { en: 'Internal note', bn: 'ভেতরের নোট' },
    'quotes.internal_note_hint': { en: 'Never printed on the customer copy.', bn: 'কাস্টমারের কপিতে কখনো ছাপা হয় না।' },
    'quotes.save_draft': { en: 'Save draft', bn: 'ড্রাফট সেভ করুন' },
    'quotes.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'quotes.delete': { en: 'Delete', bn: 'ডিলিট' },
    'quotes.delete_title': { en: 'Delete draft {number}?', bn: 'ড্রাফট {number} ডিলিট করবেন?' },
    'quotes.delete_text': { en: 'Nobody outside has seen a draft, so it can go.', bn: 'ড্রাফট বাইরের কেউ দেখেনি, তাই এটি মুছে ফেলা যায়।' },
    'quotes.revision_of': { en: 'Revision of {number}', bn: '{number}-এর সংশোধিত রূপ' },
} satisfies Record<string, Entry>;
