import type { Entry } from './entry';

/**
 * ⭐ Stock reservation (Joy's initial plan B17, R81, Q4 = A, 25 Oct 2026): a
 * confirmed order holds its stock, so others see only what is Available to
 * sell. Latin digits; quantities never translate.
 */
export const stockReservation = {
    'rsv.tab': { en: 'Reserved stock', bn: 'সংরক্ষিত মজুদ' },
    'rsv.title': { en: 'Reserved stock', bn: 'সংরক্ষিত মজুদ' },
    'rsv.intro': {
        en: 'What confirmed orders and approved requests hold, per product. Others can sell only what is available.',
        bn: 'নিশ্চিত অর্ডার ও অনুমোদিত অনুরোধ প্রতিটি পণ্যের কতটা ধরে রেখেছে। অন্যরা শুধু যা পাওয়া যায় তা-ই বিক্রি করতে পারবেন।',
    },
    'rsv.on_hand': { en: 'On hand', bn: 'হাতে আছে' },
    'rsv.reserved': { en: 'Reserved', bn: 'সংরক্ষিত' },
    'rsv.available': { en: 'Available to sell', bn: 'বিক্রির জন্য আছে' },
    'rsv.available_short': { en: 'Available', bn: 'আছে' },
    'rsv.held_by': { en: 'Held for', bn: 'যার জন্য রাখা' },
    'rsv.customer': { en: 'Customer', bn: 'ক্রেতা' },
    'rsv.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'rsv.since': { en: 'Since', bn: 'কবে থেকে' },
    'rsv.by': { en: 'By', bn: 'কে' },
    'rsv.none': { en: 'Nothing is reserved right now.', bn: 'এখন কিছুই সংরক্ষিত নেই।' },
    'rsv.release': { en: 'Release', bn: 'ছেড়ে দিন' },
    'rsv.release_title': { en: 'Release the hold of {document}', bn: '{document} এর সংরক্ষণ ছেড়ে দিন' },
    'rsv.release_text': {
        en: 'The stock becomes available to sell again. The order stays confirmed and can still be delivered.',
        bn: 'মজুদ আবার বিক্রির জন্য পাওয়া যাবে। অর্ডারটি নিশ্চিতই থাকবে, ডেলিভারিও করা যাবে।',
    },
    'rsv.request_note': { en: 'Freed when the request is cancelled', bn: 'অনুরোধ বাতিল হলে ছাড়া পাবে' },
    'rsv.backorders': { en: 'Backorders', bn: 'বাকি অর্ডার' },
    'rsv.backorders_intro': {
        en: 'Confirmed orders that hold less than they still want. The shortfall is not reserved.',
        bn: 'নিশ্চিত অর্ডার, যেগুলো যতটা চায় তার চেয়ে কম ধরে রেখেছে। ঘাটতি অংশ সংরক্ষিত নয়।',
    },
    'rsv.order': { en: 'Order', bn: 'অর্ডার' },
    'rsv.product': { en: 'Product', bn: 'পণ্য' },
    'rsv.warehouse': { en: 'Warehouse', bn: 'গুদাম' },
    'rsv.wanted': { en: 'Wanted', bn: 'চাই' },
    'rsv.held': { en: 'Held', bn: 'রাখা আছে' },
    'rsv.short': { en: 'Short', bn: 'ঘাটতি' },
    'rsv.reason': { en: 'Reason', bn: 'কারণ' },
    // ── On the order page and the counter ──────────────────────────────
    'rsv.backorder_confirm': { en: 'Confirm as backorder', bn: 'বাকি অর্ডার হিসেবে নিশ্চিত করুন' },
    'rsv.backorder_title': { en: 'Confirm {number} short of stock', bn: 'মজুদ কম থাকা অবস্থায় {number} নিশ্চিত করুন' },
    'rsv.backorder_label': { en: 'Backorder reason', bn: 'বাকি অর্ডারের কারণ' },
    'rsv.backorder_hint': {
        en: 'Only if this order is short of stock: what is there is held, the rest waits.',
        bn: 'শুধু মজুদ কম হলে: যা আছে তা রাখা হবে, বাকিটা অপেক্ষায় থাকবে।',
    },
    'rsv.take_label': { en: 'Reason to take reserved stock', bn: 'সংরক্ষিত মজুদ নেওয়ার কারণ' },
    'rsv.take_hint': {
        en: 'Manager or Owner only. The other order keeps its claim and waits for stock.',
        bn: 'শুধু ম্যানেজার বা মালিক। অন্য অর্ডারটির দাবি থাকবে, মজুদের অপেক্ষায় থাকবে।',
    },
    'rsv.line_available': { en: 'Available {quantity}', bn: 'আছে {quantity}' },
    'rsv.line_short': { en: 'Only {quantity} available', bn: 'মাত্র {quantity} আছে' },
    'rsv.line_reserved': { en: '{quantity} reserved', bn: '{quantity} সংরক্ষিত' },
    'rsv.order_holds': { en: 'Stock held for this order', bn: 'এই অর্ডারের জন্য রাখা মজুদ' },
} satisfies Record<string, Entry>;
