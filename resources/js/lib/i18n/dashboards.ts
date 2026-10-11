import type { Entry } from './entry';

/**
 * The role dashboards (11 Oct 2026): the page, its toolbar, the widget cards,
 * Settings > Dashboards, and every widget's title, description and empty text
 * by widget key (the server sends English; the browser swaps by key through
 * `tr`, so a widget added later still shows its English, never a blank).
 */
export const dashboards = {
    // ── The page ────────────────────────────────────────────────────────
    'dash.breadcrumb': { en: 'Dashboard', bn: 'ড্যাশবোর্ড' },
    'dash.head_title': { en: '{name} dashboard', bn: '{name} ড্যাশবোর্ড' },
    'dash.switcher': { en: 'Dashboards', bn: 'ড্যাশবোর্ডসমূহ' },
    'dash.classic': { en: 'Classic', bn: 'ক্লাসিক' },
    'dash.settings_title': { en: 'Which dashboard each role gets', bn: 'কোন রোল কোন ড্যাশবোর্ড পাবে' },
    'dash.settings': { en: 'Dashboard settings', bn: 'ড্যাশবোর্ড সেটিংস' },
    'dash.layout_not_saved': { en: 'Your layout could not be saved.', bn: 'আপনার সাজানো সংরক্ষণ করা যায়নি।' },
    'dash.range_error_instead': { en: 'Showing {range} instead.', bn: 'এর বদলে {range} দেখানো হচ্ছে।' },
    'dash.all_hidden': { en: 'Every widget is hidden. Use Customise to show them again.', bn: 'সব উইজেট লুকানো। আবার দেখাতে "সাজান" ব্যবহার করুন।' },
    'dash.nothing_for_role': {
        en: 'Nothing to show on this dashboard for your role yet.',
        bn: 'আপনার রোলের জন্য এই ড্যাশবোর্ডে এখনও দেখানোর কিছু নেই।',
    },
    'dash.empty_default': { en: 'Nothing to show yet.', bn: 'এখনও দেখানোর কিছু নেই।' },

    // ── Dashboard names and descriptions (DashboardKey) ─────────────────
    'dash.name.owner': { en: 'Owner', bn: 'মালিক' },
    'dash.name.finance': { en: 'Finance', bn: 'হিসাব ও অর্থ' },
    'dash.name.manager': { en: 'Manager', bn: 'ম্যানেজার' },
    'dash.name.sales': { en: 'Sales and counter', bn: 'বিক্রি ও কাউন্টার' },
    'dash.name.employee': { en: 'My dashboard', bn: 'আমার ড্যাশবোর্ড' },
    'dash.description.owner': {
        en: 'The whole business: money, sales, stock, people and what needs you.',
        bn: 'পুরো ব্যবসা: টাকা, বিক্রি, স্টক, লোকজন আর যা আপনার অপেক্ষায়।',
    },
    'dash.description.finance': {
        en: 'Cash per account, who owes what, reconciliation and the close.',
        bn: 'প্রতি অ্যাকাউন্টের নগদ, কে কত পাওনা, মিলানো আর হিসাব বন্ধ।',
    },
    'dash.description.manager': {
        en: 'Your team today, approvals, orders to dispatch and stock to reorder.',
        bn: 'আজ আপনার দল, অনুমোদন, পাঠানোর অর্ডার আর আবার কেনার স্টক।',
    },
    'dash.description.sales': {
        en: 'Your orders, your target and what the counter can sell right now.',
        bn: 'আপনার অর্ডার, আপনার লক্ষ্য আর কাউন্টার এখন কী বিক্রি করতে পারে।',
    },
    'dash.description.employee': {
        en: 'Your own attendance, leave, payslip, claims and to-dos.',
        bn: 'আপনার নিজের হাজিরা, ছুটি, বেতনের স্লিপ, খরচের দাবি আর করণীয়।',
    },

    // ── Customise ───────────────────────────────────────────────────────
    'dash.customise': { en: 'Customise', bn: 'সাজান' },
    'dash.your_widgets': { en: 'Your widgets', bn: 'আপনার উইজেট' },
    'dash.move_up': { en: 'Move {name} up', bn: '{name} উপরে নিন' },
    'dash.move_down': { en: 'Move {name} down', bn: '{name} নিচে নিন' },
    'dash.hiding_note': {
        en: 'Hiding a widget only tidies your page; nobody gains or loses access.',
        bn: 'উইজেট লুকালে শুধু আপনার পাতা গোছানো হয়; কারও অনুমতি বদলায় না।',
    },
    'dash.reset': { en: 'Reset', bn: 'আগের মতো' },
    'dash.save': { en: 'Save', bn: 'সংরক্ষণ' },

    // ── Live indicator ──────────────────────────────────────────────────
    'dash.live': { en: 'Live', bn: 'লাইভ' },
    'dash.connecting': { en: 'Connecting', bn: 'সংযোগ হচ্ছে' },
    'dash.timer': { en: 'Refreshing on a timer', bn: 'নির্দিষ্ট সময় পরপর হালনাগাদ হচ্ছে' },
    'dash.offline_timer': { en: 'Offline, refreshing on a timer', bn: 'সংযোগ নেই, নির্দিষ্ট সময় পরপর হালনাগাদ হচ্ছে' },

    // ── Date range ──────────────────────────────────────────────────────
    'dash.date_range': { en: 'Date range', bn: 'তারিখের সীমা' },
    'dash.preset.today': { en: 'Today', bn: 'আজ' },
    'dash.preset.yesterday': { en: 'Yesterday', bn: 'গতকাল' },
    'dash.preset.last_7_days': { en: 'Last 7 days', bn: 'গত 7 দিন' },
    'dash.preset.this_month': { en: 'This month', bn: 'এই মাস' },
    'dash.preset.last_month': { en: 'Last month', bn: 'গত মাস' },
    'dash.preset.this_quarter': { en: 'This quarter', bn: 'এই প্রান্তিক' },
    'dash.preset.this_year': { en: 'This year', bn: 'এই বছর' },
    'dash.preset.custom': { en: 'Custom', bn: 'নিজের মতো' },
    'dash.from': { en: 'From', bn: 'শুরু' },
    'dash.to': { en: 'To', bn: 'শেষ' },
    'dash.range_note': {
        en: 'Dhaka dates, up to 366 days. Compared with the same length just before.',
        bn: 'ঢাকার তারিখ, সর্বোচ্চ 366 দিন। ঠিক আগের সমান সময়ের সাথে তুলনা।',
    },
    'dash.show': { en: 'Show', bn: 'দেখান' },

    // ── Sound settings ──────────────────────────────────────────────────
    'dash.sound_settings': { en: 'Sound settings', bn: 'শব্দের সেটিংস' },
    'dash.sound_on': { en: 'Sound on', bn: 'শব্দ চালু' },
    'dash.sound_off': { en: 'Sound off', bn: 'শব্দ বন্ধ' },
    'dash.sound_enable': { en: 'Play sounds on this dashboard', bn: 'এই ড্যাশবোর্ডে শব্দ বাজান' },
    'dash.volume': { en: 'Volume', bn: 'আওয়াজ' },
    'dash.which_sounds': { en: 'Which sounds', bn: 'কোন শব্দগুলো' },
    'dash.sound.success': { en: 'Good news', bn: 'সুখবর' },
    'dash.sound.success_hint': { en: 'Orders, payments, approvals given', bn: 'অর্ডার, টাকা আদায়, অনুমোদন দেওয়া' },
    'dash.sound.attention': { en: 'Needs attention', bn: 'নজর দরকার' },
    'dash.sound.attention_hint': { en: 'Waiting for you, low stock', bn: 'আপনার অপেক্ষায়, স্টক কম' },
    'dash.sound.error': { en: 'Problems', bn: 'সমস্যা' },
    'dash.sound.error_hint': { en: 'Failures and sync errors', bn: 'ব্যর্থতা আর সিঙ্কের ত্রুটি' },
    'dash.sound_test': { en: 'Test', bn: 'শুনে দেখুন' },
    'dash.quiet_hours': { en: 'Quiet hours', bn: 'নীরব সময়' },
    'dash.quiet_from': { en: 'Quiet from', bn: 'নীরব শুরু' },
    'dash.quiet_until': { en: 'Quiet until', bn: 'নীরব শেষ' },
    'dash.sound_note': {
        en: 'Never on opening the page or for your own actions. At most one sound every 3 seconds; a burst plays once.',
        bn: 'পাতা খুললে বা আপনার নিজের কাজে কখনও বাজে না। প্রতি 3 সেকেন্ডে সর্বোচ্চ একটি শব্দ; একসাথে অনেক ঘটলে একবারই বাজে।',
    },
    'dash.sound_unlock': {
        en: 'Click anywhere once so the browser allows sound.',
        bn: 'ব্রাউজার যেন শব্দ বাজাতে দেয়, তাই একবার যেকোনো জায়গায় ক্লিক করুন।',
    },
    'dash.saving': { en: 'Saving.', bn: 'সংরক্ষণ হচ্ছে।' },

    // ── The widget card ─────────────────────────────────────────────────
    'dash.basis.ledger': { en: 'Ledger', bn: 'খতিয়ান' },
    'dash.basis.operational': { en: 'Operational', bn: 'চলতি কাজের হিসাব' },
    'dash.basis.estimate': { en: 'Estimate', bn: 'আনুমানিক' },
    'dash.by_basis': { en: 'by {basis}', bn: '{basis} অনুযায়ী' },
    'dash.updated': { en: 'Updated {ago}', bn: 'হালনাগাদ {ago}' },
    'dash.not_refreshed': { en: 'Not refreshed, showing {ago}', bn: 'হালনাগাদ হয়নি, দেখানো হচ্ছে {ago}' },
    'dash.ago.now': { en: 'just now', bn: 'এইমাত্র' },
    'dash.ago.seconds': { en: '{n} s ago', bn: '{n} সেকেন্ড আগে' },
    'dash.ago.minutes': { en: '{n} min ago', bn: '{n} মিনিট আগে' },
    'dash.ago.hours': { en: '{n} h ago', bn: '{n} ঘণ্টা আগে' },
    'dash.refresh_name': { en: 'Refresh {name}', bn: '{name} হালনাগাদ করুন' },
    'dash.refresh_now': { en: 'Refresh now', bn: 'এখনই হালনাগাদ করুন' },
    'dash.hide_name': { en: 'Hide {name}', bn: '{name} লুকান' },
    'dash.hide_title': { en: 'Hide from my dashboard', bn: 'আমার ড্যাশবোর্ড থেকে লুকান' },
    'dash.open_name': { en: 'Open the screen behind {name}', bn: '{name}-এর পেছনের পাতা খুলুন' },
    'dash.open_full': { en: 'Open the full screen', bn: 'পুরো পাতা খুলুন' },
    'dash.load_failed': { en: 'This figure could not be loaded.', bn: 'এই হিসাবটি লোড করা যায়নি।' },
    'dash.load_failed_retry': {
        en: 'This figure could not be loaded. It will try again on its own.',
        bn: 'এই হিসাবটি লোড করা যায়নি। নিজে থেকেই আবার চেষ্টা করবে।',
    },
    'dash.try_again': { en: 'Try again', bn: 'আবার চেষ্টা করুন' },

    // ── Widget bodies ───────────────────────────────────────────────────
    'dash.vs_previous': { en: 'Against the previous period', bn: 'আগের সময়ের তুলনায়' },
    'dash.total': { en: 'Total', bn: 'মোট' },
    'dash.nothing_posted': { en: 'Nothing posted in this range.', bn: 'এই সময়ে কিছু পোস্ট হয়নি।' },
    'dash.search_stock': { en: 'Search a product or SKU', bn: 'পণ্য বা SKU খুঁজুন' },
    'dash.search_stock_label': { en: 'Search stock', bn: 'স্টক খুঁজুন' },
    'dash.nothing_matches': { en: 'Nothing matches.', bn: 'কিছু মেলেনি।' },
    'dash.shown_of': { en: '{shown} of {total} shown', bn: '{total}টির মধ্যে {shown}টি দেখানো হচ্ছে' },
    'dash.no_signals': { en: 'No signals for your role.', bn: 'আপনার রোলের জন্য কোনো সংকেত নেই।' },
    'dash.of_target': { en: 'of {target} target ({percent}%)', bn: '{target} লক্ষ্যের ({percent}%)' },

    // ── Spoken event messages (use-dashboard-channel) ───────────────────
    'dash.event.sales_order_created': { en: 'A new sales order came in.', bn: 'নতুন একটি বিক্রয় অর্ডার এসেছে।' },
    'dash.event.sales_order_approved': { en: 'A sales order was approved.', bn: 'একটি বিক্রয় অর্ডার অনুমোদিত হয়েছে।' },
    'dash.event.sales_payment_received': { en: 'A customer payment was recorded.', bn: 'একজন ক্রেতার টাকা আদায় লেখা হয়েছে।' },
    'dash.event.approval_requested': { en: 'Something was sent for approval.', bn: 'কিছু একটা অনুমোদনের জন্য পাঠানো হয়েছে।' },
    'dash.event.approval_decided': { en: 'An approval was decided.', bn: 'একটি অনুমোদনের সিদ্ধান্ত হয়েছে।' },
    'dash.event.stock_low': { en: 'A product reached its low-stock line.', bn: 'একটি পণ্য কম-স্টকের সীমায় পৌঁছেছে।' },
    'dash.event.expense_claim_submitted': { en: 'An expense claim was submitted.', bn: 'একটি খরচের দাবি জমা হয়েছে।' },
    'dash.event.leave_decided': { en: 'Your leave request was decided.', bn: 'আপনার ছুটির আবেদনের সিদ্ধান্ত হয়েছে।' },
    'dash.event.payslip_ready': { en: 'Your payslip is ready.', bn: 'আপনার বেতনের স্লিপ তৈরি।' },
    'dash.event.courier_parcel_status_changed': { en: 'A parcel moved.', bn: 'একটি পার্সেলের অবস্থা বদলেছে।' },
    'dash.event.courier_booking_failed': { en: 'A courier booking failed.', bn: 'একটি কুরিয়ার বুকিং ব্যর্থ হয়েছে।' },
    'dash.event.integration_sync_error': { en: 'A sync with an outside service failed.', bn: 'বাইরের একটি সার্ভিসের সাথে সিঙ্ক ব্যর্থ হয়েছে।' },
    'dash.event.courier_statement_imported': { en: 'A courier statement was imported.', bn: 'একটি কুরিয়ার স্টেটমেন্ট আনা হয়েছে।' },
    'dash.event.courier_statement_posted': { en: 'A courier statement was posted.', bn: 'একটি কুরিয়ার স্টেটমেন্ট পোস্ট হয়েছে।' },
    'dash.event.courier_booking_unknown': {
        en: 'A courier booking answer was lost. Check the parcel.',
        bn: 'একটি কুরিয়ার বুকিংয়ের উত্তর হারিয়ে গেছে। পার্সেলটি দেখুন।',
    },

    // ── Settings > Dashboards ───────────────────────────────────────────
    'dash.settings.title': { en: 'Dashboards', bn: 'ড্যাশবোর্ড' },
    'dash.settings.description': {
        en: 'Which dashboard each role lands on in this business. Somebody with several roles lands on the highest one; holders of see-all access can open every dashboard, and those views are logged.',
        bn: 'এই ব্যবসায় কোন রোল কোন ড্যাশবোর্ডে পৌঁছাবে। কারও একাধিক রোল থাকলে সবচেয়ে উঁচুটিতে পৌঁছাবে; সব-দেখার অনুমতি যাদের আছে তারা প্রতিটি ড্যাশবোর্ড খুলতে পারেন, আর সেই দেখা লগে লেখা থাকে।',
    },
    'dash.settings.role': { en: 'Role', bn: 'রোল' },
    'dash.settings.people': { en: 'People', bn: 'লোকজন' },
    'dash.settings.lands_on': { en: 'Lands on', bn: 'যেখানে পৌঁছায়' },
    'dash.settings.dashboard_for': { en: 'Dashboard for {name}', bn: '{name}-এর ড্যাশবোর্ড' },
    'dash.settings.automatic': {
        en: 'Automatic (from its permissions: {name})',
        bn: 'স্বয়ংক্রিয় (অনুমতি অনুযায়ী: {name})',
    },

    // ── Widgets, by key (App\Support\Dashboards\Widgets) ────────────────
    'widget.cash_position.title': { en: 'Cash and equivalents', bn: 'নগদ ও সমতুল্য' },
    'widget.cash_position.description': {
        en: 'Cash in hand, bank and mobile wallets, from posted journals. Money still with couriers or the gateway is not counted here.',
        bn: 'হাতে নগদ, ব্যাংক ও মোবাইল ওয়ালেট, পোস্ট করা জার্নাল থেকে। কুরিয়ার বা গেটওয়ের কাছে থাকা টাকা এখানে ধরা হয়নি।',
    },
    'widget.money_in_transit.title': { en: 'With couriers and gateway', bn: 'কুরিয়ার ও গেটওয়ের কাছে' },
    'widget.money_in_transit.description': {
        en: 'Cash on delivery a courier has collected and not paid out, and online payments the gateway has not settled. Posted journals only.',
        bn: 'কুরিয়ার যে ক্যাশ অন ডেলিভারি তুলেছে কিন্তু দেয়নি, আর গেটওয়ে যে অনলাইন পেমেন্ট এখনও মেটায়নি। শুধু পোস্ট করা জার্নাল।',
    },
    'widget.gross_profit.title': { en: 'Gross profit', bn: 'মোট মুনাফা' },
    'widget.gross_profit.description': {
        en: 'Income less cost of goods sold for the range, from the income statement. Cost is released at invoice.',
        bn: 'এই সময়ের আয় থেকে বিক্রিত পণ্যের খরচ বাদ, আয় বিবরণী থেকে। খরচ ধরা হয় ইনভয়েসের সময়।',
    },
    'widget.bank_unmatched.title': { en: 'Bank lines to match', bn: 'মেলানোর বাকি ব্যাংক লাইন' },
    'widget.bank_unmatched.description': {
        en: 'Imported bank statement lines not yet matched to a posting or set aside.',
        bn: 'আনা ব্যাংক স্টেটমেন্টের যে লাইন এখনও কোনো পোস্টিংয়ের সাথে মেলানো বা সরিয়ে রাখা হয়নি।',
    },
    'widget.period_lock.title': { en: 'Books closed through', bn: 'হিসাব বন্ধ যে তারিখ পর্যন্ত' },
    'widget.period_lock.description': {
        en: 'Nothing can be posted on or before the closed date unless the lock is opened for a correction.',
        bn: 'সংশোধনের জন্য লক না খোলা পর্যন্ত বন্ধ তারিখে বা তার আগে কিছু পোস্ট করা যায় না।',
    },
    'widget.period_lock.empty': { en: 'The books have never been closed.', bn: 'হিসাব কখনও বন্ধ করা হয়নি।' },
    'widget.income_trend.title': { en: 'Sales income', bn: 'বিক্রির আয়' },
    'widget.income_trend.description': {
        en: 'Posted income by journal date (ex-VAT, net of returns and discounts) and gross profit after cost of goods sold.',
        bn: 'জার্নালের তারিখ অনুযায়ী পোস্ট করা আয় (ভ্যাট ছাড়া, ফেরত ও ছাড় বাদে) আর বিক্রিত পণ্যের খরচের পরে মোট মুনাফা।',
    },
    'widget.receipts_vs_payments.title': { en: 'Money in and out', bn: 'টাকা এল ও গেল' },
    'widget.receipts_vs_payments.description': {
        en: 'Receipts and payments through cash, bank and wallets by journal date. Transfers between your own accounts are left out.',
        bn: 'জার্নালের তারিখ অনুযায়ী নগদ, ব্যাংক ও ওয়ালেটে আদায় ও পরিশোধ। নিজের অ্যাকাউন্টগুলোর মধ্যে স্থানান্তর বাদ।',
    },
    'widget.receivables_ageing.title': { en: 'Receivables', bn: 'পাওনা' },
    'widget.receivables_ageing.description': {
        en: 'What customers owe as at today, by how overdue it is. Five buckets always, so an empty one reads as empty.',
        bn: 'আজ পর্যন্ত ক্রেতাদের কাছে পাওনা, কত দিন বকেয়া সেই অনুযায়ী। সবসময় পাঁচটি ভাগ, তাই খালি ভাগ খালিই দেখায়।',
    },
    'widget.payables_ageing.title': { en: 'Payables', bn: 'দেনা' },
    'widget.payables_ageing.description': {
        en: 'What we owe suppliers as at today, by age, with what falls due in the next seven days.',
        bn: 'আজ পর্যন্ত সরবরাহকারীদের কাছে আমাদের দেনা, বয়স অনুযায়ী, আর আগামী সাত দিনে যা দিতে হবে।',
    },
    'widget.money_accounts.title': { en: 'Balance per account', bn: 'প্রতি অ্যাকাউন্টের ব্যালান্স' },
    'widget.money_accounts.description': {
        en: 'Every active account with its posted balance. Courier and gateway lines are money owed to us, not cash.',
        bn: 'প্রতিটি চালু অ্যাকাউন্ট ও তার পোস্ট করা ব্যালান্স। কুরিয়ার ও গেটওয়ের লাইন আমাদের পাওনা টাকা, নগদ নয়।',
    },
    'widget.finance_approvals.title': { en: 'Money approvals for you', bn: 'আপনার জন্য টাকার অনুমোদন' },
    'widget.finance_approvals.description': {
        en: 'Vouchers, payments, transfers, refunds, claims and payroll you can decide now.',
        bn: 'ভাউচার, পরিশোধ, স্থানান্তর, ফেরত, দাবি আর বেতন, যার সিদ্ধান্ত আপনি এখন দিতে পারেন।',
    },
    'widget.claims_to_pay.title': { en: 'Expense claims to pay', bn: 'পরিশোধের বাকি খরচের দাবি' },
    'widget.claims_to_pay.description': {
        en: 'Approved claims waiting to be paid back or added to payroll.',
        bn: 'অনুমোদিত দাবি, যা ফেরত দেওয়া বা বেতনে যোগ করার অপেক্ষায়।',
    },
    'widget.sales_vs_target.title': { en: 'My sales against target', bn: 'লক্ষ্যের তুলনায় আমার বিক্রি' },
    'widget.sales_vs_target.description': {
        en: 'Invoices you raised (ex-VAT, less returns) today and this month, against the target set for you.',
        bn: 'আজ ও এই মাসে আপনার করা ইনভয়েস (ভ্যাট ছাড়া, ফেরত বাদে), আপনার জন্য ঠিক করা লক্ষ্যের তুলনায়।',
    },
    'widget.sales_vs_target.empty': { en: 'No target is set for you this month.', bn: 'এই মাসে আপনার জন্য কোনো লক্ষ্য ঠিক করা নেই।' },
    'widget.my_orders.title': { en: 'My orders', bn: 'আমার অর্ডার' },
    'widget.my_orders.description': {
        en: 'Sales orders you entered in the range, by order date, and their agreed value. Operational.',
        bn: 'এই সময়ে আপনার লেখা বিক্রয় অর্ডার, অর্ডারের তারিখ অনুযায়ী, আর তাদের ঠিক হওয়া মূল্য। চলতি কাজের হিসাব।',
    },
    'widget.my_orders.empty': { en: 'No orders from you in this range yet.', bn: 'এই সময়ে আপনার কোনো অর্ডার এখনও নেই।' },
    'widget.orders_needing_action.title': { en: 'Orders needing action', bn: 'যে অর্ডারে কাজ বাকি' },
    'widget.orders_needing_action.description': {
        en: 'Your open orders: sent back to you, waiting for approval, or approved and ready to deliver.',
        bn: 'আপনার খোলা অর্ডার: আপনার কাছে ফেরত পাঠানো, অনুমোদনের অপেক্ষায়, বা অনুমোদিত ও পাঠানোর জন্য তৈরি।',
    },
    'widget.orders_needing_action.empty': {
        en: 'None of your orders needs anything right now.',
        bn: 'এই মুহূর্তে আপনার কোনো অর্ডারে কিছু করার নেই।',
    },
    'widget.orders_by_state.title': { en: 'Orders by state', bn: 'অবস্থা অনুযায়ী অর্ডার' },
    'widget.orders_by_state.description': {
        en: 'Open queues as at now; delivered and cancelled by order date in the range. Orders entered in the ERP only.',
        bn: 'এখনকার খোলা সারি; ডেলিভারি ও বাতিল এই সময়ের অর্ডারের তারিখ অনুযায়ী। শুধু ইআরপিতে লেখা অর্ডার।',
    },
    'widget.orders_to_dispatch.title': { en: 'Orders awaiting dispatch', bn: 'পাঠানোর অপেক্ষায় অর্ডার' },
    'widget.orders_to_dispatch.description': {
        en: 'Approved orders not yet fully delivered, oldest first. Operational.',
        bn: 'অনুমোদিত অর্ডার যা এখনও পুরো ডেলিভারি হয়নি, পুরনোটা আগে। চলতি কাজের হিসাব।',
    },
    'widget.orders_to_dispatch.empty': { en: 'Every approved order has gone out.', bn: 'অনুমোদিত সব অর্ডার পাঠানো হয়ে গেছে।' },
    'widget.top_products.title': { en: 'Top products', bn: 'শীর্ষ পণ্য' },
    'widget.top_products.description': {
        en: 'Invoiced value per product in the range (line totals after line discounts, before VAT). Opening invoices excluded.',
        bn: 'এই সময়ে প্রতি পণ্যের ইনভয়েস মূল্য (লাইনের ছাড়ের পরে, ভ্যাটের আগে)। শুরুর ইনভয়েস বাদ।',
    },
    'widget.courier_parcels_by_status.title': { en: 'Parcels by courier status', bn: 'কুরিয়ারের অবস্থা অনুযায়ী পার্সেল' },
    'widget.courier_parcels_by_status.description': {
        en: 'Open parcels as at now; delivered, returned and other final words by the date the courier said them, in the range. Operational, from the updates the courier sends.',
        bn: 'এখনকার খোলা পার্সেল; ডেলিভারি, ফেরত ও অন্য শেষ অবস্থা কুরিয়ার যে তারিখে জানিয়েছে সেই অনুযায়ী, এই সময়ের মধ্যে। চলতি কাজের হিসাব, কুরিয়ারের নিজের হালনাগাদ থেকে।',
    },
    'widget.courier_parcels_attention.title': { en: 'Parcels needing attention', bn: 'নজর দরকার এমন পার্সেল' },
    'widget.courier_parcels_attention.description': {
        en: 'Booking answer lost, delivery failed, on hold, lost or damaged, or more than 3 days in one courier status. As at now.',
        bn: 'বুকিংয়ের উত্তর হারানো, ডেলিভারি ব্যর্থ, আটকে আছে, হারানো বা নষ্ট, অথবা কুরিয়ারের একই অবস্থায় 3 দিনের বেশি। এখন পর্যন্ত।',
    },
    'widget.courier_parcels_attention.empty': { en: 'Every parcel is moving.', bn: 'সব পার্সেল চলছে।' },
    'widget.courier_cod_chain.title': { en: 'Cash on delivery chain', bn: 'ক্যাশ অন ডেলিভারির ধাপ' },
    'widget.courier_cod_chain.description': {
        en: 'Where the cash on delivery of each parcel stands: with the courier, reported collected, recorded in the ERP, settled on a posted statement, or overdue by the days in the finance policy. Operational, from the parcels.',
        bn: 'প্রতিটি পার্সেলের ক্যাশ অন ডেলিভারি কোথায়: কুরিয়ারের কাছে, আদায় হয়েছে বলে জানানো, ইআরপিতে লেখা, পোস্ট করা স্টেটমেন্টে মেটানো, অথবা হিসাব নীতির দিন পেরিয়ে বকেয়া। চলতি কাজের হিসাব, পার্সেল থেকে।',
    },
    'widget.courier_balances.title': { en: 'Money with couriers', bn: 'কুরিয়ারের কাছে টাকা' },
    'widget.courier_balances.description': {
        en: 'Per courier: cash on delivery the courier holds for us (1145) and what we owe the courier (2165). Posted journals only.',
        bn: 'প্রতি কুরিয়ার: আমাদের হয়ে কুরিয়ারের কাছে থাকা ক্যাশ অন ডেলিভারি (1145) আর কুরিয়ারের কাছে আমাদের দেনা (2165)। শুধু পোস্ট করা জার্নাল।',
    },
    'widget.courier_statements_pending.title': { en: 'Courier statements to post', bn: 'পোস্ট করার বাকি কুরিয়ার স্টেটমেন্ট' },
    'widget.courier_statements_pending.description': {
        en: 'Statements imported from a courier and still in draft, so their payout is not in the books yet. As at now.',
        bn: 'কুরিয়ার থেকে আনা স্টেটমেন্ট যা এখনও খসড়া, তাই তাদের পরিশোধ এখনও হিসাবে ওঠেনি। এখন পর্যন্ত।',
    },
    'widget.courier_statements_pending.empty': { en: 'Every imported statement is posted.', bn: 'আনা সব স্টেটমেন্ট পোস্ট হয়ে গেছে।' },
    'widget.courier_integration_health.title': { en: 'Courier connections', bn: 'কুরিয়ার সংযোগ' },
    'widget.courier_integration_health.description': {
        en: 'Per courier account: switched on or not, the last call that worked, the last error, whether its webhook is paired, and the last update the courier pushed to us. As at now.',
        bn: 'প্রতি কুরিয়ার অ্যাকাউন্ট: চালু কি না, শেষ যে কল কাজ করেছে, শেষ ত্রুটি, ওয়েবহুক জোড়া লেগেছে কি না, আর কুরিয়ারের পাঠানো শেষ হালনাগাদ। এখন পর্যন্ত।',
    },
    'widget.attendance_today.title': { en: 'Attendance today', bn: 'আজকের হাজিরা' },
    'widget.attendance_today.description': {
        en: 'Everybody in this business today, by status. Not recorded yet means no punch has been processed, not absent.',
        bn: 'আজ এই ব্যবসার সবাই, অবস্থা অনুযায়ী। এখনও লেখা হয়নি মানে কোনো পাঞ্চ প্রক্রিয়া হয়নি, অনুপস্থিত নয়।',
    },
    'widget.team_attendance.title': { en: 'My team today', bn: 'আজ আমার দল' },
    'widget.team_attendance.description': {
        en: 'The people you supervise, by status today. Not recorded yet means no punch has been processed, not absent.',
        bn: 'আপনি যাদের তত্ত্বাবধান করেন, আজকের অবস্থা অনুযায়ী। এখনও লেখা হয়নি মানে কোনো পাঞ্চ প্রক্রিয়া হয়নি, অনুপস্থিত নয়।',
    },
    'widget.team_attendance.empty': { en: 'Nobody reports to you in this business.', bn: 'এই ব্যবসায় কেউ আপনার অধীনে নেই।' },
    'widget.leave_pending.title': { en: 'Leave requests pending', bn: 'অপেক্ষমাণ ছুটির আবেদন' },
    'widget.leave_pending.description': {
        en: 'Requests waiting for a decision, oldest start date first.',
        bn: 'সিদ্ধান্তের অপেক্ষায় আবেদন, যার শুরুর তারিখ পুরনো সেটি আগে।',
    },
    'widget.leave_pending.empty': { en: 'No leave is waiting for a decision.', bn: 'কোনো ছুটি সিদ্ধান্তের অপেক্ষায় নেই।' },
    'widget.approvals_waiting.title': { en: 'Waiting for you', bn: 'আপনার অপেক্ষায়' },
    'widget.approvals_waiting.description': {
        en: 'Approvals you can decide now, across every module. Not narrowed by the date range: an approval belongs to a person, not a period.',
        bn: 'সব মডিউলের যে অনুমোদনের সিদ্ধান্ত আপনি এখন দিতে পারেন। তারিখের সীমায় ছাঁটা নয়: অনুমোদন একজন মানুষের, কোনো সময়ের নয়।',
    },
    'widget.approvals_waiting.empty': { en: 'Nothing is waiting on you.', bn: 'আপনার অপেক্ষায় কিছু নেই।' },
    'widget.followups_due.title': { en: 'My follow-ups due', bn: 'আমার বাকি ফলো-আপ' },
    'widget.followups_due.description': {
        en: 'Customer follow-ups assigned to you, due today or already late.',
        bn: 'আপনাকে দেওয়া ক্রেতার ফলো-আপ, আজ করার কথা বা ইতিমধ্যে দেরি।',
    },
    'widget.followups_due.empty': { en: 'No follow-ups due. Well done.', bn: 'কোনো ফলো-আপ বাকি নেই। চমৎকার।' },
    'widget.stock_alerts.title': { en: 'Stock to watch', bn: 'নজরে রাখার স্টক' },
    'widget.stock_alerts.description': {
        en: 'Products at or under their low-stock line, out of stock, and batches expired or inside their warning window. Operational.',
        bn: 'কম-স্টকের সীমায় বা তার নিচে থাকা পণ্য, স্টক শেষ, আর মেয়াদ শেষ বা সতর্কতার সময়ের ভেতরের ব্যাচ। চলতি কাজের হিসাব।',
    },
    'widget.stock_lookup.title': { en: 'Stock lookup', bn: 'স্টক খোঁজা' },
    'widget.stock_lookup.description': {
        en: 'Sellable products and what is on hand across warehouses. Nothing is reserved for open orders yet.',
        bn: 'বিক্রিযোগ্য পণ্য আর সব গুদামে হাতে কত আছে। খোলা অর্ডারের জন্য এখনও কিছু আলাদা রাখা হয় না।',
    },
    'widget.business_health.title': { en: 'Business health', bn: 'ব্যবসার স্বাস্থ্য' },
    'widget.business_health.description': {
        en: 'The morning walk around the business: each line links to where the fix lives.',
        bn: 'সকালে পুরো ব্যবসা ঘুরে দেখা: প্রতিটি লাইন যেখানে সমাধান সেখানে নিয়ে যায়।',
    },
    'widget.my_attendance.title': { en: 'My attendance', bn: 'আমার হাজিরা' },
    'widget.my_attendance.description': {
        en: 'Today and the current payroll period, as the attendance engine recorded them.',
        bn: 'আজ আর চলতি বেতনের সময়, হাজিরার হিসাব যেমন লিখেছে।',
    },
    'widget.my_tasks.title': { en: 'My to-dos and approvals', bn: 'আমার করণীয় ও অনুমোদন' },
    'widget.my_tasks.description': {
        en: 'Your open to-dos, approvals waiting for you, and your requests still waiting on others.',
        bn: 'আপনার খোলা করণীয়, আপনার অপেক্ষায় থাকা অনুমোদন, আর অন্যদের অপেক্ষায় থাকা আপনার আবেদন।',
    },
    'widget.my_payslip.title': { en: 'My latest payslip', bn: 'আমার শেষ বেতনের স্লিপ' },
    'widget.my_payslip.description': {
        en: 'Your most recent approved payslip. Only you (and payroll) can see it.',
        bn: 'আপনার সবশেষ অনুমোদিত বেতনের স্লিপ। শুধু আপনি (আর বেতন বিভাগ) দেখতে পারেন।',
    },
    'widget.my_payslip.empty': { en: 'No approved payslip yet.', bn: 'এখনও কোনো অনুমোদিত বেতনের স্লিপ নেই।' },
    'widget.my_leave.title': { en: 'My leave', bn: 'আমার ছুটি' },
    'widget.my_leave.description': {
        en: 'Days left per leave type this year, and your requests waiting for a decision.',
        bn: 'এই বছর প্রতি ধরনের ছুটির বাকি দিন, আর সিদ্ধান্তের অপেক্ষায় থাকা আপনার আবেদন।',
    },
    'widget.my_claims.title': { en: 'My expense claims', bn: 'আমার খরচের দাবি' },
    'widget.my_claims.description': { en: 'Your claims and where each one stands.', bn: 'আপনার দাবিগুলো আর প্রতিটি কোথায় আছে।' },
    'widget.my_claims.empty': { en: 'You have no expense claims.', bn: 'আপনার কোনো খরচের দাবি নেই।' },
    'widget.needs_attention.title': { en: 'Needs attention', bn: 'নজর দরকার' },
    'widget.needs_attention.description': {
        en: 'The most urgent things to act on, ranked by severity and age.',
        bn: 'সবচেয়ে জরুরি কাজগুলো, গুরুত্ব ও বয়স অনুযায়ী সাজানো।',
    },
    'widget.needs_attention.empty': { en: 'Nothing needs attention right now.', bn: 'এই মুহূর্তে কিছুতে নজর দরকার নেই।' },
    // ── The colour pass (11 Oct 2026): hero band and card details ──────
    'dash.hero.context': { en: '{range}, {brand}', bn: '{range} সময়ে, {brand}' },
    'dash.hero.all_brands': { en: 'all brands', bn: 'সব ব্র্যান্ড' },
    'dash.hero.aria': { en: '{title}: headline figures', bn: '{title}: প্রধান সংখ্যাগুলো' },
    'dash.hero.could_not_load': { en: 'Could not load', bn: 'লোড করা যায়নি' },
    'dash.hero.nothing_yet': { en: 'Nothing yet', bn: 'এখনও কিছু নেই' },
    'dash.hero.vs_previous': { en: 'Against the previous period of the same length', bn: 'একই দৈর্ঘ্যের আগের সময়ের তুলনায়' },
    'dash.hero.better': { en: 'better than the previous period', bn: 'আগের সময়ের চেয়ে ভালো' },
    'dash.hero.worse': { en: 'worse than the previous period', bn: 'আগের সময়ের চেয়ে খারাপ' },
    'dash.hero.over_range': { en: '{label} over the range', bn: 'এই সময়ে {label}' },
    'dash.hero.all_clear': { en: 'All clear, nothing waiting', bn: 'সব ঠিক আছে, কিছু অপেক্ষায় নেই' },
    'dash.hero.none_yet': { en: 'None in this range yet', bn: 'এই সময়ে এখনও কিছু নেই' },
    'dash.hero.no_compare': { en: 'No earlier figure to compare', bn: 'তুলনার জন্য আগের কোনো সংখ্যা নেই' },
    'dash.hero.open': { en: '{label}: {value}. Open the full screen', bn: '{label}: {value}। পুরো স্ক্রিন খুলুন' },
    'dash.hero.no_target': { en: 'No target set', bn: 'কোনো লক্ষ্য ঠিক করা নেই' },
    'dash.hero.days_present': { en: 'Days present', bn: 'উপস্থিত দিন' },
    'dash.colour.of_target': { en: 'of {target} target', bn: '{target} লক্ষ্যের মধ্যে' },
    'dash.colour.in_all': { en: 'in all', bn: 'সব মিলিয়ে' },
    'dash.colour.none': { en: 'None', bn: 'নেই' },
    // ── Getting started (Owner, see-all only) ───────────────────────────
    'dash.start.title': { en: 'Getting started', bn: 'শুরু করার ধাপ' },
    'dash.start.progress': { en: '{done} of {total} done', bn: '{total}টির মধ্যে {done}টি হয়েছে' },
    'dash.start.why': {
        en: 'The figures below stay at Tk 0 until the opening numbers are in.',
        bn: 'শুরুর হিসাব না ওঠা পর্যন্ত নিচের সংখ্যাগুলো ৳0 থাকবে।',
    },
    'dash.start.why_rest': {
        en: 'A few things are still missing before every figure is complete.',
        bn: 'সব সংখ্যা পূর্ণ হতে এখনও কয়েকটি জিনিস বাকি।',
    },
    'dash.start.dismiss': { en: 'Hide this', bn: 'এটি লুকান' },
    'dash.start.dismiss_label': { en: 'Hide the Getting started card for me', bn: 'আমার জন্য শুরু করার ধাপ কার্ডটি লুকান' },
    'dash.start.not_saved': { en: 'Could not hide the card. Try again.', bn: 'কার্ডটি লুকানো যায়নি। আবার চেষ্টা করুন।' },
    'dash.start.done': { en: 'Done', bn: 'হয়েছে' },
    'dash.start.to_do': { en: 'Still to do', bn: 'এখনও বাকি' },
    'dash.start.opening_balances.title': { en: 'Opening balances journal', bn: 'শুরুর ব্যালান্সের জার্নাল' },
    'dash.start.opening_balances.why': {
        en: 'Cash, bank, receivables and payables on the day the books start.',
        bn: 'হিসাব শুরুর দিনের নগদ, ব্যাংক, পাওনা আর দেনা।',
    },
    'dash.start.product_costs.title': { en: 'Products with a cost', bn: 'খরচসহ পণ্য' },
    'dash.start.product_costs.why': {
        en: 'Without a cost, gross profit and stock value cannot be worked out.',
        bn: 'খরচ ছাড়া মোট লাভ আর স্টকের মূল্য হিসাব করা যায় না।',
    },
    'dash.start.product_costs.count': { en: '{count} of {of} products', bn: '{of}টি পণ্যের মধ্যে {count}টি' },
    'dash.start.opening_stock.title': { en: 'Opening stock', bn: 'শুরুর স্টক' },
    'dash.start.opening_stock.why': { en: 'What the warehouse held on the first day, with its cost.', bn: 'প্রথম দিনে গুদামে যা ছিল, খরচসহ।' },
    'dash.start.customers.title': { en: 'Customers', bn: 'গ্রাহক' },
    'dash.start.customers.why': { en: 'Needed to sell on account and to track who owes you.', bn: 'বাকিতে বিক্রি আর কে কত দেবে তা দেখতে লাগবে।' },
    'dash.start.suppliers.title': { en: 'Suppliers', bn: 'সরবরাহকারী' },
    'dash.start.suppliers.why': { en: 'Needed for purchases and to track what you owe.', bn: 'কেনাকাটা আর আপনার দেনা দেখতে লাগবে।' },
    'dash.start.count': { en: '{count} added', bn: '{count}টি যোগ হয়েছে' },
    'dash.start.courier.title': { en: 'Courier connected and webhook paired', bn: 'কুরিয়ার যুক্ত ও ওয়েবহুক জোড়া' },
    'dash.start.courier.why': {
        en: 'Parcel updates and cash on delivery arrive on their own once paired.',
        bn: 'জোড়া লাগলে পার্সেলের খবর আর ক্যাশ অন ডেলিভারি নিজে থেকেই আসে।',
    },
    'dash.start.courier.count': { en: '{count} of {of} connected accounts paired', bn: '{of}টি যুক্ত অ্যাকাউন্টের মধ্যে {count}টি জোড়া' },

    // ── All-zero buckets: one calm line instead of empty bars ────────────
    'dash.zero.receivables_ageing': { en: 'Nothing owed to you right now.', bn: 'এই মুহূর্তে আপনার কোনো পাওনা নেই।' },
    'dash.zero.payables_ageing': { en: 'Nothing you owe right now.', bn: 'এই মুহূর্তে আপনার কোনো দেনা নেই।' },
    'dash.zero.courier_cod_chain': { en: 'No cash on delivery in the chain.', bn: 'চেইনে কোনো ক্যাশ অন ডেলিভারি নেই।' },
    'dash.zero.orders_by_state': { en: 'No orders in this range.', bn: 'এই সময়ে কোনো অর্ডার নেই।' },
    'dash.zero.default': { en: 'Nothing here right now.', bn: 'এই মুহূর্তে এখানে কিছু নেই।' },

    // ── Section chips ────────────────────────────────────────────────────
    'dash.jump.label': { en: 'Jump to a section', bn: 'একটি অংশে যান' },
    'dash.jump.Money': { en: 'Money', bn: 'টাকা' },
    'dash.jump.Sales': { en: 'Sales', bn: 'বিক্রি' },
    'dash.jump.Couriers': { en: 'Couriers', bn: 'কুরিয়ার' },
    'dash.jump.People': { en: 'People', bn: 'লোকজন' },
    'dash.jump.Stock': { en: 'Stock', bn: 'স্টক' },
    'dash.jump.Work': { en: 'Work', bn: 'কাজ' },
    'dash.jump.Me': { en: 'Me', bn: 'আমি' },
} satisfies Record<string, Entry>;
