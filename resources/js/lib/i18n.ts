import { usePage } from '@inertiajs/react';
import { attention } from './i18n/attention';
import { billFlex } from './i18n/bill-flex';
import { books } from './i18n/books';
import { campaigns } from './i18n/campaigns';
import { connected } from './i18n/connected';
import { corrAccounts } from './i18n/corr-accounts';
import { corrAssets } from './i18n/corr-assets';
import { corrCrm } from './i18n/corr-crm';
import { corrFollowup } from './i18n/corr-followup';
import { corrHrm } from './i18n/corr-hrm';
import { corrPeople } from './i18n/corr-people';
import { corrSales } from './i18n/corr-sales';
import { corrStock } from './i18n/corr-stock';
import { corrSupplier } from './i18n/corr-supplier';
import { corrW1Pages } from './i18n/corr-w1-pages';
import { correctionsPurchasing } from './i18n/corrections-purchasing';
import { counterparties } from './i18n/counterparties';
import { courierLocations } from './i18n/courier-locations';
import { courierParcel } from './i18n/courier-parcel';
import { courierSales } from './i18n/courier-sales';
import { courierSettings } from './i18n/courier-settings';
import { dashboards } from './i18n/dashboards';
import { employeeCash } from './i18n/employee-cash';
import type { Entry } from './i18n/entry';
import { farmHarvest } from './i18n/farm-harvest';
import { gateway } from './i18n/gateway';
import { greeting } from './i18n/greeting';
import { hrRules } from './i18n/hr-rules';
import { landedCosts } from './i18n/landed-costs';
import { loans } from './i18n/loans';
import { marketPricing } from './i18n/market-pricing';
import { openingAssets } from './i18n/opening-assets';
import { ownerSales } from './i18n/owner-sales';
import { ownerWithdrawals } from './i18n/owner-withdrawals';
import { parties } from './i18n/parties';
import { partyLedgers } from './i18n/party-ledgers';
import { payrollPay } from './i18n/payroll-pay';
import { popups } from './i18n/popups';
import { production } from './i18n/production';
import { purchaseOrderForm } from './i18n/purchase-order-form';
import { purchasePayGrid } from './i18n/purchase-pay-grid';
import { purchaseReturnsVat } from './i18n/purchase-returns-vat';
import { quickPurchase } from './i18n/quick-purchase';
import { quotes } from './i18n/quotes';
import { receipts } from './i18n/receipts';
import { relax } from './i18n/relax';
import { reports } from './i18n/reports';
import { retailSellers } from './i18n/retail-sellers';
import { reversals } from './i18n/reversals';
import { schedule } from './i18n/schedule';
import { selfService } from './i18n/self-service';
import { staffPurchase } from './i18n/staff-purchase';
import { stock } from './i18n/stock';
import { supplierTerms } from './i18n/supplier-terms';
import { tickets } from './i18n/tickets';
import { units } from './i18n/units';

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
//
// ⚠ The dictionary grows by area in lib/i18n/*.ts (courier and dashboard
// screens, 11 Oct 2026), spread in below so every key is still one typed
// union. BanglaDictionaryTest reads every one of those files too.

