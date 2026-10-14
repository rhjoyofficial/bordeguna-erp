import type { Entry } from './entry';

/**
 * HR and payroll rules approved on 14 Oct 2026 (audit items #16 to #23): the
 * salary revision cycle-start offer, the frozen-attendance notice and the
 * self-approval reason and mark. Dates and amounts never come through here.
 */
export const hrRules = {
    'hr_rules.cycle_start_hint': {
        en: 'A salary change starts on a payroll cycle start (the 24th).',
        bn: 'বেতনের পরিবর্তন শুরু হয় বেতন চক্রের শুরুর দিনে (২৪ তারিখ)।',
    },
    'hr_rules.use_suggested': { en: 'Use {date} instead', bn: 'এর বদলে {date} ব্যবহার করুন' },
    'hr_rules.suggested_confirm': {
        en: 'Effective date changed to {date} (you asked for {requested}). Press Propose to confirm.',
        bn: 'কার্যকর তারিখ {date} করা হয়েছে (আপনি চেয়েছিলেন {requested})। নিশ্চিত করতে প্রস্তাব করুন চাপুন।',
    },
    'hr_rules.asked_for': { en: 'asked for {date}', bn: 'চাওয়া হয়েছিল {date}' },
} satisfies Record<string, Entry>;
