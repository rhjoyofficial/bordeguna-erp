import type { Entry } from './entry';

/**
 * The Bismillah greeting (11 Oct 2026): the popup's accessible name and hint,
 * and its switch in Settings > Appearance. The greeting's own three lines are
 * fixed text and never come through here.
 */
export const greeting = {
    'bismillah.dialog_label': { en: 'Bismillah greeting', bn: 'বিসমিল্লাহ শুভেচ্ছা' },
    'bismillah.close_hint': {
        en: 'Click anywhere or press any key to close. It also closes by itself.',
        bn: 'বন্ধ করতে যেকোনো জায়গায় ক্লিক করুন বা যেকোনো কী চাপুন। এটি নিজে থেকেও বন্ধ হয়।',
    },
    'bismillah.setting_heading': { en: 'Greeting', bn: 'শুভেচ্ছা' },
    'bismillah.setting_label': { en: 'Show Bismillah greeting', bn: 'বিসমিল্লাহ শুভেচ্ছা দেখান' },
    'bismillah.setting_hint': {
        en: 'On login and when you return after an hour away',
        bn: 'লগইন করার পর এবং এক ঘণ্টা দূরে থাকার পর ফিরে এলে',
    },
} satisfies Record<string, Entry>;
