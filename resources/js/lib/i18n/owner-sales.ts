import type { Entry } from './entry';

/**
 * ⭐ D-B (Joy, 18 Oct 2026, "Cost price"): the proprietor buys as a customer of
 * his own at the FIFO batch cost. Settings > Company makes the account; the
 * counter and orders price it. Numbers, dates and money never translate.
 */
export const ownerSales = {
    'owner_sale.account_title': { en: "Proprietor's customer account", bn: 'স্বত্বাধিকারীর গ্রাহক অ্যাকাউন্ট' },
    'owner_sale.account_hint': {
        en: 'When the proprietor buys and pays (now or later), sell to this account on the counter or an order. The price is the cost of the FIFO batch the sale takes (owner price): no cap, a normal receivable, settled by a normal customer receipt.',
        bn: 'স্বত্বাধিকারী যখন কিনে টাকা দেন (এখন বা পরে), কাউন্টারে বা অর্ডারে এই অ্যাকাউন্টে বিক্রি করুন। দাম হলো বিক্রিতে যে FIFO ব্যাচ যায় তার খরচ (মালিকের দাম): কোনো সীমা নেই, সাধারণ পাওনা, সাধারণ গ্রাহক রসিদে পরিশোধ।',
    },
    'owner_sale.create': { en: "Create {name}'s customer account", bn: '{name}-এর গ্রাহক অ্যাকাউন্ট তৈরি করুন' },
    'owner_sale.exists': { en: 'Customer account {code}: {name}', bn: 'গ্রাহক অ্যাকাউন্ট {code}: {name}' },
    'owner_sale.no_proprietor': {
        en: 'Name the proprietor above and save, then create his customer account.',
        bn: 'উপরে স্বত্বাধিকারী নির্ধারণ করে সংরক্ষণ করুন, তারপর তাঁর গ্রাহক অ্যাকাউন্ট তৈরি করুন।',
    },
} satisfies Record<string, Entry>;
