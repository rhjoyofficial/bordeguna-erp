import type { Entry } from './entry';

/**
 * Purchases > Bills (ruling P5, 19 Oct 2026): one bill across several
 * deliveries and orders of one supplier, service lines with no order,
 * carriage through landed cost, the likely-duplicate question, and the
 * supplier paying back what they owe us. Numbers, dates and money never
 * translate.
 */
export const billFlex = {
    'bill_flex.deliveries': { en: 'Deliveries on this bill', bn: 'এই বিলের ডেলিভারি' },
    'bill_flex.add_deliveries': { en: 'Add deliveries', bn: 'ডেলিভারি যোগ করুন' },
    'bill_flex.pick_deliveries_hint': {
        en: 'Deliveries from {supplier} still to bill, from any of their orders. Tick the ones this bill covers.',
        bn: '{supplier}-এর যেসব ডেলিভারির বিল এখনও হয়নি, যেকোনো অর্ডারের। এই বিলে যেগুলো আছে সেগুলোতে টিক দিন।',
    },
    'bill_flex.no_deliveries': { en: 'No delivery from this supplier is waiting to be billed.', bn: 'এই সরবরাহকারীর কোনো ডেলিভারির বিল বাকি নেই।' },
    'bill_flex.delivery': { en: 'Delivery', bn: 'ডেলিভারি' },
    'bill_flex.services': { en: 'Services and other costs (no purchase order)', bn: 'সেবা ও অন্যান্য খরচ (ক্রয় আদেশ ছাড়া)' },
    'bill_flex.services_hint': {
        en: 'For example electricity, repairs or rent on this bill. Each goes to the expense head you pick. A bill with such a line is marked as a non-PO bill.',
        bn: 'যেমন এই বিলে বিদ্যুৎ, মেরামত বা ভাড়া। প্রতিটি আপনার বেছে নেওয়া খরচের খাতে যাবে। এমন লাইন থাকলে বিলটি ক্রয় আদেশ ছাড়া বিল হিসেবে চিহ্নিত হয়।',
    },
    'bill_flex.add_service': { en: 'Add service line', bn: 'সেবার লাইন যোগ করুন' },
    'bill_flex.expense_head': { en: 'Expense head', bn: 'খরচের খাত' },
    'bill_flex.description': { en: 'What it was for', bn: 'কিসের জন্য' },
    'bill_flex.amount': { en: 'Amount (Tk)', bn: 'টাকার অঙ্ক' },
    'bill_flex.carriage': { en: 'Carriage and handling', bn: 'পরিবহন ও হ্যান্ডলিং' },
    'bill_flex.carriage_hint': {
        en: 'Goes to Landed costs on the delivery you pick, owed to this supplier. It is not added to this bill total, so it is never owed twice.',
        bn: 'আপনার বেছে নেওয়া ডেলিভারির ল্যান্ডেড কস্টে যাবে, এই সরবরাহকারীর পাওনা হিসেবে। এটি এই বিলের মোটে যোগ হয় না, তাই কখনো দুবার পাওনা হয় না।',
    },
    'bill_flex.add_carriage': { en: 'Add carriage', bn: 'পরিবহন খরচ যোগ করুন' },
    'bill_flex.cost_type': { en: 'Cost for', bn: 'খরচের ধরন' },
    'bill_flex.spread': { en: 'Spread by', bn: 'ভাগ হবে' },
    'bill_flex.spread_value': { en: 'Value', bn: 'মূল্য' },
    'bill_flex.spread_quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'bill_flex.spread_weight': { en: 'Weight', bn: 'ওজন' },
    'bill_flex.remove': { en: 'Remove', bn: 'বাদ দিন' },
    'bill_flex.add': { en: 'Add', bn: 'যোগ করুন' },
    'bill_flex.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'bill_flex.duplicate_title': { en: 'This bill may already be recorded', bn: 'এই বিল হয়তো আগেই লেখা হয়েছে' },
    'bill_flex.duplicate_reason': { en: 'Why is this a different bill?', bn: 'এটি কেন আলাদা বিল?' },
    'bill_flex.record_anyway': { en: 'Record anyway', bn: 'তবুও লিখুন' },
    'bill_flex.for_supplier': { en: 'Bill for a supplier', bn: 'সরবরাহকারীর বিল' },
    'bill_flex.for_supplier_hint': {
        en: 'Several deliveries or orders on one bill, or a service bill with no order.',
        bn: 'এক বিলে কয়েকটি ডেলিভারি বা অর্ডার, বা অর্ডার ছাড়া সেবার বিল।',
    },
    'bill_flex.supplier': { en: 'Supplier', bn: 'সরবরাহকারী' },
    'bill_flex.open': { en: 'Open the bill', bn: 'বিল খুলুন' },
    'bill_flex.non_po': { en: 'Non-PO bill', bn: 'ক্রয় আদেশ ছাড়া বিল' },

    // The supplier pays back what they owe us on Accounts Payable.
    'refund_credit.title': { en: 'They owe us back', bn: 'তাদের কাছে আমাদের ফেরত পাওনা' },
    'refund_credit.explain': {
        en: '{supplier} owes us {amount} on their account, for example after a voucher paid them more than they billed. Record the money when they pay it back. Advances are refunded above, as advances.',
        bn: '{supplier}-এর হিসাবে আমাদের {amount} পাওনা, যেমন ভাউচারে বিলের চেয়ে বেশি দেওয়ার পর। তারা ফেরত দিলে টাকাটা লিখুন। অগ্রিম উপরে অগ্রিম হিসেবেই ফেরত নেওয়া হয়।',
    },
    'refund_credit.none': { en: 'Nothing is owed back on their account.', bn: 'তাদের হিসাবে কিছু ফেরত পাওনা নেই।' },
    'refund_credit.record': { en: 'Record refund', bn: 'ফেরত লিখুন' },
    'refund_credit.received_into': { en: 'Received into', bn: 'যে অ্যাকাউন্টে এসেছে' },
    'reverse.supplier_refund.title': { en: 'Reverse this refund', bn: 'এই ফেরত রিভার্স করুন' },
    'reverse.supplier_refund.explain': {
        en: 'A mirror journal undoes it. The supplier owes us this amount again. Nothing is deleted.',
        bn: 'একটি উল্টো জার্নাল এটি বাতিল করবে। সরবরাহকারীর কাছে এই টাকা আবার পাওনা হবে। কিছুই মুছে ফেলা হয় না।',
    },
} satisfies Record<string, Entry>;
