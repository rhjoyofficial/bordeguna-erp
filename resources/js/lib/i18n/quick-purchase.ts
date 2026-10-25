import type { Entry } from './entry';

/**
 * Purchases > Buy and receive (supplier finance wave C, ruling P4, 18 Oct
 * 2026): the one-screen purchase and its result page. Numbers, dates and
 * money never translate.
 */
export const quickPurchase = {
    'quick.title': { en: 'Buy and receive', bn: 'কেনা ও গ্রহণ' },
    'quick.intro': {
        en: 'Goods bought and brought in together, entered once. This saves the usual documents, each with its own number: the purchase order, the goods receipt, any extra costs, the supplier bill and the payment.',
        bn: 'একসাথে কেনা ও আনা মাল, একবারে লিখুন। এতে সাধারণ সব কাগজ নিজের নম্বরসহ তৈরি হয়: ক্রয় আদেশ, মাল গ্রহণ, বাড়তি খরচ, সরবরাহকারীর বিল আর পেমেন্ট।',
    },
    'quick.missing': {
        en: 'You cannot save here yet. Buy and receive needs every right its steps need, and you may not:',
        bn: 'আপনি এখানে এখনও সেভ করতে পারবেন না। প্রতিটি ধাপের অনুমতি লাগে, আর আপনার অনুমতি নেই:',
    },
    'quick.order_needs_approval': {
        en: 'Your purchase orders need approval. Saving here creates the order only; the goods are received from the order once it is approved.',
        bn: 'আপনার ক্রয় আদেশ অনুমোদন লাগে। এখানে সেভ করলে শুধু আদেশ তৈরি হবে; অনুমোদনের পরে আদেশ থেকে মাল গ্রহণ করুন।',
    },
    'quick.supplier': { en: 'Supplier', bn: 'সরবরাহকারী' },
    'quick.warehouse': { en: 'Receiving warehouse', bn: 'যে গুদামে মাল আসবে' },
    'quick.date': { en: 'Purchase date', bn: 'কেনার তারিখ' },
    'quick.date_hint': {
        en: 'The order, receipt, bill and payment all carry this date.',
        bn: 'আদেশ, গ্রহণ, বিল আর পেমেন্ট সবই এই তারিখের।',
    },
    'quick.backdate_reason': { en: 'Why is it entered late?', bn: 'দেরিতে কেন লেখা হচ্ছে?' },
    'quick.bill_number': { en: "Supplier's invoice or cash memo no.", bn: 'সরবরাহকারীর ইনভয়েস বা ক্যাশ মেমো নং' },
    'quick.bill_number_hint': {
        en: 'As printed on their paper. The ERP gives its own numbers separately.',
        bn: 'তাদের কাগজে যেমন ছাপা। ERP নিজের নম্বর আলাদা দেয়।',
    },
    'quick.challan': { en: 'Challan no. (optional)', bn: 'চালান নং (ঐচ্ছিক)' },
    'quick.due_date': { en: 'Due date (optional)', bn: 'পরিশোধের শেষ তারিখ (ঐচ্ছিক)' },
    'quick.items': { en: 'Items', bn: 'পণ্য' },
    'quick.batch': { en: 'Batch no.', bn: 'ব্যাচ নং' },
    'quick.expiry': { en: 'Expiry', bn: 'মেয়াদ শেষ' },
    'quick.add_item': { en: '+ Add item', bn: '+ পণ্য যোগ' },
    'quick.remove': { en: 'Remove', bn: 'সরান' },
    'quick.goods_total': { en: 'Goods total', bn: 'মালের মোট' },
    'quick.extra_costs': { en: 'Extra costs (optional)', bn: 'বাড়তি খরচ (ঐচ্ছিক)' },
    'quick.extra_costs_hint': {
        en: "Carrying, labour, conveyance, courier, feeding and similar. Spread over the items by value. Each type goes into the stock cost or is an expense by the Owner's default (Finance policy > Purchase cost types); every type is stock cost until he changes one.",
        bn: 'পরিবহন, মজুরি, যাতায়াত, কুরিয়ার, খাওয়া ইত্যাদি। মূল্য অনুযায়ী পণ্যে ভাগ হয়। প্রতিটি ধরন স্টকের দামে যাবে নাকি খরচ হবে তা মালিকের নিয়মে চলে (ফাইন্যান্স পলিসি > ক্রয়ের খরচের ধরন); তিনি না বদলানো পর্যন্ত সব ধরন স্টকের দামে।',
    },
    'quick.add_cost': { en: '+ Add extra cost', bn: '+ বাড়তি খরচ যোগ' },
    'quick.cost_type': { en: 'Cost for', bn: 'খরচের ধরন' },
    'quick.amount': { en: 'Amount', bn: 'পরিমাণ' },
    'quick.who': { en: 'Who is paid', bn: 'কাকে দেওয়া হয়' },
    'quick.owed_supplier': { en: 'Owed to this supplier', bn: 'এই সরবরাহকারীর পাওনা' },
    'quick.paid_from': { en: 'Paid from', bn: 'যে অ্যাকাউন্ট থেকে দেওয়া' },
    'quick.into_stock': { en: 'into stock cost', bn: 'স্টকের দামে' },
    'quick.expense': { en: 'expense', bn: 'খরচ' },
    'quick.description': { en: 'Description (optional)', bn: 'বিবরণ (ঐচ্ছিক)' },
    'quick.extra_costs_wait': {
        en: 'You may enter extra costs but not post them: they wait for approval before reaching the stock value.',
        bn: 'আপনি বাড়তি খরচ লিখতে পারবেন কিন্তু পোস্ট নয়: স্টকের দামে যাওয়ার আগে অনুমোদন লাগবে।',
    },
    'quick.discount': { en: 'Discount on the bill (optional)', bn: 'বিলে ছাড় (ঐচ্ছিক)' },
    'quick.discount_reference': { en: 'Where it is printed', bn: 'কোথায় ছাপা আছে' },
    'quick.discount_hint': {
        en: "A discount printed on the supplier's bill. It lowers what the goods cost.",
        bn: 'সরবরাহকারীর বিলে ছাপা ছাড়। এতে মালের দাম কমে।',
    },
    'quick.payment': { en: 'Payment', bn: 'পেমেন্ট' },
    'quick.pay_now': { en: 'Paid now', bn: 'এখনই দেওয়া হয়েছে' },
    'quick.pay_all': { en: 'Pay all', bn: 'সব দিন' },
    'quick.amount_paid': { en: 'Amount paid', bn: 'যত দেওয়া হয়েছে' },
    'quick.reference': { en: 'Reference', bn: 'রেফারেন্স' },
    'quick.reference_hint': { en: 'Cheque number, bKash trx id, slip number', bn: 'চেক নম্বর, বিকাশ ট্রানজেকশন আইডি, স্লিপ নম্বর' },
    'quick.part_payment': {
        en: 'Part payment: the rest stays owed on the bill.',
        bn: 'আংশিক পেমেন্ট: বাকিটা বিলে পাওনা থাকবে।',
    },
    'quick.overpay_advance': {
        en: 'more than is owed becomes an advance to this supplier, to use against a later bill or to be refunded.',
        bn: 'পাওনার বেশি অংশ এই সরবরাহকারীর অগ্রিম হবে, পরের বিলে কাটা বা ফেরত নেওয়ার জন্য।',
    },
    'quick.overpay_refused': {
        en: 'This pays more than is owed. Lower it, or ask someone who may record advances.',
        bn: 'এটি পাওনার বেশি। কমান, অথবা যার অগ্রিম লেখার অনুমতি আছে তাকে বলুন।',
    },
    'quick.no_pay_right': { en: 'You may not pay suppliers, so the bill stays owed.', bn: 'আপনার পেমেন্টের অনুমতি নেই, তাই বিল পাওনা থাকবে।' },
    'quick.note': { en: 'Note (optional)', bn: 'নোট (ঐচ্ছিক)' },
    'quick.owed_total': { en: 'Owed to the supplier', bn: 'সরবরাহকারীর পাওনা' },
    'quick.left_owing': { en: 'Left owing', bn: 'বাকি থাকবে' },
    'quick.save': { en: 'Save purchase', bn: 'ক্রয় সেভ করুন' },
    'quick.saving': { en: 'Saving...', bn: 'সেভ হচ্ছে...' },
    'quick.all_or_nothing': {
        en: 'Saved all together: if any step is refused, nothing is saved.',
        bn: 'সব একসাথে সেভ হয়: কোনো ধাপ আটকে গেলে কিছুই সেভ হয় না।',
    },
    // The result page.
    'quick.result': { en: 'Purchase saved', bn: 'ক্রয় সেভ হয়েছে' },
    'quick.documents': { en: 'Documents made', bn: 'তৈরি হওয়া কাগজ' },
    'quick.status.completed': { en: 'Complete', bn: 'সম্পূর্ণ' },
    'quick.status.waiting': { en: 'Waiting for approval', bn: 'অনুমোদনের অপেক্ষায়' },
    'quick.again': { en: 'Buy and receive again', bn: 'আবার কেনা ও গ্রহণ' },
} satisfies Record<string, Entry>;