const dictionary = {
    // ── Shared ──────────────────────────────────────────────────────────
    'common.today': { en: 'Today', bn: 'আজ' },
    'common.date': { en: 'Date', bn: 'তারিখ' },
    'common.status': { en: 'Status', bn: 'অবস্থা' },
    'common.download': { en: 'Download', bn: 'ডাউনলোড' },
    'common.loading': { en: 'Loading...', bn: 'লোড হচ্ছে...' },
    'common.none_yet': { en: 'Nothing here yet.', bn: 'এখনও কিছু নেই।' },

    // ── Purchase line entry (17 Oct 2026: price or total, either way) ───
    'purchase_line.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'purchase_line.unit_price': { en: 'Unit price', bn: 'একক দাম' },
    'purchase_line.unit_price_per': { en: 'Unit price per {unit}', bn: 'প্রতি {unit} দাম' },
    'purchase_line.line_total': { en: 'Line total', bn: 'লাইনের মোট' },
    'purchase_line.price_typed': { en: 'Price typed, total worked out', bn: 'দাম লেখা হয়েছে, মোট হিসাব করা' },
    'purchase_line.total_typed': { en: 'Total typed, price worked out', bn: 'মোট লেখা হয়েছে, দাম হিসাব করা' },
    'purchase_line.hint': {
        en: 'Type the unit price or the line total, whichever the supplier gave; the other is worked out. Example: 100 kg for 50,000 = 500.00 per kg.',
        bn: 'সরবরাহকারী যেটা দিয়েছেন সেটাই লিখুন, একক দাম বা লাইনের মোট; অন্যটা হিসাব হয়ে যাবে। উদাহরণ: 100 kg-এর দাম 50,000 = প্রতি kg 500.00।',
    },
    'purchase_line.vs_order': { en: '{amount} vs order', bn: 'অর্ডারের চেয়ে {amount}' },

    // ── The clock screen ────────────────────────────────────────────────
    'clock.title': { en: 'Clock in/out', bn: 'ক্লক-ইন/আউট' },
    'clock.clock_in': { en: 'Clock in', bn: 'ক্লক-ইন' },
    'clock.clock_out': { en: 'Clock out', bn: 'ক্লক-আউট' },
    'clock.recording': { en: 'Recording…', bn: 'রেকর্ড হচ্ছে…' },
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
    'tabs.my_accounts': { en: 'My accounts', bn: 'আমার অ্যাকাউন্ট' },

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
    'pos.unpriced_hidden': {
        en: '{count} items with no price are hidden. Search to find one.',
        bn: 'দাম নেই এমন {count}টি পণ্য লুকানো আছে। খুঁজতে নাম লিখুন।',
    },
    'pos.order': { en: 'Order', bn: 'অর্ডার' },
    'pos.item_one': { en: '{count} item', bn: '{count} পণ্য' },
    'pos.item_many': { en: '{count} items', bn: '{count} পণ্য' },
    'pos.clear': { en: 'Clear', bn: 'মুছুন' },
    'pos.customer': { en: 'Customer', bn: 'ক্রেতা' },
    'pos.default': { en: 'default', bn: 'ডিফল্ট' },
    'pos.sold_from': { en: 'Sold from', bn: 'যে গুদাম থেকে' },
    'pos.on_hand': { en: '{qty} on hand here', bn: 'এখানে আছে {qty}' },
    'pos.none_here': { en: 'None in this warehouse', bn: 'এই গুদামে নেই' },
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

    // ── Cashier shifts (daily screen 2 of 6, 26 Sep 2026) ────────────────
    'shift.title': { en: 'Cashier shifts', bn: 'ক্যাশিয়ার শিফট' },
    'shift.your_till': { en: 'your till', bn: 'আপনার ক্যাশবাক্স' },
    'shift.opened_by': { en: 'Opened by {name}, {when}', bn: '{name} খুলেছেন, {when}' },
    'shift.opening_float': { en: 'Opening float', bn: 'শুরুর টাকা' },
    'shift.money_in': { en: 'Money in', bn: 'টাকা এসেছে' },
    'shift.money_out': { en: 'Money out', bn: 'টাকা গেছে' },
    'shift.movements': { en: 'Till movements', bn: 'ক্যাশবাক্সে হাতবদল' },
    'shift.expected': { en: 'Expected in the drawer', bn: 'ড্রয়ারে থাকার কথা' },
    'shift.direction': { en: 'Direction', bn: 'কোন দিকে' },
    'shift.cash_out': { en: 'Cash out', bn: 'টাকা বের' },
    'shift.cash_in': { en: 'Cash in', bn: 'টাকা ঢোকানো' },
    'shift.amount': { en: 'Amount', bn: 'টাকার অঙ্ক' },
    'shift.reason_required': { en: 'Reason (required)', bn: 'কারণ (লিখতেই হবে)' },
    'shift.reason_placeholder': { en: 'e.g. change from the market / owner top-up', bn: 'যেমন: বাজার থেকে খুচরা / মালিকের দেওয়া টাকা' },
    'shift.record': { en: 'Record', bn: 'লিখে রাখুন' },
    'shift.in': { en: 'In', bn: 'ঢুকেছে' },
    'shift.out': { en: 'Out', bn: 'বেরিয়েছে' },
    'shift.open_heading': { en: 'Open a shift', bn: 'শিফট খুলুন' },
    'shift.till': { en: 'Till', bn: 'ক্যাশবাক্স' },
    'shift.which_till': { en: 'Which cash drawer?', bn: 'কোন ক্যাশবাক্স?' },
    'shift.opening_float_counted': { en: 'Opening float (counted)', bn: 'শুরুর টাকা (গুনে)' },
    'shift.open_button': { en: 'Open shift', bn: 'শিফট খুলুন' },
    'shift.close_heading': { en: 'Close the shift', bn: 'শিফট বন্ধ করুন' },
    'shift.counted_cash': { en: 'Counted cash', bn: 'গুনে পাওয়া টাকা' },
    'shift.counted_placeholder': { en: 'physical count', bn: 'হাতে গোনা অঙ্ক' },
    'shift.note_optional': { en: 'Note (optional)', bn: 'নোট (ইচ্ছা হলে)' },
    'shift.note_placeholder': { en: 'anything worth remembering', bn: 'মনে রাখার মতো কিছু' },
    'shift.close_button': { en: 'Close shift', bn: 'শিফট বন্ধ করুন' },
    'shift.balanced': { en: 'Exactly balanced.', bn: 'একদম মিলে গেছে।' },
    'shift.over_by': { en: '{amount} over the expectation.', bn: 'হিসাবের চেয়ে {amount} বেশি।' },
    'shift.short_by': { en: '{amount} short of the expectation.', bn: 'হিসাবের চেয়ে {amount} কম।' },
    'shift.no_till': {
        en: 'No cash box to open. Add one under Accounting > Bank & cash (kind: Cash).',
        bn: 'খোলার মতো কোনো ক্যাশবাক্স নেই। Accounting > Bank & cash-এ একটি যোগ করুন (ধরন: Cash)।',
    },
    'shift.closed_heading': { en: 'Closed shifts', bn: 'বন্ধ হওয়া শিফট' },
    'shift.none_closed': { en: 'None yet.', bn: 'এখনও নেই।' },
    'shift.col_opened': { en: 'Opened', bn: 'খোলা' },
    'shift.col_closed': { en: 'Closed', bn: 'বন্ধ' },
    'shift.col_float': { en: 'Float', bn: 'শুরুর টাকা' },
    'shift.col_expected': { en: 'Expected', bn: 'থাকার কথা' },
    'shift.col_counted': { en: 'Counted', bn: 'গুনে পাওয়া' },
    'shift.col_over_short': { en: 'Over / short', bn: 'বেশি / কম' },
    'shift.col_by': { en: 'By', bn: 'কে' },
    // ── Receive payment (daily screen 4 of 6, 26 Sep 2026) ───────────────
    'receipt.title': { en: 'Receive payment', bn: 'টাকা আদায়' },
    'receipt.receipts': { en: 'Receipts', bn: 'আদায়ের তালিকা' },
    'receipt.sales': { en: 'Sales', bn: 'সেলস' },
    'receipt.customer': { en: 'Customer', bn: 'ক্রেতা' },
    'receipt.choose_customer': { en: 'Choose a customer', bn: 'ক্রেতা বাছুন' },
    'receipt.amount_received': { en: 'Amount received (taka)', bn: 'যত টাকা পেলেন' },
    'receipt.date_received': { en: 'Date received', bn: 'যে তারিখে পেলেন' },
    'receipt.received_into': { en: 'Received into', bn: 'টাকা যেখানে জমা' },
    'receipt.cash_bank_wallet': { en: 'Cash, bank or wallet', bn: 'ক্যাশ, ব্যাংক বা মোবাইল ওয়ালেট' },
    'receipt.no_money_account': {
        en: 'No cash, bank or wallet account yet. Add one under Accounting > Bank & cash.',
        bn: 'এখনও কোনো ক্যাশ, ব্যাংক বা ওয়ালেট অ্যাকাউন্ট নেই। Accounting > Bank & cash-এ একটি যোগ করুন।',
    },
    'receipt.reference': { en: 'Reference', bn: 'রেফারেন্স' },
    'receipt.reference_placeholder': { en: 'cheque no., bKash TrxID', bn: 'চেক নম্বর, বিকাশ TrxID' },
    'receipt.note': { en: 'Note', bn: 'নোট' },
    'receipt.optional': { en: 'optional', bn: 'ইচ্ছা হলে' },
    'receipt.unpaid_invoices_of': { en: 'Unpaid invoices of {name}', bn: '{name}-এর বাকি ইনভয়েস' },
    'receipt.nothing_owed': {
        en: 'Nothing is owed: every invoice of this customer is paid.',
        bn: 'কোনো বাকি নেই: এই ক্রেতার সব ইনভয়েস পরিশোধিত।',
    },
    'receipt.col_invoice': { en: 'Invoice', bn: 'ইনভয়েস' },
    'receipt.col_date': { en: 'Date', bn: 'তারিখ' },
    'receipt.col_due': { en: 'Due', bn: 'শেষ তারিখ' },
    'receipt.col_total': { en: 'Total', bn: 'মোট' },
    'receipt.col_still_owed': { en: 'Still owed', bn: 'এখনও বাকি' },
    'receipt.col_pay_now': { en: 'Pay now', bn: 'এখন দিচ্ছেন' },
    'receipt.overdue': { en: 'overdue', bn: 'মেয়াদ পার' },
    'receipt.pay_now_on': { en: 'Pay now on {number}', bn: '{number}-এ এখন দিচ্ছেন' },
    'receipt.owed_in_all': { en: 'Owed in all', bn: 'মোট বাকি' },
    'receipt.owe_only': {
        en: 'They owe only {owed}. Record {owed} here; money paid ahead of an order is taken on the page of that order.',
        bn: 'তাঁর বাকি মাত্র {owed}। এখানে {owed} লিখুন; অর্ডারের আগাম টাকা সেই অর্ডারের পাতায় নেওয়া হয়।',
    },
    'receipt.split_mismatch': {
        en: 'The split adds up to {allocated}, not the {received} received. Change a figure so they agree.',
        bn: 'ভাগগুলো যোগ করলে হয় {allocated}, কিন্তু পেয়েছেন {received}। একটা অঙ্ক বদলে মিলিয়ে দিন।',
    },
    'receipt.receive_amount': { en: 'Receive {amount}', bn: '{amount} আদায় করুন' },
    'receipt.receive_button': { en: 'Receive payment', bn: 'টাকা আদায় করুন' },
    'receipt.cancel': { en: 'Cancel', bn: 'বাতিল' },
    // ── Deliveries (daily screen 5 of 6, 26 Sep 2026) ────────────────────
    'delivery.title': { en: 'Delivery challans', bn: 'ডেলিভারি চালান' },
    'delivery.sales': { en: 'Sales', bn: 'সেলস' },
    'delivery.search_placeholder': { en: 'Search challan or customer', bn: 'চালান বা ক্রেতা খুঁজুন' },
    'delivery.all': { en: 'All', bn: 'সব' },
    'delivery.not_fully_invoiced': { en: 'Not fully invoiced', bn: 'পুরো ইনভয়েস হয়নি' },
    'delivery.col_challan': { en: 'Challan', bn: 'চালান' },
    'delivery.col_date': { en: 'Date', bn: 'তারিখ' },
    'delivery.col_customer': { en: 'Customer', bn: 'ক্রেতা' },
    'delivery.col_from': { en: 'From', bn: 'যে গুদাম থেকে' },
    'delivery.col_lines': { en: 'Lines', bn: 'লাইন' },
    'delivery.col_print': { en: 'Print', bn: 'প্রিন্ট' },
    'delivery.invoiced': { en: 'Invoiced', bn: 'ইনভয়েস হয়েছে' },
    'delivery.awaiting_invoice': { en: 'Awaiting invoice', bn: 'ইনভয়েস বাকি' },
    'delivery.challan_link': { en: 'Challan', bn: 'চালান' },
    'delivery.none_yet': { en: 'No delivery challans yet.', bn: 'এখনও কোনো ডেলিভারি চালান নেই।' },
    // ── Sales orders (daily screen 3 of 6, 26 Sep 2026) ──────────────────
    'orders.title': { en: 'Sales orders', bn: 'বিক্রয় অর্ডার' },
    'orders.new': { en: 'New sales order', bn: 'নতুন বিক্রয় অর্ডার' },
    'orders.close': { en: 'Close', bn: 'বন্ধ করুন' },
    'orders.own_only': {
        en: 'You see the orders you took. Each one waits for approval by a Manager before it is delivered.',
        bn: 'আপনি শুধু নিজের নেওয়া অর্ডারগুলো দেখছেন। প্রতিটি ডেলিভারির আগে ম্যানেজারের অনুমোদনের অপেক্ষায় থাকে।',
    },
    'orders.search': { en: 'Search', bn: 'খুঁজুন' },
    'orders.search_placeholder': { en: 'Order number, website reference or customer…', bn: 'অর্ডার নম্বর, ওয়েবসাইট রেফারেন্স বা ক্রেতা…' },
    'orders.brand': { en: 'Brand', bn: 'ব্র্যান্ড' },
    'orders.all_brands': { en: 'All brands', bn: 'সব ব্র্যান্ড' },
    'orders.no_brand': { en: 'No brand', bn: 'ব্র্যান্ড নেই' },
    'orders.choose_brand': { en: 'Choose a brand', bn: 'ব্র্যান্ড বেছে নিন' },
    // Practice run, 18 Oct 2026: a line chosen before the brand changed.
    'orders.other_brand': {
        en: 'This product is sold under {brand}, not the brand chosen for this order.',
        bn: 'এই পণ্যটি {brand} ব্র্যান্ডের, এই অর্ডারের জন্য বেছে নেওয়া ব্র্যান্ডের নয়।',
    },
    'orders.col_brand': { en: 'Brand', bn: 'ব্র্যান্ড' },
    'orders.status': { en: 'Status', bn: 'অবস্থা' },
    'orders.all_statuses': { en: 'All statuses', bn: 'সব অবস্থা' },
    'orders.status_open': { en: 'Open', bn: 'চালু' },
    'orders.status_partially_delivered': { en: 'Partially delivered', bn: 'আংশিক ডেলিভারি' },
    'orders.status_delivered': { en: 'Delivered', bn: 'ডেলিভারি হয়েছে' },
    'orders.status_closed': { en: 'Closed short', bn: 'কম দিয়ে বন্ধ' },
    'orders.status_cancelled': { en: 'Cancelled', bn: 'বাতিল' },
    'orders.col_number': { en: 'Order #', bn: 'অর্ডার নং' },
    'orders.col_customer': { en: 'Customer', bn: 'ক্রেতা' },
    'orders.col_ordered': { en: 'Ordered', bn: 'অর্ডার' },
    'orders.col_delivered': { en: 'Delivered', bn: 'ডেলিভারি' },
    'orders.col_expected': { en: 'Expected', bn: 'প্রত্যাশিত' },
    'orders.col_approval': { en: 'Approval', bn: 'অনুমোদন' },
    'orders.col_status': { en: 'Status', bn: 'অবস্থা' },
    // The queues and "Courier says" (Sales redesign, 15 Oct 2026)
    'orders.queue_all': { en: 'All', bn: 'সব' },
    'orders.queue_approval': { en: 'Waiting approval', bn: 'অনুমোদনের অপেক্ষায়' },
    'orders.queue_dispatch': { en: 'To dispatch', bn: 'পাঠানো বাকি' },
    'orders.queue_moving': { en: 'With the courier', bn: 'কুরিয়ারের কাছে' },
    'orders.queue_problem': { en: 'Problem', bn: 'সমস্যা' },
    'orders.queue_delivered': { en: 'Delivered', bn: 'ডেলিভারি হয়েছে' },
    'orders.col_courier_says': { en: 'Courier says', bn: 'কুরিয়ার বলছে' },
    'orders.courier_says_hint': {
        en: "The courier's own last word on this order's parcel, from its webhook or the last look-up. Blank until a challan is booked.",
        bn: 'এই অর্ডারের পার্সেল নিয়ে কুরিয়ারের সর্বশেষ কথা, ওয়েবহুক বা শেষ খোঁজ থেকে। চালান বুক না হওয়া পর্যন্ত খালি।',
    },
    'orders.shown': { en: '{shown} of {total} shown', bn: '{total}টির মধ্যে {shown}টি দেখানো হচ্ছে' },
    'orders.to_book_nudge': {
        en: '{count} approved orders are waiting for a courier. Book them in one run and print the labels.',
        bn: '{count}টি অনুমোদিত অর্ডার কুরিয়ারের অপেক্ষায়। এক রানে বুক করুন আর লেবেল প্রিন্ট করুন।',
    },
    'orders.to_book_link': { en: 'Parcels to book', bn: 'বুক করার পার্সেল' },
    'orders.website': { en: 'Website', bn: 'ওয়েবসাইট' },
    'orders.awaiting_payment': { en: 'Awaiting payment', bn: 'পেমেন্টের অপেক্ষায়' },
    'orders.none_match': { en: 'No sales orders match these filters.', bn: 'এই ফিল্টারে কোনো অর্ডার মেলেনি।' },
    'orders.none_yet': { en: 'No sales orders yet.', bn: 'এখনও কোনো বিক্রয় অর্ডার নেই।' },
    'orders.committed_heading': { en: 'Committed vs on hand', bn: 'প্রতিশ্রুত বনাম মজুত' },
    'orders.col_product': { en: 'Product', bn: 'পণ্য' },
    'orders.col_committed': { en: 'Committed on open orders', bn: 'চালু অর্ডারে প্রতিশ্রুত' },
    'orders.col_on_hand': { en: 'On hand', bn: 'মজুত আছে' },
    'orders.short': { en: 'Short', bn: 'কম' },
    // The new order form
    'orders.form_customer': { en: 'Customer', bn: 'ক্রেতা' },
    'orders.select_customer': { en: 'Select customer', bn: 'ক্রেতা বাছুন' },
    'orders.name_or_phone': { en: 'Name or phone…', bn: 'নাম বা ফোন…' },
    'orders.new_customer': { en: 'New customer', bn: 'নতুন ক্রেতা' },
    'orders.warehouse': { en: 'Warehouse', bn: 'গুদাম' },
    'orders.decided_at_delivery': { en: 'Decided at delivery', bn: 'ডেলিভারির সময় ঠিক হবে' },
    'orders.search_warehouses': { en: 'Search warehouses…', bn: 'গুদাম খুঁজুন…' },
    'orders.expected_date': { en: 'Expected date (optional)', bn: 'প্রত্যাশিত তারিখ (ইচ্ছা হলে)' },
    'orders.product': { en: 'Product', bn: 'পণ্য' },
    'orders.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'orders.price': { en: 'Price', bn: 'দাম' },
    'orders.line_total': { en: 'Line total', bn: 'লাইনের মোট' },
    'orders.pick_customer_first': {
        en: 'Pick the customer first: the price depends on who is buying.',
        bn: 'আগে ক্রেতা বাছুন: কে কিনছে তার ওপর দাম নির্ভর করে।',
    },
    'orders.add_line': { en: '+ Add line', bn: '+ লাইন যোগ করুন' },
    'orders.order_total': { en: 'Order total', bn: 'অর্ডারের মোট' },
    'orders.note_optional': { en: 'Note (optional)', bn: 'নোট (ইচ্ছা হলে)' },
    'orders.record': { en: 'Record sales order', bn: 'অর্ডার লিখে রাখুন' },
    // One line
    'orders.select_product': { en: 'Select product', bn: 'পণ্য বাছুন' },
    'orders.search_products': { en: 'Search products…', bn: 'পণ্য খুঁজুন…' },
    'orders.quantity_in': { en: 'Quantity ({unit})', bn: 'পরিমাণ ({unit})' },
    // Courier hub slice 3 (11 Oct 2026): the parcel weight, worked out from the lines and editable.
    'orders.parcel_weight': { en: 'Parcel weight (g)', bn: 'পার্সেলের ওজন (গ্রাম)' },
    'orders.weight_from_lines': { en: 'From the lines: {grams} g', bn: 'লাইন থেকে: {grams} গ্রাম' },
    'orders.weight_at_least': { en: 'At least {grams} g', bn: 'কমপক্ষে {grams} গ্রাম' },
    'orders.weight_missing_items': { en: '{count} item(s) without weight', bn: '{count}টি পণ্যের ওজন নেই' },
    'orders.weight_hint': {
        en: 'Change it if you know better. Blank keeps the weight from the lines.',
        bn: 'আপনি ভালো জানলে বদলে দিন। খালি রাখলে লাইনের ওজনই থাকবে।',
    },
    'orders.unit_price': { en: 'Unit price', bn: 'একক দাম' },
    // Joy, 19 Oct 2026: the selling date, and a line typed by its total.
    'orders.order_date': { en: 'Order date', bn: 'বিক্রির তারিখ' },
    'orders.backdated': {
        en: 'Back-dated order: prices are looked up as on {date}. Date the challan and the invoice on the days they really happened too.',
        bn: 'পুরনো তারিখের অর্ডার: দাম {date} তারিখ অনুযায়ী দেখানো হচ্ছে। চালান আর ইনভয়েসও আসল দিনের তারিখে দিন।',
    },
    'orders.section_order': { en: 'Order', bn: 'অর্ডার' },
    'orders.section_items': { en: 'Items', bn: 'পণ্য' },
    'orders.section_delivery': { en: 'Delivery', bn: 'ডেলিভারি' },
    'orders.section_notes': { en: 'Notes', bn: 'নোট' },
    'orders.items_hint': {
        en: 'Type the unit price or the line total; the other is worked out.',
        bn: 'একক দাম বা লাইনের মোট লিখুন; অন্যটা নিজে হিসাব হবে।',
    },
    'orders.total_not_exact': {
        en: 'No unit price gives exactly {typed} for this quantity; the line will be {total}.',
        bn: 'এই পরিমাণে ঠিক {typed} হয় এমন একক দাম নেই; লাইনটি হবে {total}।',
    },
    'orders.summary': { en: 'Summary', bn: 'সারাংশ' },
    'orders.summary_lines': { en: 'Goods ({count} lines)', bn: 'পণ্য ({count} লাইন)' },
    'orders.summary_delivery': { en: 'Delivery charge', bn: 'ডেলিভারি চার্জ' },
    'orders.summary_dated': { en: 'Dated', bn: 'তারিখ' },
    'orders.changed_from': { en: 'Changed from {price}: undo', bn: '{price} থেকে বদলানো: আগেরটা ফেরান' },
    'orders.remove': { en: 'Remove', bn: 'বাদ দিন' },
    // Exposure
    'orders.line_description': { en: 'Note for this line (optional), e.g. gift wrap', bn: 'এই লাইনের নোট (ঐচ্ছিক), যেমন গিফট মোড়ক' },
    'orders.below_cost': {
        en: 'Below the last cost of {cost}: you lose {loss} a unit.',
        bn: 'শেষ খরচ {cost} এর নিচে: প্রতি ইউনিটে {loss} লোকসান।',
    },
    'orders.delivery_address': { en: 'Delivery address', bn: 'ডেলিভারির ঠিকানা' },
    'orders.delivery_address_hint': {
        en: 'Fill this in to book a courier from the order. Leave the district and street empty when the customer collects.',
        bn: 'অর্ডার থেকে কুরিয়ার বুক করতে এটি পূরণ করুন। ক্রেতা নিজে নিলে জেলা ও রাস্তা খালি রাখুন।',
    },
    'orders.recipient_name': { en: 'Recipient name', bn: 'প্রাপকের নাম' },
    'orders.recipient_phone': { en: 'Recipient phone', bn: 'প্রাপকের ফোন' },
    'orders.address_line': { en: 'House, road, area', bn: 'বাসা, রোড, এলাকা' },
    'orders.landmark': { en: 'Landmark (optional)', bn: 'চেনার চিহ্ন (ঐচ্ছিক)' },
    // 24 Oct 2026: a hand-over order needs no address; a saved street alone is never prefilled.
    'orders.hand_over': { en: 'Handed over, no delivery', bn: 'হাতে হাতে দেওয়া, ডেলিভারি নেই' },
    'orders.hand_over_hint': {
        en: 'The customer takes the goods here or by hand, so no address is needed.',
        bn: 'ক্রেতা এখানে বা হাতে হাতে মাল নেন, তাই ঠিকানা লাগবে না।',
    },
    'orders.saved_address_hint': {
        en: 'Saved address: {address}. Choose its district and thana to deliver there.',
        bn: 'সংরক্ষিত ঠিকানা: {address}। সেখানে পাঠাতে জেলা ও থানা বাছুন।',
    },
    'orders.delivery_charge': { en: 'Delivery charge (taka)', bn: 'ডেলিভারি চার্জ (টাকা)' },
    'orders.delivery_charge_hint': {
        en: 'What the customer pays for delivery. It goes on the invoice and into the courier cash amount.',
        bn: 'ডেলিভারির জন্য ক্রেতা যা দেবেন। এটি ইনভয়েসে এবং কুরিয়ারের ক্যাশ আদায়ে যোগ হবে।',
    },
    'orders.special_instructions': { en: 'Special instructions (optional)', bn: 'বিশেষ নির্দেশনা (ঐচ্ছিক)' },
    'orders.special_instructions_placeholder': {
        en: 'e.g. call before delivery, deliver after 5 pm',
        bn: 'যেমন ডেলিভারির আগে ফোন করুন, বিকেল 5টার পরে দিন',
    },
    'orders.exposure': {
        en: 'Already owed and promised: open invoices {invoices} + delivered, not yet invoiced {delivered} + undelivered orders {orders} =',
        bn: 'ইতিমধ্যে বাকি ও প্রতিশ্রুত: খোলা ইনভয়েস {invoices} + ডেলিভারি হয়েছে, ইনভয়েস হয়নি {delivered} + ডেলিভারি না হওয়া অর্ডার {orders} =',
    },
    'orders.against_limit': { en: 'against a credit limit of {limit}', bn: 'ক্রেডিট সীমা {limit}-এর বিপরীতে' },
    'orders.over_limit': {
        en: 'Over the credit limit. The order can be recorded, but approval is refused unless an Owner approves it with a reason.',
        bn: 'ক্রেডিট সীমা পার। অর্ডার লেখা যাবে, কিন্তু মালিক কারণসহ অনুমোদন না দিলে অনুমোদন হবে না।',
    },
    // Quick customer
    'orders.quick_title': { en: 'New customer', bn: 'নতুন ক্রেতা' },
    'orders.quick_name': { en: 'Name', bn: 'নাম' },
    'orders.quick_phone': { en: 'Phone', bn: 'ফোন' },
    'orders.quick_same_phone': { en: '{name} already has this phone.', bn: 'এই ফোন নম্বর ইতিমধ্যে {name}-এর।' },
    'orders.quick_use': { en: 'Use {name}', bn: '{name}-কে নিন' },
    'orders.quick_who': { en: 'Who they are', bn: 'কী ধরনের ক্রেতা' },
    'orders.quick_prices': { en: 'Prices they pay', bn: 'যে দামে কেনেন' },
    'orders.quick_address': { en: 'Address (optional)', bn: 'ঠিকানা (ইচ্ছা হলে)' },
    'orders.quick_cancel': { en: 'Cancel', bn: 'বাতিল' },
    'orders.quick_add': { en: 'Add customer', bn: 'ক্রেতা যোগ করুন' },
    // ── Goods receipt on the purchase order (daily screen 6 of 6, 26 Sep 2026) ──
    'grn.title': { en: 'Record goods receipt', bn: 'মাল বুঝে নেওয়া লিখুন' },
    'grn.supplier_challan': { en: 'Supplier challan no.', bn: 'সরবরাহকারীর চালান নং' },
    'grn.supplier_challan_placeholder': { en: 'From their delivery note', bn: 'তাদের ডেলিভারি নোট থেকে' },
    'grn.note': { en: 'Note', bn: 'নোট' },
    'grn.remaining': { en: 'remaining {quantity}', bn: 'বাকি {quantity}' },
    'grn.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'grn.batch_no': { en: 'Batch no.', bn: 'ব্যাচ নং' },
    'grn.batch_placeholder': { en: 'As on the label', bn: 'লেবেলে যেমন আছে' },
    'grn.made_on': { en: 'Made on', bn: 'তৈরির তারিখ' },
    'grn.expires_on': { en: 'Expires on', bn: 'মেয়াদ শেষ' },
    'grn.record': { en: 'Record receipt', bn: 'বুঝে নেওয়া লিখে রাখুন' },
    'grn.received_on': { en: 'Received on', bn: 'যে তারিখে এসেছে' },
    // ⭐ D-G, 18 Oct 2026: the purchase order's own date, and the warning when a delivery predates it.
    'grn.received_before_order': {
        en: 'Received before the order date ({date}): check the dates.',
        bn: 'অর্ডারের তারিখের ({date}) আগে মাল এসেছে দেখাচ্ছে: তারিখগুলো মিলিয়ে দেখুন।',
    },
    'po.order_date': { en: 'Order date', bn: 'অর্ডারের তারিখ' },
    'po.ordered': { en: 'Ordered', bn: 'অর্ডার হয়েছে' },
    'grn.backdate_reason': { en: 'Why is it entered now, not that day?', bn: 'সেদিন না লিখে এখন কেন লেখা হচ্ছে?' },
    'grn.backdate_reason_placeholder': { en: 'e.g. the challan reached the office on Monday', bn: 'যেমন: চালান সোমবার অফিসে পৌঁছেছে' },
    // Breadcrumbs shared by the sales daily screens
    'nav.sales': { en: 'Sales', bn: 'সেলস' },
    'nav.courier_settings': { en: 'Courier settings', bn: 'কুরিয়ার সেটিংস' },
    'nav.counter': { en: 'Counter', bn: 'কাউন্টার' },
    // ── The six daily menu entries (26 Sep 2026) ─────────────────────────
    'menu.counter_sale': { en: 'Counter sale', bn: 'কাউন্টার বিক্রি' },
    'menu.cashier_shifts': { en: 'Cashier shifts', bn: 'ক্যাশিয়ার শিফট' },
    'menu.sales_orders': { en: 'Sales orders', bn: 'বিক্রয় অর্ডার' },
    'menu.deliveries': { en: 'Deliveries', bn: 'ডেলিভারি' },
    'menu.receive_payment': { en: 'Receive payment', bn: 'টাকা আদায়' },
    'menu.purchase_orders': { en: 'Purchase orders', bn: 'ক্রয় অর্ডার' },
    // ── Stock list (audit #14, 14 Oct 2026) ─────────────────────────────
    'stock.expired_of_which': { en: 'of which {quantity} expired, not sellable', bn: 'এর মধ্যে {quantity} মেয়াদোত্তীর্ণ, বিক্রি করা যাবে না' },
    // ── The language picker ─────────────────────────────────────────────
    'language.heading': { en: 'Language', bn: 'ভাষা' },
    'language.description': {
        en: 'The language this account sees the ERP in, on every device. Numbers and dates stay in English digits.',
        bn: 'সব ডিভাইসে এই অ্যাকাউন্ট যে ভাষায় ইআরপি দেখবে। সংখ্যা ও তারিখ ইংরেজি অঙ্কেই থাকবে।',
    },
    ...courierSettings,
    ...courierLocations,
    ...courierSales,
    ...courierParcel,
    ...dashboards,
    ...attention,
    ...reports,
    ...greeting,
    ...payrollPay,
    ...popups,
    ...relax,
    ...schedule,
    ...selfService,
    ...production,
    ...books,
    ...stock,
    ...quotes,
    ...hrRules,
    ...openingAssets,
    ...reversals,
    ...corrSupplier,
    ...campaigns,
    ...tickets,
    ...counterparties,
    ...loans,
    ...units,
    ...landedCosts,
    ...receipts,
    ...billFlex,
    ...marketPricing,
    ...ownerWithdrawals,
    ...ownerSales,
    ...staffPurchase,
    ...parties,
    ...partyLedgers,
    ...supplierTerms,
    ...quickPurchase,
    ...connected,
    ...purchaseOrderForm,
    ...gateway,
    ...correctionsPurchasing,
    ...corrStock,
    ...corrHrm,
    ...corrSales,
    ...farmHarvest,
    ...corrW1Pages,
    ...corrPeople,
    ...corrAccounts,
    ...corrCrm,
    ...corrAssets,
    ...corrFollowup,
    ...purchasePayGrid,
    ...employeeCash,
    ...retailSellers,
    ...purchaseReturnsVat,
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

    /**
     * For a key that arrives from the server (a widget key, a courier status
     * value): the Bangla when the dictionary has it, else the server's own
     * English. Never a blank, never the raw key.
     */
    const tr = (key: string, fallback: string, replacements?: Record<string, string | number>): string =>
        key in dictionary ? t(key as TranslationKey, replacements) : fallback;

    return { t, tr, locale };
}
