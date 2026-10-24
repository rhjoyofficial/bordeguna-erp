import type { Entry } from './entry';

/**
 * ⭐ B24 (24 Oct 2026, R113; Joy's Q5 = A): Delete, Switch off and Reactivate on
 * every master list row (products, raw materials, categories, brands, units,
 * warehouses, bins, and the lists that follow), and their Recycle bin labels.
 */
export const masters = {
    'master.actions': { en: 'Actions', bn: 'কাজ' },
    'master.edit': { en: 'Edit', bn: 'এডিট' },
    'master.delete': { en: 'Delete', bn: 'মুছুন' },
    'master.switch_off': { en: 'Switch off', bn: 'বন্ধ করুন' },
    'master.reactivate': { en: 'Reactivate', bn: 'আবার চালু করুন' },
    'master.delete_title': { en: 'Move {name} to the Recycle bin?', bn: '{name} রিসাইকেল বিনে পাঠাবেন?' },
    'master.delete_help': {
        en: 'Nothing has used it yet, so it goes to the Recycle bin. The Owner can restore it from Settings > Recycle bin.',
        bn: 'এটা এখনো কোথাও ব্যবহার হয়নি, তাই রিসাইকেল বিনে যাবে। মালিক সেটিংস > রিসাইকেল বিন থেকে ফিরিয়ে আনতে পারবেন।',
    },
    'master.switch_off_title': { en: 'Switch off {name}?', bn: '{name} বন্ধ করবেন?' },
    'master.switch_off_help': {
        en: 'It is used, so it stays for its history. Switched off, it is no longer offered for new entries. Reactivate brings it back.',
        bn: 'এটা ব্যবহার হয়েছে, তাই ইতিহাসের জন্য থেকে যাবে। বন্ধ করলে নতুন এন্ট্রিতে আর আসবে না। আবার চালু করলে ফিরে আসবে।',
    },
    'master.switched_off': { en: 'Switched off', bn: 'বন্ধ' },

    // Recycle bin type labels
    'master.bin.product': { en: 'Product', bn: 'পণ্য' },
    'master.bin.category': { en: 'Category', bn: 'ক্যাটাগরি' },
    'master.bin.brand': { en: 'Brand', bn: 'ব্র্যান্ড' },
    'master.bin.unit': { en: 'Unit', bn: 'একক' },
    'master.bin.warehouse': { en: 'Warehouse', bn: 'গুদাম' },
    'master.bin.bin': { en: 'Bin', bn: 'বিন (তাক)' },
    'master.bin.customer': { en: 'Customer', bn: 'কাস্টমার' },
    'master.bin.supplier': { en: 'Supplier', bn: 'সাপ্লায়ার' },
} satisfies Record<string, Entry>;
