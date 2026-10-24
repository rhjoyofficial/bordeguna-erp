import type { Entry } from './entry';

/**
 * Joy's initial plan B22 (24 Oct 2026; R10, Q9 = A): product families. A
 * family (Miniket Rice) groups separate sizes (1 kg, 25 kg, 50 kg bosta);
 * each size keeps its own SKU, stock, batches and prices.
 */
export const ipFamilies = {
    // ── Names ───────────────────────────────────────────────────────────
    'ipf.nav': { en: 'Families', bn: 'ফ্যামিলি' },
    'ipf.bin_type': { en: 'Product families', bn: 'প্রোডাক্ট ফ্যামিলি' },
    'ipf.title': { en: 'Product families', bn: 'প্রোডাক্ট ফ্যামিলি' },
    'ipf.family': { en: 'Family', bn: 'ফ্যামিলি' },
    'ipf.size': { en: 'Size', bn: 'সাইজ' },
    'ipf.sizes': { en: 'Sizes', bn: 'সাইজসমূহ' },
    'ipf.sizes_count': { en: '{count} sizes', bn: '{count}টি সাইজ' },
    'ipf.one_size': { en: '1 size', bn: '1টি সাইজ' },
    'ipf.attribute': { en: 'Sizes are by', bn: 'সাইজ কিসের ভিত্তিতে' },
    'ipf.attribute_hint': { en: 'For example Packaging size, Weight or Volume.', bn: 'যেমন প্যাকেজিং সাইজ, ওজন বা পরিমাণ।' },
    'ipf.family_sku': { en: 'Family SKU', bn: 'ফ্যামিলি SKU' },
    'ipf.family_sku_hint': {
        en: 'A new size gets this SKU plus its size (MINIKET-25KG). Each size keeps its own SKU.',
        bn: 'নতুন সাইজ এই SKU আর তার সাইজ মিলিয়ে নিজের SKU পায় (MINIKET-25KG)। প্রতিটি সাইজের SKU আলাদা থাকে।',
    },
    'ipf.name': { en: 'Family name', bn: 'ফ্যামিলির নাম' },
    'ipf.description': { en: 'Description', bn: 'বিবরণ' },
    'ipf.category': { en: 'Category', bn: 'ক্যাটাগরি' },
    'ipf.brand': { en: 'Brand', bn: 'ব্র্যান্ড' },
    'ipf.none': { en: 'None', bn: 'নেই' },
    'ipf.on_hand': { en: 'On hand', bn: 'মজুদ' },
    'ipf.stock_value': { en: 'Stock value', bn: 'মজুদের মূল্য' },
    'ipf.mrp': { en: 'MRP', bn: 'এমআরপি' },
    'ipf.sku': { en: 'SKU', bn: 'SKU কোড' },
    'ipf.product': { en: 'Product', bn: 'প্রোডাক্ট' },
    'ipf.unit': { en: 'Unit', bn: 'একক' },
    'ipf.inactive': { en: 'inactive', bn: 'বন্ধ' },
    'ipf.open': { en: 'Open', bn: 'খুলুন' },
    'ipf.search': { en: 'Search family name or SKU', bn: 'ফ্যামিলির নাম বা SKU খুঁজুন' },
    'ipf.empty': {
        en: 'No families yet. Open a product and press Make it a family to group its sizes.',
        bn: 'এখনো কোনো ফ্যামিলি নেই। একটি প্রোডাক্ট খুলে "ফ্যামিলি বানান" চাপুন, তার সাইজগুলো এক জায়গায় আসবে।',
    },
    'ipf.list_note': {
        en: 'A family groups separate sizes. Each size is its own product, with its own SKU, stock, batches and prices.',
        bn: 'ফ্যামিলি আলাদা আলাদা সাইজকে এক জায়গায় রাখে। প্রতিটি সাইজ নিজেই একটি প্রোডাক্ট, নিজের SKU, মজুদ, ব্যাচ ও দাম সহ।',
    },
    'ipf.on_hand_hint': {
        en: 'Added up within each unit only: 10 packs and 2 bags are not 12 of anything.',
        bn: 'শুধু একই এককের মধ্যে যোগ করা হয়েছে: 10টি প্যাকেট আর 2টি বস্তা মিলে 12 কিছু হয় না।',
    },

    // ── The family page ─────────────────────────────────────────────────
    'ipf.prices_title': { en: 'Prices by price group, today', bn: 'আজকের দাম, প্রাইস গ্রুপ অনুযায়ী' },
    'ipf.prices_hint': {
        en: 'A blank Retail price sells at the MRP. Set or change prices on the Prices screen.',
        bn: 'রিটেইল দাম খালি থাকলে MRP-তে বিক্রি হয়। দাম বসানো বা বদলানো হয় দাম পাতায়।',
    },
    'ipf.set_prices': { en: 'Set prices for these sizes', bn: 'এই সাইজগুলোর দাম বসান' },
    'ipf.no_sizes': {
        en: 'This family has no sizes. Add a size, or delete the family.',
        bn: 'এই ফ্যামিলিতে কোনো সাইজ নেই। একটি সাইজ যোগ করুন, অথবা ফ্যামিলিটি মুছে দিন।',
    },
    'ipf.edit': { en: 'Edit', bn: 'এডিট' },
    'ipf.edit_title': { en: 'Edit {name}', bn: '{name} এডিট করুন' },
    'ipf.edit_hint': {
        en: 'Changes the family only. Its sizes keep their own name, SKU, category and brand; a new size starts with these.',
        bn: 'শুধু ফ্যামিলি বদলাবে। সাইজগুলোর নিজের নাম, SKU, ক্যাটাগরি ও ব্র্যান্ড যেমন আছে তেমন থাকবে; নতুন সাইজ এগুলো দিয়ে শুরু হবে।',
    },
    'ipf.save': { en: 'Save', bn: 'সেভ করুন' },
    'ipf.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'ipf.delete': { en: 'Delete', bn: 'মুছুন' },
    'ipf.delete_title': { en: 'Delete the family {name}', bn: '{name} ফ্যামিলিটি মুছুন' },
    'ipf.delete_help': {
        en: 'It goes to the Recycle bin; the Owner can restore it. No product changes.',
        bn: 'এটি রিসাইকেল বিনে যাবে; মালিক ফিরিয়ে আনতে পারবেন। কোনো প্রোডাক্ট বদলাবে না।',
    },

    // ── Add a size ──────────────────────────────────────────────────────
    'ipf.add_size': { en: 'Add a size', bn: 'সাইজ যোগ করুন' },
    'ipf.add_size_title': { en: 'Add a size to {name}', bn: '{name}-এ সাইজ যোগ করুন' },
    'ipf.add_size_copies': {
        en: "A new product. It takes the family's category and brand, and the unit ({unit}), kind, expiry tracking and selling switches of {template}. Its stock, batches and prices start empty.",
        bn: 'একটি নতুন প্রোডাক্ট। ফ্যামিলির ক্যাটাগরি ও ব্র্যান্ড, আর {template}-এর একক ({unit}), ধরন, মেয়াদ ট্র্যাকিং ও বিক্রির সুইচ নেবে। এর মজুদ, ব্যাচ ও দাম খালি থেকে শুরু হবে।',
    },
    'ipf.add_size_empty': {
        en: 'This family has no size to copy from, so choose the stock unit and the kind.',
        bn: 'এই ফ্যামিলিতে কপি করার মতো কোনো সাইজ নেই, তাই মজুদের একক আর ধরন বেছে নিন।',
    },
    'ipf.value': { en: 'Size', bn: 'সাইজ' },
    'ipf.value_placeholder': { en: 'e.g. 25 kg', bn: 'যেমন 25 কেজি' },
    'ipf.size_sku': { en: 'SKU', bn: 'SKU কোড' },
    'ipf.size_sku_hint': {
        en: 'Filled in from the family SKU and the size. Type over it to use your own.',
        bn: 'ফ্যামিলি SKU আর সাইজ থেকে বসানো হয়েছে। নিজের কোড চাইলে এর ওপর লিখুন।',
    },
    'ipf.size_name': { en: 'Product name', bn: 'প্রোডাক্টের নাম' },
    'ipf.size_mrp': { en: 'MRP (Tk)', bn: 'MRP (টাকা)' },
    'ipf.stock_unit': { en: 'Stock unit', bn: 'মজুদের একক' },
    'ipf.kind': { en: 'Kind', bn: 'ধরন' },
    'ipf.kind_product': { en: 'Finished good', bn: 'তৈরি পণ্য' },
    'ipf.kind_raw': { en: 'Raw material', bn: 'কাঁচামাল' },
    'ipf.kind_service': { en: 'Service', bn: 'সার্ভিস' },
    'ipf.choose': { en: 'Choose', bn: 'বেছে নিন' },

    // ── The product page ────────────────────────────────────────────────
    'ipf.size_of': { en: '{attribute} {value} of', bn: '{attribute} {value}, যে ফ্যামিলির:' },
    'ipf.other_sizes': { en: 'Other sizes:', bn: 'অন্য সাইজ:' },
    'ipf.single': { en: 'A single product, not in a family.', bn: 'একক প্রোডাক্ট, কোনো ফ্যামিলিতে নেই।' },
    'ipf.make': { en: 'Make it a family', bn: 'ফ্যামিলি বানান' },
    'ipf.make_title': { en: 'Make {name} a family', bn: '{name} দিয়ে ফ্যামিলি বানান' },
    'ipf.make_help': {
        en: 'A family groups separate sizes. {name} becomes its first size and stays exactly as it is: same SKU, stock, batches and prices. Then add more sizes from the family page.',
        bn: 'ফ্যামিলি আলাদা সাইজগুলোকে এক জায়গায় রাখে। {name} হবে প্রথম সাইজ, আর যেমন আছে তেমনই থাকবে: একই SKU, মজুদ, ব্যাচ ও দাম। তারপর ফ্যামিলি পাতা থেকে আরো সাইজ যোগ করুন।',
    },
    'ipf.this_size': { en: 'Size of this product', bn: 'এই প্রোডাক্টের সাইজ' },
    'ipf.make_confirm': { en: 'Make the family', bn: 'ফ্যামিলি বানান' },
    'ipf.join': { en: 'Join a family', bn: 'ফ্যামিলিতে যোগ দিন' },
    'ipf.join_title': { en: 'Add {name} to a family', bn: '{name}-কে একটি ফ্যামিলিতে যোগ করুন' },
    'ipf.join_help': {
        en: 'It becomes one more size of the family. Its SKU, stock, batches and prices stay as they are.',
        bn: 'এটি ফ্যামিলির আরেকটি সাইজ হবে। এর SKU, মজুদ, ব্যাচ ও দাম যেমন আছে তেমন থাকবে।',
    },
    'ipf.join_confirm': { en: 'Join', bn: 'যোগ দিন' },
    'ipf.leave': { en: 'Leave family', bn: 'ফ্যামিলি ছাড়ুন' },
    'ipf.leave_title': { en: '{name} leaves {family}', bn: '{name} {family} ছেড়ে যাবে' },
    'ipf.leave_help': {
        en: 'It becomes a single product again, with its SKU, stock, batches and prices. Nothing else changes.',
        bn: 'এটি আবার একক প্রোডাক্ট হবে, নিজের SKU, মজুদ, ব্যাচ ও দাম সহ। আর কিছু বদলাবে না।',
    },

    // ── The product list ────────────────────────────────────────────────
    'ipf.expand': { en: 'Show sizes', bn: 'সাইজগুলো দেখুন' },
    'ipf.collapse': { en: 'Hide sizes', bn: 'সাইজগুলো লুকান' },

    // ── The price screen ────────────────────────────────────────────────
    'ipf.price_filter': { en: 'All families', bn: 'সব ফ্যামিলি' },
    'ipf.set': { en: 'Set', bn: 'বসান' },
    'ipf.price_grid_title': { en: '{name}: every size, today', bn: '{name}: সব সাইজ, আজ' },

    // ── Reports ─────────────────────────────────────────────────────────
    'ipf.group_by_family': { en: 'Group sizes by family', bn: 'সাইজগুলো ফ্যামিলি ধরে দেখান' },
    'ipf.group_by_family_hint': {
        en: 'Each family shows as one row with its sizes added up. Single products stay as they are.',
        bn: 'প্রতিটি ফ্যামিলি একটি লাইনে, সাইজগুলো যোগ করে দেখাবে। একক প্রোডাক্ট যেমন আছে তেমন থাকবে।',
    },
} satisfies Record<string, Entry>;
