import type { Entry } from './entry';

/**
 * The work schedule (14 Oct 2026, Joy's "Deep Focus Time Policy"): the
 * Today's rhythm page, the alert that shows when a slot starts, the two
 * switches in Settings > Appearance, and Settings > Work schedule. A slot's
 * own title and subtitle are data the managers typed and never come through
 * here.
 */
export const schedule = {
    'schedule.nav': { en: "Today's rhythm", bn: 'আজকের ছন্দ' },
    'schedule.title': { en: 'Deep Focus Time Policy', bn: 'গভীর মনোযোগের সময় নীতি' },
    'schedule.tagline': { en: 'Unlock Your Potential, Deep Focus, Real Growth', bn: 'সম্ভাবনা জাগান, গভীর মনোযোগ, সত্যিকারের উন্নতি' },
    'schedule.today': { en: 'Today, {day}', bn: 'আজ, {day}' },
    'schedule.now': { en: 'Now', bn: 'এখন' },
    'schedule.off_day': {
        en: 'No work slots today. Enjoy the day off.',
        bn: 'আজ কাজের কোনো সময় নেই। ছুটির দিনটি উপভোগ করুন।',
    },
    'schedule.empty': { en: 'No work slots have been set up yet.', bn: 'এখনও কাজের কোনো সময় ঠিক করা হয়নি।' },
    'schedule.time_range': { en: '{start} to {end}', bn: '{start} থেকে {end}' },
    'schedule.alert_label': { en: 'Work schedule', bn: 'কাজের সময়সূচি' },
    'schedule.close': { en: 'Close the alert', bn: 'সতর্কবার্তা বন্ধ করুন' },

    'schedule.kind.focus': { en: 'Focus', bn: 'মনোযোগ' },
    'schedule.kind.break': { en: 'Break', bn: 'বিরতি' },
    'schedule.kind.meal': { en: 'Meal', bn: 'খাবার' },
    'schedule.kind.prayer': { en: 'Prayer', bn: 'নামাজ' },
    'schedule.kind.wrap': { en: 'Wrap up', bn: 'গুছিয়ে নেওয়া' },

    'schedule.day.0': { en: 'Sunday', bn: 'রবিবার' },
    'schedule.day.1': { en: 'Monday', bn: 'সোমবার' },
    'schedule.day.2': { en: 'Tuesday', bn: 'মঙ্গলবার' },
    'schedule.day.3': { en: 'Wednesday', bn: 'বুধবার' },
    'schedule.day.4': { en: 'Thursday', bn: 'বৃহস্পতিবার' },
    'schedule.day.5': { en: 'Friday', bn: 'শুক্রবার' },
    'schedule.day.6': { en: 'Saturday', bn: 'শনিবার' },
    'schedule.day_short.0': { en: 'Sun', bn: 'রবি' },
    'schedule.day_short.1': { en: 'Mon', bn: 'সোম' },
    'schedule.day_short.2': { en: 'Tue', bn: 'মঙ্গল' },
    'schedule.day_short.3': { en: 'Wed', bn: 'বুধ' },
    'schedule.day_short.4': { en: 'Thu', bn: 'বৃহঃ' },
    'schedule.day_short.5': { en: 'Fri', bn: 'শুক্র' },
    'schedule.day_short.6': { en: 'Sat', bn: 'শনি' },

    // ── Settings > Appearance ─────────────────────────────────────────────
    'schedule.setting_heading': { en: 'Work schedule alerts', bn: 'কাজের সময়সূচির সতর্কবার্তা' },
    'schedule.setting_label': { en: 'Show work schedule alerts', bn: 'কাজের সময়সূচির সতর্কবার্তা দেখান' },
    'schedule.setting_hint': {
        en: 'When a slot starts, the edge of the screen glows in its colour and a short note names it, then fades away.',
        bn: 'কোনো সময় শুরু হলে পর্দার কিনারা তার রঙে জ্বলে ওঠে এবং একটি ছোট নোটে নাম দেখায়, তারপর মিলিয়ে যায়।',
    },
    'schedule.sound_label': { en: 'Play a sound with work schedule alerts', bn: 'কাজের সময়সূচির সতর্কবার্তার সাথে শব্দ বাজান' },
    'schedule.sound_hint': {
        en: 'Muting the sound never hides the alert. Browsers stay silent until your first click after a page load.',
        bn: 'শব্দ বন্ধ করলেও সতর্কবার্তা দেখা যাবে। পেজ লোডের পর প্রথম ক্লিকের আগে ব্রাউজার শব্দ করে না।',
    },
    'schedule.global_off': {
        en: 'The alerts are switched off for everybody in Settings > Work schedule.',
        bn: 'সেটিংস > কাজের সময়সূচি থেকে সবার জন্য সতর্কবার্তা বন্ধ করা আছে।',
    },

    // ── Settings > Work schedule ──────────────────────────────────────────
    'schedule.settings.title': { en: 'Work schedule', bn: 'কাজের সময়সূচি' },
    'schedule.settings.description': {
        en: 'The slots of the working day, for the whole team. When a slot starts, everybody with the ERP open sees a short alert.',
        bn: 'পুরো দলের জন্য কাজের দিনের সময়গুলো। কোনো সময় শুরু হলে ইআরপি খোলা থাকা সবাই একটি ছোট সতর্কবার্তা দেখবেন।',
    },
    'schedule.settings.view_rhythm': { en: "See today's rhythm", bn: 'আজকের ছন্দ দেখুন' },
    'schedule.settings.general_heading': { en: 'Alerts', bn: 'সতর্কবার্তা' },
    'schedule.settings.alert_seconds': { en: 'Seconds an alert stays (3 to 30)', bn: 'সতর্কবার্তা কত সেকেন্ড থাকবে (3 থেকে 30)' },
    'schedule.settings.enabled': { en: 'Alerts on for everybody', bn: 'সবার জন্য সতর্কবার্তা চালু' },
    'schedule.settings.enabled_hint': {
        en: 'Off hides the alerts for everybody. Each person can also switch them off for themselves in Appearance.',
        bn: 'বন্ধ করলে সবার জন্য সতর্কবার্তা লুকানো থাকবে। প্রত্যেকে নিজের জন্যও চেহারা সেটিংসে বন্ধ করতে পারেন।',
    },
    'schedule.settings.save_general': { en: 'Save alert settings', bn: 'সতর্কবার্তার সেটিংস সংরক্ষণ' },
    'schedule.settings.slots_heading': { en: 'Slots', bn: 'সময়গুলো' },
    'schedule.settings.new': { en: 'New slot', bn: 'নতুন সময়' },
    'schedule.settings.edit': { en: 'Edit', bn: 'সম্পাদনা' },
    'schedule.settings.edit_title': { en: 'Edit slot', bn: 'সময় সম্পাদনা' },
    'schedule.settings.form_hint': {
        en: 'Times are Dhaka time. Two active slots may not overlap on a shared day.',
        bn: 'সময় ঢাকার সময় অনুযায়ী। একই দিনে দুটি চালু সময় একটির ওপর আরেকটি পড়তে পারবে না।',
    },
    'schedule.settings.pause': { en: 'Pause', bn: 'থামান' },
    'schedule.settings.resume': { en: 'Resume', bn: 'আবার চালু' },
    'schedule.settings.delete': { en: 'Delete', bn: 'মুছুন' },
    'schedule.settings.delete_confirm': { en: 'Delete this slot?', bn: 'এই সময়টি মুছে ফেলবেন?' },
    'schedule.settings.active': { en: 'Active', bn: 'চালু' },
    'schedule.settings.paused': { en: 'Paused', bn: 'থামানো' },
    'schedule.settings.none': { en: 'No slots yet.', bn: 'এখনও কোনো সময় নেই।' },
    'schedule.settings.save': { en: 'Save slot', bn: 'সময় সংরক্ষণ' },
    'schedule.settings.saving': { en: 'Saving...', bn: 'সংরক্ষণ হচ্ছে...' },
    'schedule.settings.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'schedule.field.starts_at': { en: 'Starts', bn: 'শুরু' },
    'schedule.field.ends_at': { en: 'Ends', bn: 'শেষ' },
    'schedule.field.title': { en: 'Title', bn: 'শিরোনাম' },
    'schedule.field.subtitle': { en: 'What it is for (optional)', bn: 'কীসের জন্য (ঐচ্ছিক)' },
    'schedule.field.kind': { en: 'Kind', bn: 'ধরন' },
    'schedule.field.weekdays': { en: 'Days', bn: 'দিন' },
    'schedule.field.active': { en: 'Active', bn: 'চালু' },
    'schedule.field.sort_order': { en: 'Order (same start time)', bn: 'ক্রম (একই শুরুর সময়ে)' },

    // ── Settings > Sounds: the event this feature adds ─────────────────────
    'sounds.event.schedule': { en: 'Work schedule (a slot starts)', bn: 'কাজের সময়সূচি (কোনো সময় শুরু হলে)' },
} satisfies Record<string, Entry>;
