import type { Entry } from './entry';

/**
 * SMS and email sends (CRM-004, 14 Oct 2026): the controls on a send that died
 * part-way. A campaign's own name and text are data somebody typed and never
 * come through here.
 */
export const campaigns = {
    'campaigns.stuck.note': {
        en: 'Stuck: no progress for 15 minutes. {unsent} not sent yet.',
        bn: 'আটকে আছে: 15 মিনিট কোনো অগ্রগতি নেই। এখনও {unsent} জনকে পাঠানো হয়নি।',
    },
    'campaigns.stuck.resume': { en: 'Finish sending', bn: 'বাকিদের পাঠান' },
    'campaigns.stuck.resume_confirm': {
        en: 'Send only to the people this campaign has not tried yet? Nobody already tried is messaged again.',
        bn: 'শুধু যাদের এখনও চেষ্টা করা হয়নি তাদের পাঠাবেন? যাদের আগে চেষ্টা করা হয়েছে তারা আবার পাবে না।',
    },
    'campaigns.stuck.stop': { en: 'Mark stopped', bn: 'বন্ধ হিসেবে চিহ্নিত করুন' },
    'campaigns.stuck.reason': { en: 'Why it stopped', bn: 'কেন বন্ধ হলো' },
    'campaigns.stuck.reason_placeholder': { en: 'For example: the gateway went down', bn: 'যেমন: গেটওয়ে বন্ধ ছিল' },
    'campaigns.stuck.stop_confirm': { en: 'Stop it', bn: 'বন্ধ করুন' },
    'campaigns.stuck.cancel': { en: 'Cancel', bn: 'বাতিল' },
    // The do-not-contact list takes a new entry in a popup since 14 Oct 2026.
    'campaigns.optout.add_number': { en: 'Add a number', bn: 'নম্বর যোগ করুন' },
    'campaigns.optout.add_address': { en: 'Add an address', bn: 'ঠিকানা যোগ করুন' },
    'campaigns.optout.cancel': { en: 'Cancel', bn: 'বাতিল' },
} satisfies Record<string, Entry>;
