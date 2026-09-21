import { usePage } from '@inertiajs/react';

/**
 * Bangla UI phase 1 (C-I18N-2/3).
 *
 * The dictionary carries BOTH languages side by side and the type requires
 * both, so a new string cannot ship half-translated - the compiler refuses.
 * English is the default and the fallback (logged-out pages, unset users).
 *
 * Digits, dates and money NEVER translate (Joy's explicit 31 Aug decision):
 * amounts must match bank statements and invoices, so only words go through
 * here. Bangla text is written in NFC per the standing normalisation rule.
 */
type Entry = { en: string; bn: string };

const dictionary = {
    // ── Shared ──────────────────────────────────────────────────────────
    'common.today': { en: 'Today', bn: 'আজ' },
    'common.date': { en: 'Date', bn: 'তারিখ' },
    'common.status': { en: 'Status', bn: 'অবস্থা' },
    'common.download': { en: 'Download', bn: 'ডাউনলোড' },
    'common.loading': { en: 'Loading...', bn: 'লোড হচ্ছে...' },
    'common.none_yet': { en: 'Nothing here yet.', bn: 'এখনও কিছু নেই।' },

    // ── The clock screen ────────────────────────────────────────────────
    'clock.title': { en: 'Clock in/out', bn: 'ক্লক-ইন/আউট' },
    'clock.clock_in': { en: 'Clock in', bn: 'ক্লক-ইন' },
    'clock.clock_out': { en: 'Clock out', bn: 'ক্লক-আউট' },
    'clock.recording': { en: 'Recording…', bn: 'রেকর্ড হচ্ছে…' },
    'clock.privacy_note': {
        en: 'Your location, accuracy, IP address and device details are recorded with each punch.',
        bn: 'প্রতিটি হাজিরার সাথে আপনার অবস্থান, নির্ভুলতা, আইপি ঠিকানা ও ডিভাইসের তথ্য রেকর্ড করা হয়।',
    },
    'clock.no_geolocation': {
        en: 'This browser cannot share location. Ask HR to record your attendance manually.',
        bn: 'এই ব্রাউজার অবস্থান জানাতে পারে না। এইচআর-কে বলুন আপনার হাজিরা হাতে লিখে রাখতে।',
    },
    'clock.location_denied': {
        en: 'Location permission was denied. Allow it in your browser, or ask HR to record your attendance manually.',
        bn: 'অবস্থানের অনুমতি দেওয়া হয়নি। ব্রাউজারে অনুমতি দিন, অথবা এইচআর-কে বলুন হাজিরা হাতে লিখে রাখতে।',
    },
    'clock.location_failed': {
        en: 'Could not read your location. Try again, or ask HR to record your attendance manually.',
        bn: 'আপনার অবস্থান পড়া যায়নি। আবার চেষ্টা করুন, অথবা এইচআর-কে বলুন হাজিরা হাতে লিখে রাখতে।',
    },
    'clock.not_switched_on': { en: 'Clocking in here is not switched on for you.', bn: 'এখান থেকে ক্লক-ইন করা আপনার জন্য চালু নেই।' },
    'clock.use_machine': {
        en: 'Use the punch machine at the office. If your work takes you away from the office, ask HR to switch on clocking in from a browser for you. Everything below still shows your own attendance.',
        bn: 'অফিসের পাঞ্চ মেশিন ব্যবহার করুন। কাজের জন্য অফিসের বাইরে থাকলে, ব্রাউজার থেকে ক্লক-ইন চালু করতে এইচআর-কে বলুন। নিচের সবকিছু আপনার নিজের হাজিরাই দেখায়।',
    },
    'clock.today': { en: 'Today', bn: 'আজ' },
    'clock.nothing_today': { en: 'Nothing recorded yet today.', bn: 'আজ এখনও কিছু রেকর্ড হয়নি।' },
    'clock.time': { en: 'Time', bn: 'সময়' },
    'clock.direction': { en: 'Direction', bn: 'দিক' },
    'clock.source': { en: 'Source', bn: 'উৎস' },
    'clock.flagged': { en: 'Flagged', bn: 'চিহ্নিত' },
    'clock.recorded': { en: 'Recorded', bn: 'রেকর্ড হয়েছে' },
    'clock.my_attendance': { en: 'My attendance', bn: 'আমার হাজিরা' },
    'clock.from': { en: 'From', bn: 'শুরু' },
    'clock.to': { en: 'To', bn: 'শেষ' },
    'clock.all_statuses': { en: 'All statuses', bn: 'সব অবস্থা' },
    'clock.date_order': { en: 'Date order', bn: 'তারিখের ক্রম' },
    'clock.newest_first': { en: 'Newest first', bn: 'নতুন আগে' },
    'clock.oldest_first': { en: 'Oldest first', bn: 'পুরনো আগে' },
    'clock.show': { en: 'Show', bn: 'দেখান' },
    'clock.days_attended': { en: 'Days attended', bn: 'উপস্থিত দিন' },
    'clock.present': { en: 'Present', bn: 'উপস্থিত' },
    'clock.late': { en: 'Late', bn: 'দেরি' },
    'clock.absent': { en: 'Absent', bn: 'অনুপস্থিত' },
    'clock.on_leave': { en: 'On leave', bn: 'ছুটিতে' },
    'clock.hours_worked': { en: 'Hours worked', bn: 'কাজের ঘণ্টা' },
    'clock.holds_payroll': {
        en: '{count} day(s) in this period still need a correction before payroll can include them. Use "Something wrong?" below.',
        bn: 'এই সময়ের {count} দিনের হাজিরা সংশোধন না হলে বেতনে ধরা যাবে না। নিচের "কিছু ভুল আছে?" ব্যবহার করুন।',
    },
    'clock.days': { en: 'Days', bn: 'দিনগুলো' },
    'clock.days_range': { en: 'from {from} to {to}', bn: '{from} থেকে {to}' },
    'clock.showing_only': {
        en: 'Showing {status} only. The totals above still cover every day in this period.',
        bn: 'শুধু {status} দেখানো হচ্ছে। উপরের হিসাব এই সময়ের সব দিন নিয়েই।',
    },
    'clock.show_all_statuses': { en: 'Show all statuses', bn: 'সব অবস্থা দেখান' },
    'clock.no_days_between': { en: 'No {status} days between {from} and {to}.', bn: '{from} থেকে {to} পর্যন্ত কোনো {status} দিন নেই।' },
    'clock.nothing_processed': { en: 'Nothing processed yet.', bn: 'এখনও কিছু প্রক্রিয়া হয়নি।' },
    'clock.in_out': { en: 'In / Out', bn: 'ইন / আউট' },
    'clock.something_wrong': { en: 'Something wrong?', bn: 'কিছু ভুল আছে?' },
    'clock.needs_fixing': { en: 'Needs fixing', bn: 'সংশোধন দরকার' },
    'clock.period_closed': { en: 'Period closed - ask HR', bn: 'মাস বন্ধ হয়ে গেছে - এইচআর-কে বলুন' },
    'clock.sort_by_date': { en: 'Sort by date', bn: 'তারিখ অনুযায়ী সাজান' },

    // ── The correction request dialog ───────────────────────────────────
    'correction.request': { en: 'Request correction', bn: 'সংশোধনের অনুরোধ' },
    'correction.title_for': { en: 'Request a correction for {date}', bn: '{date} তারিখের সংশোধনের অনুরোধ' },
    'correction.description': {
        en: 'Your manager reviews this first, then HR. Nothing changes until it is approved, and your original punches are never altered.',
        bn: 'প্রথমে আপনার ম্যানেজার, তারপর এইচআর এটি দেখবেন। অনুমোদনের আগে কিছুই বদলায় না, এবং আপনার আসল হাজিরা কখনও মোছা হয় না।',
    },
    'correction.what_is_wrong': { en: 'What is wrong', bn: 'কী ভুল হয়েছে' },
    'correction.correct_time': { en: 'Correct time', bn: 'সঠিক সময়' },
    'correction.direction': { en: 'Direction', bn: 'দিক' },
    'correction.unknown': { en: '- Unknown -', bn: '- অজানা -' },
    'correction.needs_hr': {
        en: 'Amending or disregarding a specific punch needs HR to pick it - ask them, or choose "Add a missing punch".',
        bn: 'নির্দিষ্ট কোনো হাজিরা বদলাতে বা বাদ দিতে এইচআর-কে সেটি বেছে নিতে হয় - তাদের বলুন, অথবা "Add a missing punch" বেছে নিন।',
    },
    'correction.reason': { en: 'Reason', bn: 'কারণ' },
    'correction.what_happened': { en: 'What happened', bn: 'কী ঘটেছিল' },
    'correction.evidence': { en: 'Evidence (optional)', bn: 'প্রমাণ (ঐচ্ছিক)' },
    'correction.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'correction.send': { en: 'Send request', bn: 'অনুরোধ পাঠান' },

    // ── My payslips ─────────────────────────────────────────────────────
    'payslips.title': { en: 'My payslips', bn: 'আমার বেতনপত্র' },
    'payslips.appears_after_approval': {
        en: 'A payslip appears here once its payroll has been approved.',
        bn: 'বেতন অনুমোদনের পর বেতনপত্র এখানে দেখা যায়।',
    },
    'payslips.password_note': {
        en: 'Downloaded payslips are password protected. Your password: {hint}',
        bn: 'ডাউনলোড করা বেতনপত্র পাসওয়ার্ড দিয়ে সুরক্ষিত। আপনার পাসওয়ার্ড: {hint}',
    },
    'payslips.none': { en: 'No payslips yet.', bn: 'এখনও কোনো বেতনপত্র নেই।' },
    'payslips.period': { en: 'Period', bn: 'মাস' },
    'payslips.gross': { en: 'Gross', bn: 'মোট বেতন' },
    'payslips.earnings': { en: 'Earnings', bn: 'আয়' },
    'payslips.deductions': { en: 'Deductions', bn: 'কর্তন' },
    'payslips.net': { en: 'Net', bn: 'প্রদেয়' },
    'payslips.payment': { en: 'Payment', bn: 'পরিশোধ' },
    'payslips.paid': { en: 'paid', bn: 'পরিশোধিত' },
    'payslips.view': { en: 'View', bn: 'দেখুন' },
    'payslips.pdf': { en: 'PDF', bn: 'পিডিএফ' },

    // ── The self-service tabs (shared by every staff screen) ────────────
    'tabs.my_profile': { en: 'My profile', bn: 'আমার প্রোফাইল' },
    'tabs.clock': { en: 'Clock in/out', bn: 'ক্লক-ইন/আউট' },
    'tabs.my_leave': { en: 'My leave', bn: 'আমার ছুটি' },
    'tabs.my_payslips': { en: 'My payslips', bn: 'আমার বেতনপত্র' },
    'tabs.my_expenses': { en: 'My expenses', bn: 'আমার খরচ' },
    'tabs.my_notices': { en: 'My notices', bn: 'আমার নোটিশ' },
    'tabs.my_assets': { en: 'My assets', bn: 'আমার জিনিসপত্র' },

    // ── The POS counter ────────────────────────────────────
    // The till is where the language matters most: the person behind it serves
    // customers all day and has no time to read a second language.
    'pos.title': { en: 'Counter sale', bn: 'কাউন্টার বিক্রি' },
    'pos.search_placeholder': { en: 'Search or scan: name, SKU, barcode', bn: 'খুঁজুন বা স্ক্যান করুন: নাম, এসকেইউ, বারকোড' },
    'pos.scan_no_match': { en: 'No product has the code {code}.', bn: '{code} কোডের কোনো পণ্য নেই।' },
    'pos.search_aria': { en: 'Search products', bn: 'পণ্য খোঁজা' },
    'pos.all': { en: 'All', bn: 'সব' },
    'pos.no_match': { en: 'Nothing matches that search.', bn: 'এই খোঁজায় কিছু মেলেনি।' },
    'pos.no_price_set': { en: 'No price set', bn: 'দাম দেওয়া নেই' },
    'pos.order': { en: 'Order', bn: 'অর্ডার' },
    'pos.item_one': { en: '{count} item', bn: '{count} পণ্য' },
    'pos.item_many': { en: '{count} items', bn: '{count} পণ্য' },
    'pos.clear': { en: 'Clear', bn: 'মুছুন' },
    'pos.customer': { en: 'Customer', bn: 'ক্রেতা' },
    'pos.default': { en: 'default', bn: 'ডিফল্ট' },
    'pos.sold_from': { en: 'Sold from', bn: 'যে গুদাম থেকে' },
    'pos.paid_into': { en: 'Paid into', bn: 'টাকা যেখানে জমা' },
    'pos.on_account': { en: 'On account (unpaid)', bn: 'বাকিতে (অপরিশোধিত)' },
    'pos.reference': { en: 'Reference', bn: 'রেফারেন্স' },
    'pos.reference_placeholder': { en: 'bKash trxID, cheque no.', bn: 'বিকাশ ট্রানজেকশন আইডি, চেক নম্বর' },
    'pos.tap_to_start': { en: 'Tap a product to start the sale.', bn: 'বিক্রি শুরু করতে একটি পণ্যে চাপ দিন।' },
    'pos.remove': { en: 'Remove', bn: 'বাদ দিন' },
    'pos.remove_aria': { en: 'Remove {name}', bn: '{name} বাদ দিন' },
    'pos.line': { en: 'line', bn: 'লাইন' },
    'pos.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'pos.unit_price': { en: 'Unit price', bn: 'একক দাম' },
    'pos.line_discount': { en: 'Line discount', bn: 'লাইনের ছাড়' },
    'pos.less': { en: 'less', bn: 'ছাড়' },
    'pos.subtotal': { en: 'Subtotal', bn: 'উপমোট' },
    'pos.invoice_discount': { en: 'Discount on the whole bill', bn: 'পুরো বিলে ছাড়' },
    'pos.total': { en: 'Total', bn: 'মোট' },
    'pos.take_payment': { en: 'Take payment and finish', bn: 'টাকা নিয়ে শেষ করুন' },
    'pos.finish_on_account': { en: 'Finish on account', bn: 'বাকিতে শেষ করুন' },
    'pos.held_carts': { en: 'Held carts', bn: 'ধরে রাখা কার্ট' },
    'pos.hold_cart': { en: 'Hold', bn: 'ধরে রাখুন' },
    'pos.resume_cart': { en: 'Resume', bn: 'ফিরিয়ে আনুন' },
    'pos.discard_cart': { en: 'Discard', bn: 'বাতিল' },
    'pos.hold_label_placeholder': { en: 'Who is this for? e.g. uncle in the blue shirt', bn: 'কার কার্ট? যেমন নীল শার্টের কাকা' },
    // ⚠ Not "enter it by hand" any more: the server prices every line from the
    // list itself (21 Sep 2026), so a failed lookup only leaves the screen's
    // total unsure, never the sale's price.
    'pos.price_lookup_failed': {
        en: 'Could not look the price up just now. The price list still decides it when you finish.',
        bn: 'এখন দাম আনা গেল না। শেষ করার সময় তালিকার দামই ধরা হবে।',
    },
    // ── Paid into: the cashier's own till, or a deliberate choice ──
    'pos.choose_paid_into': { en: 'Choose where the money goes', bn: 'টাকা কোথায় জমা হবে বেছে নিন' },
    'pos.choose_paid_into_first': {
        en: 'Choose "Paid into" first: your till, a bank or bKash, or on account.',
        bn: 'আগে "টাকা যেখানে জমা" বেছে নিন: আপনার ক্যাশ, ব্যাংক বা বিকাশ, অথবা বাকিতে।',
    },
    'pos.no_open_till': {
        en: 'You have no open till shift, so no cash till is filled in for you. Open your till shift first:',
        bn: 'আপনার কোনো খোলা ক্যাশ শিফট নেই, তাই কোনো ক্যাশ নিজে থেকে বসানো হয়নি। আগে আপনার ক্যাশ শিফট খুলুন:',
    },
    'pos.open_shift_link': { en: 'Sales > Cashier shifts', bn: 'সেলস > ক্যাশিয়ার শিফট' },
    'pos.bank_still_works': { en: 'Bank and bKash payments work without a shift.', bn: 'শিফট ছাড়াও ব্যাংক ও বিকাশে টাকা নেওয়া যায়।' },
    'pos.till_not_usable': {
        en: 'Your till {name} cannot take a payment here yet: it has no ledger account.',
        bn: 'আপনার ক্যাশ {name} এখনও এখানে টাকা নিতে পারে না: এর কোনো লেজার হিসাব নেই।',
    },
    'pos.not_your_till': { en: '{name} is not your till. Your open shift is on {till}.', bn: '{name} আপনার ক্যাশ নয়। আপনার খোলা শিফট {till}-এ।' },
    'pos.cash_given': { en: 'Cash given', bn: 'ক্রেতা দিলেন' },
    'pos.change_due': { en: 'Change to give: {amount}', bn: 'ফেরত দিন: {amount}' },
    'pos.cash_short': { en: 'Short by {amount}', bn: '{amount} কম' },
    // ── Price changes: permission, and a reason kept on the invoice ──
    'pos.list_price': { en: 'List price {price}', bn: 'তালিকার দাম {price}' },
    'pos.override_reason_placeholder': { en: 'Why? (kept on the invoice)', bn: 'কেন? (ইনভয়েসে থাকবে)' },
    'pos.price_locked': {
        en: 'List price only. Changing a price or giving a discount needs the price-override permission.',
        bn: 'শুধু তালিকার দাম। দাম বদলাতে বা ছাড় দিতে দাম-পরিবর্তনের অনুমতি লাগে।',
    },
    'pos.discount_reason_placeholder': { en: 'Why is the bill discounted?', bn: 'পুরো বিলে ছাড় কেন?' },
    // ── After the sale ──
    'pos.sale_done': { en: 'Sale complete: {number}, total {total}.', bn: 'বিক্রি সম্পন্ন: {number}, মোট {total}।' },
    'pos.sale_on_account': { en: 'Left on account: the customer owes it.', bn: 'বাকিতে রাখা হলো: ক্রেতার কাছে পাওনা।' },
    'pos.received_change': { en: 'Received {received}, change {change}.', bn: 'নেওয়া হলো {received}, ফেরত {change}।' },
    'pos.print_slip': { en: 'Print slip', bn: 'স্লিপ প্রিন্ট করুন' },
    'pos.dismiss': { en: 'Dismiss', bn: 'বন্ধ করুন' },
    'pos.no_price_for_product': {
        en: 'No price is set for this product.',
        bn: 'এই পণ্যের কোনো দাম দেওয়া নেই।',
    },

    // ── The language picker ─────────────────────────────────────────────
    'language.heading': { en: 'Language', bn: 'ভাষা' },
    'language.description': {
        en: 'The language this account sees the ERP in, on every device. Numbers and dates stay in English digits.',
        bn: 'সব ডিভাইসে এই অ্যাকাউন্ট যে ভাষায় ইআরপি দেখবে। সংখ্যা ও তারিখ ইংরেজি অঙ্কেই থাকবে।',
    },
} satisfies Record<string, Entry>;

export type TranslationKey = keyof typeof dictionary;

type Locale = 'en' | 'bn';

/**
 * The hook every translated screen calls. `t('clock.title')` - an unknown key
 * is a compile error, never a runtime blank. English is the fallback for a
 * logged-out page or an unset user.
 */
export function useTranslation() {
    const page = usePage<{ auth?: { user?: { locale?: string } | null } }>();
    const locale: Locale = page.props.auth?.user?.locale === 'bn' ? 'bn' : 'en';

    const t = (key: TranslationKey, replacements?: Record<string, string | number>): string => {
        let text: string = dictionary[key][locale];

        if (replacements) {
            for (const [name, value] of Object.entries(replacements)) {
                text = text.replaceAll(`{${name}}`, String(value));
            }
        }

        return text;
    };

    return { t, locale };
}
