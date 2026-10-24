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
} satisfies Record<string, Entry>;
