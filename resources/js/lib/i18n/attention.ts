import type { Entry } from './entry';

/**
 * Needs attention and Settings > Failed jobs (11 Oct 2026).
 *
 * `attention.reason.*` mirror the English sentences the sources write on the
 * server (App\Support\Attention\Sources), with the same {placeholders}: the
 * figures, names and dates in them arrive already written and never translate.
 */
export const attention = {
    // ── The page ────────────────────────────────────────────────────────
    'attention.title': { en: 'Needs attention', bn: 'মনোযোগ দরকার' },
    'attention.subtitle': {
        en: 'Everything waiting on somebody, most urgent first. Each line links to where it is fixed.',
        bn: 'যা কিছু কারও কাজের অপেক্ষায়, সবচেয়ে জরুরিটা আগে। প্রতিটি লাইন সেই পাতায় নিয়ে যায় যেখানে সমাধান হয়।',
    },
    'attention.all_clear': { en: 'All clear.', bn: 'সব ঠিক আছে।' },
    'attention.all_clear_hint': {
        en: 'Nothing you can see is waiting on anyone right now.',
        bn: 'আপনি যা দেখতে পান তার কিছুই এখন কারও অপেক্ষায় নেই।',
    },
    'attention.none_match': { en: 'Nothing matches these filters.', bn: 'এই ফিল্টারে কিছু মেলেনি।' },
    'attention.clear_filters': { en: 'Clear filters', bn: 'ফিল্টার মুছুন' },
    'attention.showing': { en: 'Showing {shown} of {total}', bn: '{total}টির মধ্যে {shown}টি দেখানো হচ্ছে' },
    'attention.failed_note': {
        en: 'Could not be read just now: {areas}. Everything else on this list is complete.',
        bn: 'এই মুহূর্তে পড়া যায়নি: {areas}। এই তালিকার বাকি সব সম্পূর্ণ।',
    },
    'attention.updated': { en: 'Updated {time}', bn: 'হালনাগাদ {time}' },
    'attention.open': { en: 'Open', bn: 'খুলুন' },
    'attention.retry': { en: 'Retry', bn: 'আবার চালান' },
    'attention.forget': { en: 'Forget', bn: 'মুছে দিন' },
    'attention.forget_confirm': {
        en: 'Remove this failed job without running it again?',
        bn: 'এই ব্যর্থ কাজটি আবার না চালিয়ে মুছে দেবেন?',
    },
    'attention.more': { en: 'See all {count}', bn: 'সব {count}টি দেখুন' },

    // ── Filters and columns ─────────────────────────────────────────────
    'attention.filter.severity': { en: 'Severity', bn: 'গুরুত্ব' },
    'attention.filter.all_severities': { en: 'All severities', bn: 'সব গুরুত্ব' },
    'attention.filter.area': { en: 'Area', bn: 'বিভাগ' },
    'attention.filter.all_areas': { en: 'All areas', bn: 'সব বিভাগ' },
    'attention.filter.brand': { en: 'Brand', bn: 'ব্র্যান্ড' },
    'attention.filter.all_brands': { en: 'All brands', bn: 'সব ব্র্যান্ড' },
    'attention.filter.mine': { en: 'Assigned to me', bn: 'আমার দায়িত্বে' },
    'attention.col.severity': { en: 'Severity', bn: 'গুরুত্ব' },
    'attention.col.what': { en: 'What', bn: 'কী' },
    'attention.col.owner': { en: 'Who acts', bn: 'কে করবেন' },
    'attention.col.age': { en: 'Waiting', bn: 'অপেক্ষায়' },
    'attention.col.action': { en: 'Action', bn: 'কাজ' },

    // ── Severity, area, owner, age ──────────────────────────────────────
    'attention.severity.critical': { en: 'Critical', bn: 'জরুরি' },
    'attention.severity.high': { en: 'High', bn: 'বেশি' },
    'attention.severity.normal': { en: 'Normal', bn: 'সাধারণ' },
    'attention.area.couriers': { en: 'Couriers', bn: 'কুরিয়ার' },
    'attention.area.money': { en: 'Money', bn: 'টাকা' },
    'attention.area.stock': { en: 'Stock', bn: 'স্টক' },
    'attention.area.work': { en: 'Work', bn: 'কাজ' },
    'attention.area.system': { en: 'System', bn: 'সিস্টেম' },
    'attention.owner.you': { en: 'You', bn: 'আপনি' },
    'attention.owner.anyone': { en: 'Anyone who can', bn: 'যিনি পারেন' },
    'attention.role.owner': { en: 'Owner', bn: 'মালিক' },
    'attention.role.finance': { en: 'Finance', bn: 'হিসাব বিভাগ' },
    'attention.role.manager': { en: 'Manager', bn: 'ম্যানেজার' },
    'attention.role.staff': { en: 'Staff', bn: 'স্টাফ' },
    'attention.age.today': { en: 'Today', bn: 'আজ' },
    'attention.age.days': { en: '{days} d', bn: '{days} দিন' },
    'attention.age.unknown': { en: '-', bn: '- (অজানা)' },

    // ── Reasons (server sentences, same placeholders) ───────────────────
    'attention.reason.cod_overdue': {
        en: '{count} delivered parcels on {courier} not settled after {days} days (Tk {amount}).',
        bn: '{courier}-এর {count}টি ডেলিভারি হওয়া পার্সেলের টাকা {days} দিন পরেও সেটেল হয়নি (Tk {amount})।',
    },
    'attention.reason.statement_problems': {
        en: 'Statement {reference} from {courier}: {problems} rows to fix ({unmatched} unmatched), {errors} rows the import could not read.',
        bn: '{courier}-এর স্টেটমেন্ট {reference}: {problems}টি সারি ঠিক করতে হবে ({unmatched}টি মেলেনি), {errors}টি সারি ইমপোর্ট পড়তে পারেনি।',
    },
    'attention.reason.door_difference': {
        en: 'Parcel {parcel}: Tk {collected} collected at the door against Tk {due} due. Decide the difference.',
        bn: 'পার্সেল {parcel}: পাওনা Tk {due}, দরজায় আদায় Tk {collected}। পার্থক্যের সিদ্ধান্ত দিন।',
    },
    'attention.reason.parcel_problem': {
        en: 'Parcel {parcel} ({courier}): the courier says {status}.',
        bn: 'পার্সেল {parcel} ({courier}): কুরিয়ার বলছে {status}।',
    },
    'attention.reason.parcel_stuck': {
        en: 'Parcel {parcel} ({courier}) has been {status} for more than {days} days.',
        bn: 'পার্সেল {parcel} ({courier}) {days} দিনের বেশি ধরে {status} অবস্থায় আছে।',
    },
    'attention.reason.booking_unknown': {
        en: 'Booking for {challan} on {account}: the answer from the courier was lost. Look it up or settle it by hand.',
        bn: '{account}-এ {challan}-এর বুকিং: কুরিয়ারের উত্তর হারিয়ে গেছে। খুঁজে দেখুন অথবা হাতে মীমাংসা করুন।',
    },
    'attention.reason.courier_failing': {
        en: '{account}: calls to the courier are failing. Last error: {error}',
        bn: '{account}: কুরিয়ারের সাথে যোগাযোগ ব্যর্থ হচ্ছে। শেষ ত্রুটি: {error}',
    },
    'attention.reason.courier_stale': {
        en: '{account}: {count} parcels are open and nothing has been read from the courier for over {hours} hours.',
        bn: '{account}: {count}টি পার্সেল খোলা, অথচ {hours} ঘণ্টার বেশি সময় কুরিয়ার থেকে কিছু পড়া হয়নি।',
    },
    'attention.reason.courier_webhook_silent': {
        en: '{account}: the courier has pushed no update for over {hours} hours while {count} parcels are open.',
        bn: '{account}: {count}টি পার্সেল খোলা থাকা অবস্থায় কুরিয়ার {hours} ঘণ্টার বেশি কোনো আপডেট পাঠায়নি।',
    },
    // The webhook pairing guard (11 Oct 2026)
    'attention.reason.courier_pairing_missing_echo': {
        en: "{account} tried to connect its webhook but the courier's reply value is missing. Open {page}, copy the value from the line \"The header {header} value should be exactly ...\", paste it in Settings > Couriers > Edit > Courier's reply value, save, then press connect on the courier's page again.",
        bn: '{account} তার ওয়েবহুক যুক্ত করার চেষ্টা করেছে, কিন্তু কুরিয়ারের উত্তর-মান (reply value) নেই। {page} খুলুন, "The header {header} value should be exactly ..." লাইনের মানটি কপি করুন, সেটিংস > কুরিয়ার > সম্পাদনা > কুরিয়ারের উত্তর-মান ঘরে বসিয়ে সংরক্ষণ করুন, তারপর কুরিয়ারের পাতায় আবার connect চাপুন।',
    },
    'attention.reason.courier_pairing_retry': {
        en: "{account}: the courier's reply value is saved now. Press connect on {page} again to finish pairing the webhook.",
        bn: '{account}: কুরিয়ারের উত্তর-মান এখন সংরক্ষিত। ওয়েবহুক যুক্ত করা শেষ করতে {page}-এ আবার connect চাপুন।',
    },
    'attention.reason.courier_pairing_bad_secret': {
        en: "{account} tried to connect its webhook but the webhook secret in the courier's form does not match the ERP. Copy the secret again from Settings > Couriers (Rotate webhook secret shows a new one once), paste it in the courier's form, then connect again.",
        bn: '{account} তার ওয়েবহুক যুক্ত করার চেষ্টা করেছে, কিন্তু কুরিয়ারের ফর্মে দেওয়া ওয়েবহুক সিক্রেট ইআরপির সাথে মেলেনি। সেটিংস > কুরিয়ার থেকে সিক্রেটটি আবার কপি করুন ("ওয়েবহুক সিক্রেট বদলান" নতুন একটি একবার দেখায়), কুরিয়ারের ফর্মে বসান, তারপর আবার connect চাপুন।',
    },
    'attention.reason.courier_pairing_not_ready': {
        en: '{account}: the courier\'s reply value is not saved, so connecting its webhook would fail with "Validation error". Copy it from {page} into Settings > Couriers first.',
        bn: '{account}: কুরিয়ারের উত্তর-মান সংরক্ষিত নেই, তাই ওয়েবহুক যুক্ত করতে গেলে "Validation error" আসবে। আগে {page} থেকে মানটি কপি করে সেটিংস > কুরিয়ার-এ বসান।',
    },
    'attention.reason.loss_claim_open': {
        en: 'Claim on parcel {parcel} ({courier}): Tk {amount} claimed, no answer from the courier yet.',
        bn: 'পার্সেল {parcel} ({courier})-এর দাবি: Tk {amount} দাবি করা হয়েছে, কুরিয়ারের উত্তর এখনও আসেনি।',
    },
    'attention.reason.loss_claim_unpaid': {
        en: 'Claim on parcel {parcel} ({courier}) was accepted; the compensation has not arrived.',
        bn: 'পার্সেল {parcel} ({courier})-এর দাবি মেনে নেওয়া হয়েছে; ক্ষতিপূরণ এখনও আসেনি।',
    },
    'attention.reason.fee_variance': {
        en: '{count} of {clean} parcels in the last {days} days cost more than {percent}% away from our estimate.',
        bn: 'গত {days} দিনে {clean}টির মধ্যে {count}টি পার্সেলের খরচ আমাদের অনুমানের চেয়ে {percent}%-এর বেশি আলাদা।',
    },
    'attention.reason.bank_unmatched': {
        en: '{count} bank lines (Tk {amount}) are not matched to a posting.',
        bn: '{count}টি ব্যাংক লাইন (Tk {amount}) কোনো পোস্টিংয়ের সাথে মেলানো হয়নি।',
    },
    'attention.reason.approvals_waiting': {
        en: '{count} approvals are waiting for your decision.',
        bn: '{count}টি অনুমোদন আপনার সিদ্ধান্তের অপেক্ষায়।',
    },
    'attention.reason.stock_expired': {
        en: '{count} batches in stock are past their expiry date.',
        bn: 'স্টকে {count}টি ব্যাচের মেয়াদ পেরিয়ে গেছে।',
    },
    'attention.reason.stock_out': { en: '{count} products are out of stock.', bn: '{count}টি পণ্য স্টকে নেই।' },
    'attention.reason.stock_low': {
        en: '{count} products are at or under their low-stock line.',
        bn: '{count}টি পণ্য কম-স্টকের সীমায় বা তার নিচে।',
    },
    'attention.reason.stock_expiring': {
        en: '{count} batches expire inside their warning window.',
        bn: '{count}টি ব্যাচের মেয়াদ সতর্কতার সময়ের মধ্যে শেষ হবে।',
    },
    'attention.reason.failed_jobs': {
        en: '{count} background jobs failed. The latest, {job}, stopped on {error}.',
        bn: '{count}টি ব্যাকগ্রাউন্ড কাজ ব্যর্থ হয়েছে। সর্বশেষটি, {job}, {error}-এ থেমে গেছে।',
    },
    'attention.reason.receivables_overdue': {
        en: '{count} invoices from {customers} customers are more than {days} days overdue (Tk {amount}).',
        bn: '{customers} জন ক্রেতার {count}টি ইনভয়েস {days} দিনের বেশি বকেয়া (Tk {amount})।',
    },
    'attention.reason.payables_due': {
        en: '{count} supplier bills (Tk {amount}) fall due in the next {days} days, the first on {date}.',
        bn: 'সরবরাহকারীর {count}টি বিল (Tk {amount}) আগামী {days} দিনে পরিশোধযোগ্য, প্রথমটি {date} তারিখে।',
    },
    'attention.reason.loan_installment_overdue': {
        en: '{count} loan installments are past due (Tk {amount}) on {loans}.',
        bn: '{loans} ঋণের {count}টি কিস্তির তারিখ পেরিয়ে গেছে (Tk {amount})।',
    },
    'attention.reason.loan_installment_due': {
        en: '{count} loan installments (Tk {amount}) fall due in the next {days} days, the first on {date}.',
        bn: 'ঋণের {count}টি কিস্তি (Tk {amount}) আগামী {days} দিনে পরিশোধযোগ্য, প্রথমটি {date} তারিখে।',
    },
    'attention.reason.loan_maturity_soon': {
        en: '{count} loans mature in the next {days} days, the first on {date}.',
        bn: 'আগামী {days} দিনে {count}টি ঋণের মেয়াদ শেষ হবে, প্রথমটি {date} তারিখে।',
    },
    'attention.reason.unpriced_sellable': {
        en: '{count} of {sellable} sellable products have no selling price in force, so each refuses its first sale.',
        bn: '{sellable}টি বিক্রয়যোগ্য পণ্যের মধ্যে {count}টির কোনো চালু বিক্রয়মূল্য নেই, তাই প্রতিটির প্রথম বিক্রি আটকে যাবে।',
    },

    // ── Settings > Failed jobs ──────────────────────────────────────────
    'failed_jobs.title': { en: 'Failed jobs', bn: 'ব্যর্থ কাজ' },
    'failed_jobs.description': {
        en: 'Background jobs that failed and will not run again on their own. Retry puts one back on its queue; Forget removes it without running it.',
        bn: 'যে ব্যাকগ্রাউন্ড কাজগুলো ব্যর্থ হয়েছে এবং নিজে থেকে আর চলবে না। "আবার চালান" কাজটিকে আবার সারিতে পাঠায়; "মুছে দিন" না চালিয়ে মুছে ফেলে।',
    },
    'failed_jobs.system_wide': {
        en: 'This list is for the whole system, not one business. Errors are shortened and anything that looks like a password or key is hidden.',
        bn: 'এই তালিকা পুরো সিস্টেমের, কোনো একটি ব্যবসার নয়। ত্রুটি ছোট করে দেখানো হয় এবং পাসওয়ার্ড বা কী-এর মতো যা কিছু লুকানো থাকে।',
    },
    'failed_jobs.none': { en: 'No failed jobs. Every background job finished.', bn: 'কোনো ব্যর্থ কাজ নেই। সব ব্যাকগ্রাউন্ড কাজ শেষ হয়েছে।' },
    'failed_jobs.count': { en: '{count} failed jobs', bn: '{count}টি ব্যর্থ কাজ' },
    'failed_jobs.retry_all': { en: 'Retry all', bn: 'সব আবার চালান' },
    'failed_jobs.retry_all_confirm': {
        en: 'Put every failed job back on its queue? Each one does again whatever it was doing.',
        bn: 'সব ব্যর্থ কাজ আবার সারিতে পাঠাবেন? প্রতিটি কাজ আবার তার আগের কাজটিই করবে।',
    },
    'failed_jobs.retry_confirm': {
        en: 'Put this job back on its queue? It does again whatever it was doing.',
        bn: 'এই কাজটি আবার সারিতে পাঠাবেন? এটি আবার তার আগের কাজটিই করবে।',
    },
    'failed_jobs.col.failed_at': { en: 'Failed', bn: 'ব্যর্থ হয়েছে' },
    'failed_jobs.col.job': { en: 'Job', bn: 'কাজ' },
    'failed_jobs.col.queue': { en: 'Queue', bn: 'সারি' },
    'failed_jobs.col.error': { en: 'Error', bn: 'ত্রুটি' },
    'failed_jobs.show_details': { en: 'Show the first lines', bn: 'প্রথম লাইনগুলো দেখুন' },
} satisfies Record<string, Entry>;
