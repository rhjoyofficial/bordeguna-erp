import type { Entry } from './entry';

/**
 * Joy, 26 Oct 2026: a phone or a name typed on a selling screen finds the
 * customer and fills the form (components/sales/customer-lookup.tsx).
 */
export const customerLookup = {
    'clk.label': { en: 'Find by phone or name', bn: 'ফোন বা নাম দিয়ে খুঁজুন' },
    'clk.placeholder': { en: 'Phone or name', bn: 'ফোন বা নাম' },
    'clk.found': { en: 'Existing customer found: {name} ({code})', bn: 'আগের ক্রেতা মিলেছে: {name} ({code})' },
    'clk.found_no_code': { en: 'Existing customer found: {name}', bn: 'আগের ক্রেতা মিলেছে: {name}' },
    'clk.undo': { en: 'Undo', bn: 'বাতিল করুন' },
    'clk.new': { en: 'Not them: a new customer', bn: 'ইনি নন: নতুন ক্রেতা' },
    'clk.same_phone': { en: '{count} customers share this phone. Pick one:', bn: 'এই ফোনে {count} জন ক্রেতা আছেন। একজন বেছে নিন:' },
    'clk.pick': { en: 'Pick a customer:', bn: 'একজন ক্রেতা বেছে নিন:' },
    'clk.none': { en: 'No customer with this phone or name.', bn: 'এই ফোন বা নামে কোনো ক্রেতা নেই।' },
    'clk.prices': { en: 'Prices: {group}', bn: 'দাম: {group}' },
    'clk.retail': { en: 'Retail', bn: 'খুচরা' },
    'clk.type': { en: 'Type: {type}', bn: 'ধরন: {type}' },
    'clk.failed': { en: 'Could not look the customer up. Pick from the list instead.', bn: 'ক্রেতা খোঁজা গেল না। তালিকা থেকে বেছে নিন।' },
} satisfies Record<string, Entry>;
