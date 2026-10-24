import type { Entry } from './entry';

/**
 * Joy's initial plan, catalogue slices (24 Oct 2026): B4 product master fields
 * (SKU filled in, Can be sold, Packaging, Made here, usual supplier), B5 recipe
 * costing, and the batch picker of B6 on the stock correction popup.
 */
export const ipCatalogue = {
    // ── B4: the product popup and lists ─────────────────────────────────
    'ipc.sku.auto': {
        en: 'Filled in from the name, brand and category. Type over it to use your own.',
        bn: 'নাম, ব্র্যান্ড ও ক্যাটাগরি থেকে বসানো হয়েছে। নিজের কোড চাইলে এর ওপর লিখুন।',
    },
    'ipc.sale.label': { en: 'Can be sold', bn: 'বিক্রি করা যায়' },
    'ipc.sale.hint_raw': {
        en: 'Off: no order, counter bill, challan, invoice, quotation, import or website order can take it. Switch it on only if you sell it as it is.',
        bn: 'বন্ধ থাকলে: কোনো অর্ডার, কাউন্টার বিল, চালান, ইনভয়েস, কোটেশন, ইমপোর্ট বা ওয়েবসাইট অর্ডারে এটা নেওয়া যাবে না। যেমন আছে তেমন বিক্রি করলে তবেই চালু করুন।',
    },
    'ipc.sale.hint_product': {
        en: 'Off: it stays in stock, but no sale of any kind can take it.',
        bn: 'বন্ধ থাকলে: স্টকে থাকবে, কিন্তু কোনো বিক্রিতে নেওয়া যাবে না।',
    },
    'ipc.kind.label': { en: 'Kind', bn: 'ধরন' },
    'ipc.kind.raw': { en: 'Raw material', bn: 'কাঁচামাল' },
    'ipc.kind.packaging': { en: 'Packaging (bottle, jar, cap, label, carton)', bn: 'প্যাকেজিং (বোতল, জার, ক্যাপ, লেবেল, কার্টন)' },
    'ipc.supplier.label': { en: 'Usual supplier', bn: 'নিয়মিত সরবরাহকারী' },
    'ipc.supplier.none': { en: 'None', bn: 'নেই' },
    'ipc.supplier.hint': {
        en: 'Proposed on a new purchase of this item. The purchase can still name someone else.',
        bn: 'এই আইটেমের নতুন ক্রয়ে প্রস্তাব করা হবে। ক্রয়ে চাইলে অন্য কাউকেও দেওয়া যাবে।',
    },
    'ipc.made_here': { en: 'Made here', bn: 'এখানে তৈরি' },
    'ipc.made_here_hint': { en: 'A recipe for it is in use', bn: 'এর একটি রেসিপি চালু আছে' },
    'ipc.bought_in': { en: 'Bought in', bn: 'কিনে আনা' },
    'ipc.not_for_sale': { en: 'Not for sale', bn: 'বিক্রির জন্য নয়' },
    'ipc.packaging': { en: 'Packaging', bn: 'প্যাকেজিং' },
    'ipc.po.supplier_proposed': {
        en: '{supplier} filled in: the usual supplier of {product}. Change it if this purchase is from someone else.',
        bn: '{supplier} বসানো হয়েছে: {product}-এর নিয়মিত সরবরাহকারী। এই ক্রয় অন্য কারও কাছ থেকে হলে বদলে দিন।',
    },

    // ── B5: recipe costing ──────────────────────────────────────────────
    'ipc.recipe.rate': { en: 'Rate (BDT)', bn: 'দর (টাকা)' },
    'ipc.recipe.line_total': { en: 'Total (BDT)', bn: 'মোট (টাকা)' },
    'ipc.recipe.raw': { en: 'Raw materials', bn: 'কাঁচামাল' },
    'ipc.recipe.packaging': { en: 'Packaging materials', bn: 'প্যাকেজিং সামগ্রী' },
    'ipc.recipe.subtotal': { en: 'Subtotal: {section}', bn: 'উপমোট: {section}' },
    'ipc.recipe.direct': { en: 'Subtotal: Direct materials', bn: 'উপমোট: সরাসরি উপকরণ' },
    'ipc.recipe.per_unit': { en: 'Cost of one {unit}', bn: 'এক {unit}-এর খরচ' },
    'ipc.recipe.per': { en: 'per {unit}', bn: 'প্রতি {unit}' },
    'ipc.recipe.basis.latest_batch': { en: 'latest batch cost', bn: 'সর্বশেষ ব্যাচের খরচ' },
    'ipc.recipe.average_hint': { en: 'stock on hand averages {cost}', bn: 'হাতে থাকা স্টকের গড় {cost}' },
    'ipc.recipe.no_rate': { en: 'No cost yet: never stocked', bn: 'এখনও খরচ নেই: কখনও স্টকে আসেনি' },
    'ipc.recipe.incomplete': {
        en: '{count} item(s) have no cost yet, so the totals leave them out.',
        bn: '{count}টি আইটেমের এখনও খরচ নেই, তাই মোটে সেগুলো ধরা হয়নি।',
    },
    'ipc.recipe.estimate_note': {
        en: "An estimate from today's batch costs, wastage included. A run is costed from the batches it actually uses.",
        bn: 'আজকের ব্যাচের খরচ থেকে আনুমানিক হিসাব, অপচয়সহ। প্রোডাকশন রানের খরচ ধরা হয় আসলে যে ব্যাচগুলো লাগে সেগুলো থেকে।',
    },
    'ipc.recipe.in_use_from': { en: 'In use from', bn: 'চালু হবে যেদিন থেকে' },

    // ── B6: the batch picker on the stock correction popup ──────────────
    'ipc.batch.label': { en: 'Which batch', bn: 'কোন ব্যাচ' },
    'ipc.batch.auto': { en: 'Let the system choose (soonest expiry first)', bn: 'সিস্টেম বেছে নিক (যার মেয়াদ আগে শেষ, সেটা আগে)' },
    'ipc.batch.loading': { en: 'Loading batches...', bn: 'ব্যাচ লোড হচ্ছে...' },
    'ipc.batch.none': { en: 'No batch of this item holds stock here.', bn: 'এখানে এই আইটেমের কোনো ব্যাচে স্টক নেই।' },
    'ipc.batch.left': { en: '{quantity} {unit} left', bn: '{quantity} {unit} বাকি' },
    'ipc.batch.each': { en: '{cost} each', bn: 'প্রতিটি {cost}' },
    'ipc.batch.expires': { en: 'expires {date}', bn: 'মেয়াদ {date}' },
    'ipc.batch.expired': { en: 'expired', bn: 'মেয়াদোত্তীর্ণ' },
    'ipc.batch.on_hold': { en: 'on hold', bn: 'আটকে রাখা' },
    'ipc.batch.hint': {
        en: "Pick the exact batch that was damaged, spoiled or lost: that batch's own cost is written off, not an average.",
        bn: 'যে ব্যাচটা নষ্ট, পচা বা হারিয়েছে ঠিক সেটা বাছুন: গড় নয়, ওই ব্যাচের নিজের খরচই বাদ যাবে।',
    },
    'ipc.batch.loss_at': {
        en: "Written off at this batch's own cost: {cost} each, {total} in all.",
        bn: 'এই ব্যাচের নিজের খরচে বাদ যাবে: প্রতিটি {cost}, মোট {total}।',
    },
    'ipc.batch.too_much': { en: 'This batch holds only {quantity}.', bn: 'এই ব্যাচে আছে মাত্র {quantity}।' },
} satisfies Record<string, Entry>;
