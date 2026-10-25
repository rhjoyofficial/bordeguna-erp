import type { Entry } from './entry';

/**
 * Joy's answers of 25 Oct 2026 (initial plan, builder IP18): purchase cost
 * type defaults on Finance policy, the cashier float in the books, the staff
 * purchase of a not-for-sale item, and SSL Commerz settlement.
 */
export const ipAnswers = {
    // ── Finance policy > Purchase cost types (answer 3) ─────────────────
    'ipa.cost.title': { en: 'Purchase cost types', bn: 'ক্রয়ের খরচের ধরন' },
    'ipa.cost.intro': {
        en: 'What each extra cost of a purchase does by default: added to the stock value, or an expense under the head named here. A line on a purchase may still switch, with a reason. Documents already saved keep the default they were saved under.',
        bn: 'ক্রয়ের প্রতিটি বাড়তি খরচ স্বাভাবিকভাবে কী হবে: স্টকের মূল্যে যোগ, নাকি এখানে নাম দেওয়া খাতে খরচ। ক্রয়ের কোনো লাইন কারণ দিয়ে তবু বদলাতে পারবে। আগে সংরক্ষিত দলিল আগের নিয়মেই থাকবে।',
    },
    'ipa.cost.type': { en: 'Cost type', bn: 'খরচের ধরন' },
    'ipa.cost.default': { en: 'Default', bn: 'স্বাভাবিক নিয়ম' },
    'ipa.cost.head': { en: 'Expense head', bn: 'খরচের খাত' },
    'ipa.cost.capitalize': { en: 'Add to stock value', bn: 'স্টকের মূল্যে যোগ' },
    'ipa.cost.expense': { en: 'Expense', bn: 'খরচ' },
    'ipa.cost.mapped': { en: 'As mapped: {head}', bn: 'ম্যাপ অনুযায়ী: {head}' },
    'ipa.cost.named_on_line': { en: 'Named on each line', bn: 'প্রতিটি লাইনে নাম দিতে হবে' },
    'ipa.cost.edit': { en: 'Edit', bn: 'সম্পাদনা' },
    'ipa.cost.edit_title': { en: 'Default for {type}', bn: '{type}-এর স্বাভাবিক নিয়ম' },
    'ipa.cost.choose_head': { en: 'Choose a head', bn: 'খাত বাছুন' },
    'ipa.cost.head_hint': {
        en: 'Leave blank to use the mapped head; a type with no mapped head needs one here.',
        bn: 'খালি রাখলে ম্যাপ করা খাত ব্যবহার হবে; যে ধরনের ম্যাপ করা খাত নেই তার জন্য এখানে একটি দিতে হবে।',
    },
    'ipa.cost.save': { en: 'Save', bn: 'সংরক্ষণ' },
    'ipa.cost.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'ipa.cost.nav': { en: 'Purchase cost types', bn: 'ক্রয়ের খরচের ধরন' },

    // ── Cashier float in the books (answer 5) ────────────────────────────
    'ipa.float.from': { en: 'Float from', bn: 'শুরুর টাকা কোথা থেকে' },
    'ipa.float.choose': { en: 'Choose an account', bn: 'একটি অ্যাকাউন্ট বাছুন' },
    'ipa.float.in_till': { en: 'Already in the till (left by the last shift)', bn: 'আগে থেকেই ক্যাশে আছে (আগের শিফট রেখে গেছে)' },
    'ipa.float.from_hint': {
        en: 'Posted as a money transfer into the till today, so the books show the float.',
        bn: 'আজকের তারিখে ক্যাশে টাকা স্থানান্তর হিসেবে পোস্ট হবে, তাই হিসাবে শুরুর টাকা দেখাবে।',
    },
    'ipa.float.hand_over_to': { en: 'Hand over counted cash to', bn: 'গোনা টাকা কাকে বুঝিয়ে দেবেন' },
    'ipa.float.keep_in_till': { en: 'Keep it in the till', bn: 'ক্যাশেই রাখুন' },
    'ipa.float.hand_over_hint': {
        en: 'Optional. Posts a money transfer of the counted cash out of the till today.',
        bn: 'ঐচ্ছিক। গোনা টাকা আজকের তারিখে ক্যাশ থেকে স্থানান্তর হিসেবে পোস্ট হবে।',
    },
    'ipa.float.posted_as': { en: 'Posted as {number} from {account}', bn: '{account} থেকে {number} হিসেবে পোস্ট হয়েছে' },
    'ipa.float.not_posted': {
        en: 'This float was typed before floats were posted: it is not in the books as a transfer.',
        bn: 'এই শুরুর টাকা পোস্ট শুরুর আগে লেখা হয়েছিল: হিসাবে কোনো স্থানান্তর নেই।',
    },
    // ── Staff purchase of a not-for-sale item (answer 6) ─────────────────
    'ipa.custom.button': { en: 'Sell a not-for-sale item', bn: 'বিক্রির জন্য নয় এমন পণ্য বিক্রি' },
    'ipa.custom.title': { en: 'Not-for-sale item at a custom price', bn: 'নিজের ঠিক করা দামে বিক্রির জন্য নয় এমন পণ্য' },
    'ipa.custom.hint': {
        en: 'Only for an item whose Can be sold switch is off. The price and the reason are kept in History. It is then approved and handed over like any staff purchase.',
        bn: 'শুধু যে পণ্যের "বিক্রি করা যায়" বন্ধ তার জন্য। দাম ও কারণ ইতিহাসে থাকবে। তারপর অন্য স্টাফ কেনাকাটার মতোই অনুমোদন ও হস্তান্তর হবে।',
    },
    'ipa.custom.employee': { en: 'Employee', bn: 'কর্মী' },
    'ipa.custom.product': { en: 'Item', bn: 'পণ্য' },
    'ipa.custom.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'ipa.custom.price': { en: 'Price per unit (Tk)', bn: 'প্রতি এককের দাম (টাকা)' },
    'ipa.custom.cost': { en: 'Stock cost {amount}', bn: 'স্টকের খরচ {amount}' },
    'ipa.custom.reason': { en: 'Reason for this price', bn: 'এই দামের কারণ' },
    'ipa.custom.payment': { en: 'Payment', bn: 'পরিশোধ' },
    'ipa.custom.pay_cash': { en: 'Cash', bn: 'নগদ' },
    'ipa.custom.pay_mfs': { en: 'Mobile money', bn: 'মোবাইল ব্যাংকিং' },
    'ipa.custom.pay_bank': { en: 'Bank', bn: 'ব্যাংক' },
    'ipa.custom.pay_salary': { en: 'Salary deduction', bn: 'বেতন থেকে কাটা' },
    'ipa.custom.pay_credit': { en: 'Pay later', bn: 'পরে পরিশোধ' },
    'ipa.custom.choose': { en: 'Choose', bn: 'বাছুন' },
    'ipa.custom.save': { en: 'Write the request', bn: 'আবেদন লিখুন' },
    'ipa.custom.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'ipa.custom.none': { en: 'No stocked item has its Can be sold switch off.', bn: '"বিক্রি করা যায়" বন্ধ এমন কোনো মজুত পণ্য নেই।' },
    'ipa.custom.line_label': { en: 'custom price: {reason}', bn: 'নিজের ঠিক করা দাম: {reason}' },

    'ipa.float.handed_over': { en: 'Handed over to {account} as {number}', bn: '{number} হিসেবে {account}-কে বুঝিয়ে দেওয়া হয়েছে' },

    // ── SSL Commerz settlement and report (answer 9) ─────────────────────
    'ipa.ssl.nav': { en: 'SSL settlements', bn: 'SSL সেটেলমেন্ট' },
    'ipa.ssl.report_nav': { en: 'SSL Commerz', bn: 'এসএসএল কমার্জ' },
    'ipa.ssl.title': { en: 'SSL settlements', bn: 'SSL সেটেলমেন্ট' },
    'ipa.ssl.intro': {
        en: 'Paid-online orders wait in SSL Commerz Clearing. When SSL pays out, record it here: the gross leaves clearing, SSL’s charge goes to Payment Gateway Charge, the net lands in the bank.',
        bn: 'অনলাইনে পরিশোধ করা অর্ডারের টাকা SSL Commerz Clearing-এ থাকে। SSL টাকা দিলে এখানে লিখুন: মোট টাকা ক্লিয়ারিং থেকে বের হয়, SSL-এর চার্জ পেমেন্ট গেটওয়ে চার্জে যায়, বাকি টাকা ব্যাংকে আসে।',
    },
    'ipa.ssl.in_clearing': { en: 'Now in {name}: {amount}', bn: 'এখন {name}-এ আছে: {amount}' },
    'ipa.ssl.no_clearing': {
        en: 'There is no SSL Commerz Clearing account: the chart has no 1146 Receivable from Payment Gateways.',
        bn: 'SSL Commerz Clearing অ্যাকাউন্ট নেই: হিসাবের তালিকায় 1146 নেই।',
    },
    'ipa.ssl.add': { en: 'Record an SSL payout', bn: 'SSL পেমেন্ট লিখুন' },
    'ipa.ssl.date': { en: 'Date SSL paid out', bn: 'SSL যেদিন টাকা দিয়েছে' },
    'ipa.ssl.bank': { en: 'Paid into', bn: 'যে হিসাবে জমা হয়েছে' },
    'ipa.ssl.gross': { en: 'Gross settled (Tk)', bn: 'মোট সেটেল (টাকা)' },
    'ipa.ssl.fee': { en: 'SSL charge (Tk)', bn: 'SSL চার্জ (টাকা)' },
    'ipa.ssl.net': { en: 'Net to bank', bn: 'ব্যাংকে আসবে' },
    'ipa.ssl.reference': { en: 'SSL reference', bn: 'SSL রেফারেন্স' },
    'ipa.ssl.note': { en: 'Note', bn: 'নোট' },
    'ipa.ssl.file': { en: 'SSL statement (optional)', bn: 'SSL স্টেটমেন্ট (ঐচ্ছিক)' },
    'ipa.ssl.choose': { en: 'Choose', bn: 'বাছুন' },
    'ipa.ssl.save': { en: 'Save', bn: 'সংরক্ষণ' },
    'ipa.ssl.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'ipa.ssl.none': { en: 'No SSL payout recorded yet.', bn: 'এখনও কোনো SSL পেমেন্ট লেখা হয়নি।' },
    'ipa.ssl.col_number': { en: 'Settlement', bn: 'সেটেলমেন্ট' },
    'ipa.ssl.col_date': { en: 'Date', bn: 'তারিখ' },
    'ipa.ssl.col_bank': { en: 'Bank', bn: 'ব্যাংক' },
    'ipa.ssl.col_gross': { en: 'Gross', bn: 'মোট' },
    'ipa.ssl.col_fee': { en: 'SSL charge', bn: 'SSL চার্জ' },
    'ipa.ssl.col_net': { en: 'Net', bn: 'নিট' },
    'ipa.ssl.col_reference': { en: 'Reference', bn: 'রেফারেন্স' },
    'ipa.ssl.reversed': { en: 'Reversed', bn: 'বাতিল (রিভার্স)' },
    'ipa.ssl.show_corrections': { en: 'Show reversed', bn: 'বাতিলগুলো দেখান' },
    'ipa.ssl.report_title': { en: 'SSL Commerz', bn: 'এসএসএল কমার্জ' },
    'ipa.ssl.report_intro': {
        en: 'How much SSL took for us, how much it kept, what reached the bank and what it still owes, for the period.',
        bn: 'এই সময়ে SSL আমাদের জন্য কত নিয়েছে, কত রেখেছে, ব্যাংকে কত এসেছে আর এখনও কত পাওনা।',
    },
    'ipa.ssl.from': { en: 'From', bn: 'থেকে' },
    'ipa.ssl.to': { en: 'To', bn: 'পর্যন্ত' },
    'ipa.ssl.show': { en: 'Show', bn: 'দেখান' },
    'ipa.ssl.excel': { en: 'Excel', bn: 'এক্সেল' },
    'ipa.ssl.opening': { en: 'In clearing at the start', bn: 'শুরুতে ক্লিয়ারিং-এ' },
    'ipa.ssl.received': { en: 'Gross received (paid online)', bn: 'মোট পাওয়া (অনলাইনে পরিশোধ)' },
    'ipa.ssl.settled': { en: 'Settled by SSL (gross)', bn: 'SSL সেটেল করেছে (মোট)' },
    'ipa.ssl.fee_taken': { en: 'SSL charge (fee taken)', bn: 'SSL চার্জ (কেটে নেওয়া)' },
    'ipa.ssl.net_bank': { en: 'Net to bank', bn: 'ব্যাংকে এসেছে' },
    'ipa.ssl.closing': { en: 'Still in clearing at the end', bn: 'শেষে এখনও ক্লিয়ারিং-এ' },
} satisfies Record<string, Entry>;
