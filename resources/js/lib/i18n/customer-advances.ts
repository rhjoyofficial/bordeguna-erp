import type { Entry } from './entry';

/**
 * ⭐ Customer advances (Joy's initial plan R90, B11, 24 Oct 2026): money a
 * customer pays before there is an invoice, used on their invoices later or
 * paid back; the Advances page, the invoice page, Receive payment, the order
 * page (cancelled order), the party statement and the customer page.
 * Latin digits; money and dates never translate.
 */
export const customerAdvances = {
    'adv.tab': { en: 'Advances', bn: 'অগ্রিম' },
    'adv.title': { en: 'Customer advances', bn: 'কাস্টমারের অগ্রিম' },
    'adv.intro': {
        en: 'Money a customer paid before there is an invoice. It is kept as their advance (2150) and used on their invoices later, oldest first, or paid back.',
        bn: 'ইনভয়েসের আগে কাস্টমার যে টাকা দিয়েছেন। এটি তাদের অগ্রিম (2150) হিসেবে থাকে। পরে তাদের ইনভয়েসে কাটা হয় (পুরনোটা আগে), অথবা ফেরত দেওয়া হয়।',
    },
    'adv.customer': { en: 'Customer', bn: 'কাস্টমার' },
    'adv.all_customers': { en: 'All customers', bn: 'সব কাস্টমার' },
    'adv.with_us': { en: 'Their advance with us', bn: 'আমাদের কাছে তাদের অগ্রিম' },
    'adv.with_us_hint': { en: 'Not yet used on an invoice or paid back.', bn: 'এখনো ইনভয়েসে কাটা হয়নি বা ফেরত দেওয়া হয়নি।' },
    'adv.receive': { en: 'Receive advance', bn: 'অগ্রিম নিন' },
    'adv.receive_title': { en: 'Receive an advance', bn: 'অগ্রিম গ্রহণ' },
    'adv.receive_text': {
        en: 'Money with no invoice and no order behind it. It is kept as the customer’s advance until an invoice uses it.',
        bn: 'যে টাকার পেছনে কোনো ইনভয়েস বা অর্ডার নেই। কোনো ইনভয়েসে কাটা না হওয়া পর্যন্ত এটি কাস্টমারের অগ্রিম হিসেবে থাকবে।',
    },
    'adv.refund': { en: 'Pay back', bn: 'ফেরত দিন' },
    'adv.refund_title': { en: 'Pay an advance back', bn: 'অগ্রিম ফেরত' },
    'adv.refund_text': {
        en: 'Money goes back to the customer out of the account you choose.',
        bn: 'আপনার বাছাই করা অ্যাকাউন্ট থেকে টাকা কাস্টমারকে ফেরত যাবে।',
    },
    'adv.date': { en: 'Date', bn: 'তারিখ' },
    'adv.amount': { en: 'Amount', bn: 'পরিমাণ (টাকা)' },
    'adv.account_in': { en: 'Received into', bn: 'কোন অ্যাকাউন্টে এলো' },
    'adv.account_out': { en: 'Paid from', bn: 'কোন অ্যাকাউন্ট থেকে' },
    'adv.choose_account': { en: 'Cash, bank or wallet', bn: 'ক্যাশ, ব্যাংক বা ওয়ালেট' },
    'adv.choose_customer': { en: 'Choose the customer', bn: 'কাস্টমার বাছাই করুন' },
    'adv.which': { en: 'Which advance', bn: 'কোন অগ্রিম' },
    'adv.reference': { en: 'Reference', bn: 'রেফারেন্স' },
    'adv.note': { en: 'Note', bn: 'নোট' },
    'adv.save': { en: 'Save', bn: 'সেভ করুন' },
    'adv.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'adv.working': { en: 'Working...', bn: 'কাজ চলছে...' },
    'adv.on_account': { en: 'On account', bn: 'অন অ্যাকাউন্ট' },
    'adv.open': { en: 'Open', bn: 'বাকি আছে' },
    'adv.number': { en: 'Number', bn: 'নম্বর' },
    'adv.kind': { en: 'Kind', bn: 'ধরন' },
    'adv.kind_receipt': { en: 'Received', bn: 'গ্রহণ' },
    'adv.kind_from_order': { en: 'From a cancelled order', bn: 'বাতিল অর্ডার থেকে' },
    'adv.list': { en: 'Advances', bn: 'অগ্রিমের তালিকা' },
    'adv.uses': { en: 'Used on invoices', bn: 'ইনভয়েসে কাটা হয়েছে' },
    'adv.refunds': { en: 'Paid back', bn: 'ফেরত দেওয়া হয়েছে' },
    'adv.from': { en: 'From', bn: 'কোথা থেকে' },
    'adv.invoice': { en: 'Invoice', bn: 'ইনভয়েস' },
    'adv.empty': { en: 'No advances yet.', bn: 'এখনো কোনো অগ্রিম নেই।' },
    'adv.reversed': { en: 'Reversed', bn: 'রিভার্স করা' },
    'adv.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'adv.actions': { en: 'Actions', bn: 'কাজ' },
    'adv.none_open': { en: 'Nothing open for this customer.', bn: 'এই কাস্টমারের কোনো অগ্রিম বাকি নেই।' },

    // ── The invoice page ───────────────────────────────────────────────
    'adv.use': { en: 'Use advance', bn: 'অগ্রিম থেকে কাটুন' },
    'adv.use_title': { en: 'Use the customer’s advance on {number}', bn: '{number}-এ কাস্টমারের অগ্রিম কাটুন' },
    'adv.use_text': {
        en: 'The oldest advance is filled in first. Change the amounts if another one should pay. No money moves: the advance pays the invoice.',
        bn: 'সবচেয়ে পুরনো অগ্রিম আগে বসানো হয়েছে। অন্যটি দিয়ে দিতে চাইলে পরিমাণ বদলান। টাকা কোথাও যায় না: অগ্রিম দিয়ে ইনভয়েস পরিশোধ হয়।',
    },
    'adv.use_available': { en: 'Advance with us: {amount}', bn: 'আমাদের কাছে অগ্রিম: {amount}' },
    'adv.use_total': { en: 'Using {amount} of {owed} owed', bn: 'বাকি {owed}-এর মধ্যে {amount} কাটা হচ্ছে' },
    'adv.use_too_much': { en: 'That is more than the invoice owes.', bn: 'এটি ইনভয়েসের বাকির চেয়ে বেশি।' },

    // ── Receive payment ────────────────────────────────────────────────
    'adv.receipt_use': { en: 'Use their advance first', bn: 'আগে তাদের অগ্রিম কাটুন' },
    'adv.receipt_use_hint': {
        en: 'The advance pays the oldest invoices first; the money received then pays what is left.',
        bn: 'অগ্রিম দিয়ে আগে পুরনো ইনভয়েস পরিশোধ হবে; তারপর আসা টাকা বাকি অংশ পরিশোধ করবে।',
    },
    'adv.receipt_from_advance': { en: 'From advance', bn: 'অগ্রিম থেকে' },
    'adv.keep_rest': { en: 'Keep the rest ({amount}) as the customer’s advance', bn: 'বাকি ({amount}) কাস্টমারের অগ্রিম হিসেবে রাখুন' },
    'adv.keep_all': { en: 'Keep all of it ({amount}) as the customer’s advance', bn: 'পুরোটা ({amount}) কাস্টমারের অগ্রিম হিসেবে রাখুন' },

    // ── The order page ─────────────────────────────────────────────────
    'adv.move': { en: 'Move to advance', bn: 'অগ্রিমে সরান' },
    'adv.move_title': { en: 'Move {number}’s money to the customer’s advance', bn: '{number}-এর টাকা কাস্টমারের অগ্রিমে সরান' },
    'adv.move_text': {
        en: 'Nothing more will be billed on this order. Instead of paying the money back, keep it as the customer’s advance for their next invoice.',
        bn: 'এই অর্ডারে আর বিল হবে না। টাকা ফেরত না দিয়ে কাস্টমারের পরের ইনভয়েসের জন্য অগ্রিম হিসেবে রাখুন।',
    },
    'adv.moved': { en: 'Moved to advance', bn: 'অগ্রিমে সরানো হয়েছে' },
    'adv.or_refund': { en: 'Or pay it back with Refund below.', bn: 'অথবা নিচের রিফান্ড দিয়ে ফেরত দিন।' },

    // ── Party statement and the customer page ───────────────────────────
    'adv.side': { en: 'Their advance', bn: 'তাদের অগ্রিম' },
    'adv.change': { en: 'Their advance with us (change)', bn: 'আমাদের কাছে তাদের অগ্রিম (পরিবর্তন)' },
    'adv.closing': { en: 'Their advance still with us', bn: 'আমাদের কাছে এখনও তাদের অগ্রিম' },
    'adv.page_link': { en: 'Advances', bn: 'অগ্রিম' },
    'adv.paid_total': { en: 'Advance paid', bn: 'অগ্রিম দিয়েছেন' },
    'adv.applied_total': { en: 'Used on invoices', bn: 'ইনভয়েসে কাটা' },
    'adv.refunded_total': { en: 'Paid back', bn: 'ফেরত' },

    // ── Sales returns (B13, 24 Oct 2026): credit note number and Reverse ─────
    'ret.credit_note': { en: 'Credit note {number}', bn: 'ক্রেডিট নোট {number}' },
    'ret.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'ret.reversed': { en: 'Reversed', bn: 'রিভার্স করা' },
    'ret.reversed_on': { en: 'Reversed on {date} by {by}.', bn: '{date} তারিখে {by} রিভার্স করেছেন।' },
    'ret.mirror': { en: 'Reversal of an earlier return', bn: 'আগের একটি রিটার্নের রিভার্সাল' },
    'ret.mirror_of': { en: 'This undoes return', bn: 'এটি এই রিটার্নটি বাতিল করে:' },

    // ── Courier payouts (B13): Reverse a posted statement ─────────────────
    'cs.reverse': { en: 'Reverse', bn: 'রিভার্স' },
    'cs.reversed': { en: 'Reversed', bn: 'রিভার্স করা' },
    'cs.reversed_on': {
        en: 'Reversed on {date} by {by}. Import the right statement again.',
        bn: '{date} তারিখে {by} রিভার্স করেছেন। সঠিক স্টেটমেন্টটি আবার ইমপোর্ট করুন।',
    },
} satisfies Record<string, Entry>;
