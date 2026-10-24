import type { Entry } from './entry';

/**
 * Initial plan B1 + B2 (25 Oct 2026): the multi-account payment grid (R57) and
 * the purchase screen polish (R40, R49, R51, R54, R56, R58). Numbers, dates and
 * money never translate.
 */
export const purchasePayGrid = {
    'ipp.split.add': { en: 'Add another account', bn: 'আরেকটি অ্যাকাউন্ট যোগ করুন' },
    'ipp.split.account': { en: 'Also paid from', bn: 'এখান থেকেও দেওয়া' },
    'ipp.split.amount': { en: 'Amount', bn: 'টাকা' },
    'ipp.split.remove': { en: 'Remove this account', bn: 'এই অ্যাকাউন্ট সরান' },
    'ipp.split.pick': { en: 'Pick an account', bn: 'অ্যাকাউন্ট বাছুন' },
    'ipp.split.rest': { en: '{account} pays the rest: {amount}', bn: '{account} বাকিটা দেবে: {amount}' },
    'ipp.split.rest_unnamed': { en: 'The first account pays the rest: {amount}', bn: 'প্রথম অ্যাকাউন্ট বাকিটা দেবে: {amount}' },
    'ipp.split.too_much': {
        en: 'The other accounts add up to {amount}, which leaves nothing for the first account. Lower them.',
        bn: 'অন্য অ্যাকাউন্টগুলো মিলে {amount}, প্রথম অ্যাকাউন্টের জন্য কিছু থাকে না। কমান।',
    },
    'ipp.split.hint': {
        en: 'Paid from several accounts? Add each one with its amount. Whatever is not paid stays owed to the supplier.',
        bn: 'কয়েকটি অ্যাকাউন্ট থেকে দেওয়া? প্রতিটি টাকাসহ যোগ করুন। যা দেওয়া হয়নি তা সরবরাহকারীর পাওনা থাকে।',
    },
    'ipp.paid_from_lines': { en: 'Paid from', bn: 'যেখান থেকে দেওয়া' },
    'ipp.totals.quantity': { en: 'Total quantity', bn: 'মোট পরিমাণ' },
    'ipp.totals.weight': { en: 'Total weight', bn: 'মোট ওজন' },
    'ipp.totals.unweighed': { en: '({count} line(s) have no weight on the product)', bn: '({count}টি লাইনের পণ্যে ওজন দেওয়া নেই)' },
    'ipp.later.add': { en: "Supplier's later charge", bn: 'সরবরাহকারীর পরের চার্জ' },
    'ipp.later.title': { en: 'A charge the supplier claims later', bn: 'সরবরাহকারী পরে যে চার্জ দাবি করেছে' },
    'ipp.later.intro': {
        en: 'Carriage, labour or another cost the supplier adds after the goods arrived. It is owed to this supplier and goes into the cost of this delivery as a landed cost.',
        bn: 'মাল আসার পরে সরবরাহকারী যে পরিবহন, শ্রমিক বা অন্য খরচ যোগ করে। এটি এই সরবরাহকারীর পাওনা, আর ল্যান্ডেড কস্ট হিসেবে এই মালের খরচে যায়।',
    },
    'ipp.later.date': { en: 'Date of the charge', bn: 'চার্জের তারিখ' },
    'ipp.later.type': { en: 'What for', bn: 'কিসের জন্য' },
    'ipp.later.what': { en: 'Details', bn: 'বিস্তারিত' },
    'ipp.later.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'ipp.later.save': { en: 'Save the charge', bn: 'চার্জ সংরক্ষণ করুন' },
    'ipp.adv.title': { en: 'Allocate advance', bn: 'অগ্রিম সমন্বয়' },
    'ipp.adv.hint': {
        en: 'Money already paid to this supplier in advance. Type how much of it this bill uses; it comes off what the bill owes before any payment.',
        bn: 'এই সরবরাহকারীকে আগেই দেওয়া অগ্রিম। এই বিলে কত লাগবে লিখুন; পেমেন্টের আগে বিলের পাওনা থেকে কমে যাবে।',
    },
    'ipp.adv.open': { en: '{amount} open', bn: '{amount} বাকি' },
    'ipp.adv.take': { en: 'Amount of {number} to use', bn: '{number} থেকে কত নেবেন' },
    'ipp.adv.use': { en: 'Use', bn: 'ব্যবহার' },
    'ipp.adv.total': { en: 'Advances taken off this bill: {amount}', bn: 'এই বিল থেকে অগ্রিম কমানো: {amount}' },
    'ipp.adv.too_much': { en: 'That is more than the bill owes.', bn: 'এটি বিলের পাওনার চেয়ে বেশি।' },
    'ipp.files.title': { en: 'Files', bn: 'ফাইল' },
    'ipp.files.none': {
        en: 'No files yet: a memo photo, a courier receipt or a labour slip can go here.',
        bn: 'এখনও কোনো ফাইল নেই: মেমোর ছবি, কুরিয়ার রসিদ বা শ্রমিকের স্লিপ এখানে রাখা যায়।',
    },
    'ipp.files.attach': { en: 'Attach a file', bn: 'ফাইল যুক্ত করুন' },
    'ipp.files.uploading': { en: 'Uploading...', bn: 'আপলোড হচ্ছে...' },
    'ipp.files.remove': { en: 'Remove', bn: 'সরান' },
    'ipp.employee': { en: 'Purchasing employee', bn: 'ক্রয়কারী কর্মী' },
    'ipp.employee_pick': { en: 'Pick an employee', bn: 'কর্মী বাছুন' },
    'ipp.payee_name': { en: 'Paid to (name)', bn: 'যাকে দেওয়া (নাম)' },
    'ipp.payee_name_hint': { en: 'Truck owner, restaurant, labourer', bn: 'ট্রাক মালিক, রেস্তোরাঁ, শ্রমিক' },
} satisfies Record<string, Entry>;
