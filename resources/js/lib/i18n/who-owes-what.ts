import type { Entry } from './entry';

/**
 * Joy, 26 Oct 2026: every customer, supplier and courier on one page, with
 * what they owe us, what we owe them and the net (pages/accounting/who-owes-what).
 */
export const whoOwesWhat = {
    'wow.title': { en: 'Who owes what', bn: 'কে কত পাবে, কে কত দেবে' },
    'wow.intro': {
        en: 'Every customer, supplier and courier on one line each. A firm that is both our customer and our supplier is one line with both sides and one net. Today, from the books.',
        bn: 'প্রতিটি ক্রেতা, সরবরাহকারী আর কুরিয়ার এক লাইনে। যে একই সঙ্গে ক্রেতা ও সরবরাহকারী, তার দুই দিক এক লাইনে, একটি মোট। আজকের খাতা থেকে।',
    },
    'wow.total_owe_us': { en: 'They owe us', bn: 'তারা আমাদের দেবে' },
    'wow.total_we_owe': { en: 'We owe them', bn: 'আমরা তাদের দেব' },
    'wow.total_net': { en: 'Net', bn: 'মোট হিসাব' },
    'wow.parties': { en: '{count} parties', bn: '{count} জন' },
    'wow.side': { en: 'Show', bn: 'দেখান' },
    'wow.side_all': { en: 'Everyone with a balance', bn: 'যাদের হিসাব খোলা' },
    'wow.side_owe_us': { en: 'They owe us', bn: 'যারা আমাদের দেবে' },
    'wow.side_we_owe': { en: 'We owe them', bn: 'যাদের আমরা দেব' },
    'wow.kind': { en: 'Kind', bn: 'ধরন' },
    'wow.kind_all': { en: 'All kinds', bn: 'সব ধরন' },
    'wow.kind_customer': { en: 'Customer', bn: 'ক্রেতা' },
    'wow.kind_supplier': { en: 'Supplier', bn: 'সরবরাহকারী' },
    'wow.kind_both': { en: 'Customer and supplier', bn: 'ক্রেতা ও সরবরাহকারী' },
    'wow.kind_courier': { en: 'Courier', bn: 'কুরিয়ার' },
    'wow.search': { en: 'Name or phone', bn: 'নাম বা ফোন' },
    'wow.download': { en: 'Download (Excel)', bn: 'ডাউনলোড (Excel)' },
    'wow.col_name': { en: 'Name', bn: 'নাম' },
    'wow.col_they_owe': { en: 'They owe us (invoices)', bn: 'তারা দেবে (ইনভয়েস)' },
    'wow.col_our_advance': { en: 'Our advance with them', bn: 'তাদের কাছে আমাদের অগ্রিম' },
    'wow.col_we_owe': { en: 'We owe them (bills)', bn: 'আমরা দেব (বিল)' },
    'wow.col_their_advance': { en: 'Their advance with us', bn: 'আমাদের কাছে তাদের অগ্রিম' },
    'wow.col_net': { en: 'Net', bn: 'মোট' },
    'wow.net_us': { en: 'They owe us {amount}', bn: 'তারা দেবে {amount}' },
    'wow.net_them': { en: 'We owe them {amount}', bn: 'আমরা দেব {amount}' },
    'wow.net_even': { en: 'Even', bn: 'সমান' },
    'wow.empty': { en: 'Nobody matches.', bn: 'কেউ মেলেনি।' },
    'wow.shown': { en: 'Showing {shown} of {total}', bn: '{total}-এর মধ্যে {shown}' },
    'wow.statement': { en: 'Statement', bn: 'হিসাব বিবরণী' },
    'wow.tile_label': { en: 'Owes now, both sides together', bn: 'এখন দেনা, দুই দিক মিলিয়ে' },
    'wow.tile_invoices': { en: 'Invoices only: {amount}', bn: 'শুধু ইনভয়েস: {amount}' },
    'wow.tile_bills': { en: 'Bills only: {amount}', bn: 'শুধু বিল: {amount}' },
    'wow.tile_link': { en: 'Everyone: Who owes what', bn: 'সবার হিসাব: কে কত পাবে, কে কত দেবে' },
} satisfies Record<string, Entry>;
