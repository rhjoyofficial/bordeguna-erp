import type { Entry } from './entry';

/**
 * Joy's initial plan, B20 (24 Oct 2026): sales returns start from the invoice
 * (or its challan); per line Good (back to a selling warehouse) or Damaged
 * (to the Not for sale warehouse); the money reduces what is owed, or goes
 * back, or stays as an open credit; an exchange is a NEW order, linked.
 */
export const ipSalesReturns = {
    // Buttons on the invoice, the challan list and the order's challans.
    'ips.sret.return': { en: 'Return', bn: 'ফেরত' },
    'ips.sret.return_title': { en: 'Goods came back on this: record a return (credit note)', bn: 'এই মাল ফেরত এসেছে: ফেরত (ক্রেডিট নোট) লিখুন' },

    // The form.
    'ips.sret.title': { en: 'Return (credit note)', bn: 'ফেরত (ক্রেডিট নোট)' },
    'ips.sret.from_invoice': { en: 'Return of invoice {number}', bn: 'ইনভয়েস {number} এর ফেরত' },
    'ips.sret.invoice_owes': { en: 'Invoice total {total}, still owed {owed}.', bn: 'ইনভয়েস মোট {total}, এখনো বাকি {owed}।' },
    'ips.sret.challan_of_invoice': { en: 'Challan of this invoice', bn: 'এই ইনভয়েসের চালান' },
    'ips.sret.date': { en: 'Return date', bn: 'ফেরতের তারিখ' },
    'ips.sret.col_batches': { en: 'Batch it left from', bn: 'যে ব্যাচ থেকে গিয়েছিল' },
    'ips.sret.col_price': { en: 'Billed at', bn: 'বিলের দাম' },
    'ips.sret.col_good': { en: 'Good, back to stock', bn: 'ভালো, স্টকে ফেরত' },
    'ips.sret.col_damaged': { en: 'Damaged, to quarantine', bn: 'নষ্ট, আলাদা গুদামে' },
    'ips.sret.col_write_off': { en: 'Write off now', bn: 'এখনই বাদ দিন' },
    'ips.sret.expires': { en: 'expires {date}', bn: 'মেয়াদ {date}' },
    'ips.sret.good_warehouse': { en: 'Good goods go to', bn: 'ভালো মাল যাবে' },
    'ips.sret.good_warehouse_hint': {
        en: 'Same batch, same cost and expiry, whichever warehouse you choose.',
        bn: 'যে গুদামই বেছে নিন, ব্যাচ, খরচ ও মেয়াদ একই থাকবে।',
    },
    'ips.sret.credit_about': { en: 'Credit about {amount}', bn: 'ক্রেডিট প্রায় {amount}' },
    'ips.sret.reduces_due': {
        en: 'It first reduces what the customer still owes on the invoice ({owed}).',
        bn: 'প্রথমে ইনভয়েসে কাস্টমারের বাকি ({owed}) কমাবে।',
    },
    'ips.sret.left_over': {
        en: 'About {amount} is more than they owe: refund it now, or keep it as an open credit for their next invoice.',
        bn: 'প্রায় {amount} তাদের বাকির চেয়ে বেশি: এখনই টাকা ফেরত দিন, অথবা পরের ইনভয়েসের জন্য খোলা ক্রেডিট হিসেবে রাখুন।',
    },
    'ips.sret.money_title': { en: 'Money', bn: 'টাকা' },
    'ips.sret.keep_credit': {
        en: "Keep as open credit (the customer's advance, no cash now)",
        bn: 'খোলা ক্রেডিট হিসেবে রাখুন (কাস্টমারের অগ্রিম, এখন নগদ নয়)',
    },
    'ips.sret.refund_now': { en: 'Refund now', bn: 'এখনই টাকা ফেরত' },
    'ips.sret.refund_from': { en: 'From', bn: 'যেখান থেকে' },
    'ips.sret.refund_amount': { en: 'Amount (blank = all that is left)', bn: 'পরিমাণ (খালি = যা বাকি সব)' },
    'ips.sret.add_split': { en: 'Split over another account', bn: 'আরেকটি অ্যাকাউন্টে ভাগ করুন' },
    'ips.sret.remove': { en: 'Remove', bn: 'সরান' },
    'ips.sret.choose_account': { en: 'Choose the account', bn: 'অ্যাকাউন্ট বেছে নিন' },
    'ips.sret.exchange': {
        en: 'Exchange: after the return, open a new order for the replacement',
        bn: 'বদল: ফেরতের পরে বদলি মালের জন্য নতুন অর্ডার খুলুন',
    },
    'ips.sret.exchange_hint': {
        en: 'The return and the replacement are never mixed. The new order takes fresh batches, so the new item carries its own batch and expiry.',
        bn: 'ফেরত আর বদলি কখনো মেশানো হয় না। নতুন অর্ডার নতুন ব্যাচ নেয়, তাই নতুন মালের নিজের ব্যাচ ও মেয়াদ থাকে।',
    },
    'ips.sret.record': { en: 'Record return', bn: 'ফেরত লিখুন' },

    // The order form, opened for the replacement.
    'ips.sret.exchange_for': { en: 'Exchange for {number}', bn: '{number} এর বদলি' },

    // The return page.
    'ips.sret.opened_from': { en: 'From invoice', bn: 'ইনভয়েস থেকে' },
    'ips.sret.good_went_to': { en: 'Good goods went to', bn: 'ভালো মাল গেছে' },
    'ips.sret.replacement': { en: 'Replacement order', bn: 'বদলি অর্ডার' },
    'ips.sret.start_exchange': { en: 'Exchange', bn: 'বদল' },
    'ips.sret.start_exchange_title': {
        en: 'Open a new order for the replacement, linked to this return',
        bn: 'এই ফেরতের সাথে যুক্ত, বদলি মালের নতুন অর্ডার খুলুন',
    },
    'ips.sret.use_credit': { en: 'Use on an invoice', bn: 'ইনভয়েসে ব্যবহার করুন' },
    'ips.sret.use_credit_title': { en: 'Use the open credit of {number}', bn: '{number} এর খোলা ক্রেডিট ব্যবহার করুন' },
    'ips.sret.use_credit_hint': {
        en: 'Open credit {open}. It pays the invoice you choose; nothing new is posted, the return already credited the customer.',
        bn: 'খোলা ক্রেডিট {open}। আপনি যে ইনভয়েস বাছবেন তা শোধ হবে; নতুন কিছু পোস্ট হয় না, ফেরত আগেই কাস্টমারকে ক্রেডিট দিয়েছে।',
    },
    'ips.sret.invoice': { en: 'Invoice', bn: 'ইনভয়েস' },
    'ips.sret.choose_invoice': { en: 'Choose the invoice', bn: 'ইনভয়েস বেছে নিন' },
    'ips.sret.owes': { en: 'owes {amount}', bn: 'বাকি {amount}' },
    'ips.sret.amount': { en: 'Amount', bn: 'পরিমাণ' },
    'ips.sret.apply': { en: 'Use credit', bn: 'ক্রেডিট ব্যবহার করুন' },
    'ips.sret.cancel': { en: 'Cancel', bn: 'বাতিল' },

    // Courier: a refused parcel.
    'ips.sret.courier_damaged': { en: 'Damaged, to quarantine', bn: 'নষ্ট, আলাদা গুদামে' },
} satisfies Record<string, Entry>;
