import type { Entry } from './entry';

/**
 * Joy, 26 Oct 2026: a purchase from a firm that is also our customer is taken
 * off EVERYTHING they owe us (our advance, then their open invoices by
 * set-off), not only the advance (components/purchasing/take-off-what-they-owe).
 */
export const takeOff = {
    'tko.title': { en: 'Take it off what they owe us', bn: 'তাদের কাছে আমাদের পাওনা থেকে কাটুন' },
    'tko.hint': {
        en: '{name} is also our customer. One amount comes off this bill: our advance with them first, then their open sales invoices, oldest first.',
        bn: '{name} আমাদের ক্রেতাও। এই বিল থেকে একটি অঙ্ক কাটা হবে: প্রথমে তাদের কাছে আমাদের অগ্রিম, তারপর তাদের খোলা বিক্রয় চালান, পুরোনোটা আগে।',
    },
    'tko.use': { en: 'Take this purchase off what they owe us', bn: 'এই কেনা তাদের পাওনা থেকে কাটুন' },
    'tko.our_advance': { en: 'Our advance with them', bn: 'তাদের কাছে আমাদের অগ্রিম' },
    'tko.their_invoices': { en: 'Their open invoices', bn: 'তাদের খোলা চালান' },
    'tko.none': { en: 'None', bn: 'নেই' },
    'tko.open': { en: '{amount} open', bn: '{amount} খোলা' },
    'tko.total': { en: 'They owe us in all: {amount}', bn: 'মোট পাওনা: {amount}' },
    'tko.amount': { en: 'Amount to take off', bn: 'কাটার অঙ্ক' },
    'tko.split': { en: 'It comes off like this', bn: 'যেভাবে কাটা হবে' },
    'tko.from_advance': { en: 'From our advance {number}: {amount}', bn: 'আমাদের অগ্রিম {number} থেকে: {amount}' },
    'tko.from_invoice': { en: 'From invoice {number}: {amount}', bn: 'চালান {number} থেকে: {amount}' },
    'tko.too_much_total': { en: 'They owe us only {amount} in all.', bn: 'তাদের কাছে মোট পাওনা মাত্র {amount}।' },
    'tko.too_much_bill': { en: 'This bill comes to only {amount}.', bn: 'এই বিল মাত্র {amount}।' },
    'tko.posts_now': {
        en: 'The part off their invoices is a set-off; you may approve it, so it posts with the purchase (kept in the approval history as approved by you).',
        bn: 'চালান থেকে কাটা অংশটি একটি সমন্বয় (সেট-অফ); আপনি অনুমোদন দিতে পারেন, তাই কেনার সঙ্গেই হিসাবে উঠবে (অনুমোদনের ইতিহাসে আপনার নামে থাকবে)।',
    },
    'tko.waits': {
        en: 'The part off their invoices is a set-off and waits for the Owner or Finance to approve it. Until then the bill still owes that part.',
        bn: 'চালান থেকে কাটা অংশটি একটি সমন্বয় (সেট-অফ), মালিক বা হিসাব বিভাগের অনুমোদনের অপেক্ষায় থাকবে। ততক্ষণ বিলে ওই অংশ বাকি থাকবে।',
    },
    'tko.no_right': {
        en: 'Taking it off their invoices is a set-off, which your role may not raise. Only our advance can come off here.',
        bn: 'চালান থেকে কাটা একটি সমন্বয় (সেট-অফ), যা আপনার ভূমিকায় করা যায় না। এখানে শুধু আমাদের অগ্রিম কাটা যাবে।',
    },
    'tko.pick_instead': { en: 'Pick advances one by one instead', bn: 'বরং অগ্রিম একটি একটি করে বেছে নিন' },
    'tko.after': { en: 'Owed to the supplier after this: {amount}', bn: 'এরপর সরবরাহকারীর পাওনা: {amount}' },
    'tko.result_title': { en: 'Taken off what they owe us', bn: 'তাদের পাওনা থেকে কাটা হয়েছে' },
    'tko.result_advance': { en: '{amount} from our advance {number}', bn: 'আমাদের অগ্রিম {number} থেকে {amount}' },
    'tko.result_set_off': { en: '{amount} off {invoices} by set-off {number}', bn: 'সমন্বয় {number} দিয়ে {invoices} থেকে {amount}' },
    'tko.state_posted': { en: 'posted', bn: 'হিসাবে উঠেছে' },
    'tko.state_pending': { en: 'waiting for approval: the bill still owes it until then', bn: 'অনুমোদনের অপেক্ষায়: ততক্ষণ বিলে বাকি' },
    'tko.state_rejected': { en: 'rejected: nothing came off', bn: 'বাতিল: কিছু কাটা হয়নি' },
    'tko.state_reversed': { en: 'reversed', bn: 'উল্টানো হয়েছে' },
} satisfies Record<string, Entry>;
