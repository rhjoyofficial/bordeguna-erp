import type { Entry } from './entry';

/**
 * Market-priced goods (Phase 2 / 1B, 17 Oct 2026): the pricing mode and rule
 * boxes on the product and category popups, the "Set today's price" cell on
 * the Receive goods step, the receipt history, the counter badge and the
 * Selling prices section. Numbers, dates and money never translate.
 */
export const marketPricing = {
    'market.mode': { en: 'How it is priced', bn: 'দাম কীভাবে ঠিক হয়' },
    'market.mode.fixed_mrp': { en: 'Fixed MRP and price list', bn: 'নির্দিষ্ট MRP ও দামের তালিকা' },
    'market.mode.market': { en: 'Market price (set from each lot)', bn: 'বাজারদর (প্রতি লটে ঠিক হয়)' },
    'market.mode_hint': {
        en: 'A market product has no printed MRP: its price is proposed at every goods receipt from what the lot cost, and the receiver confirms it. It is never priced from an MRP.',
        bn: 'বাজারদরের পণ্যে ছাপানো MRP থাকে না: প্রতিবার মাল গ্রহণের সময় লটের খরচ থেকে দাম প্রস্তাব হয়, গ্রহণকারী নিশ্চিত করেন। MRP থেকে কখনও দাম হয় না।',
    },
    'market.rules': { en: 'Market pricing rules', bn: 'বাজারদরের নিয়ম' },
    'market.rules_hint': {
        en: 'Blank boxes inherit the category. Markup is on COST; minimum margin is on the SELLING price; the step is the taka increment the price is rounded UP to.',
        bn: 'খালি ঘর ক্যাটাগরির নিয়ম নেয়। মার্কআপ খরচের উপর; ন্যূনতম মার্জিন বিক্রয়মূল্যের উপর; ধাপ হলো যে টাকার গুণিতকে দাম উপরের দিকে গোল করা হয়।',
    },
    'market.category_rules_hint': {
        en: 'Defaults for every market-priced product in this category. A product can override any one of them.',
        bn: 'এই ক্যাটাগরির প্রতিটি বাজারদরের পণ্যের জন্য স্বাভাবিক নিয়ম। যেকোনো পণ্য এর যেকোনো একটি বদলাতে পারে।',
    },
    'market.markup': { en: 'Markup on cost (%)', bn: 'খরচের উপর মার্কআপ (%)' },
    'market.min_margin': { en: 'Minimum margin on selling price (%)', bn: 'বিক্রয়মূল্যের উপর ন্যূনতম মার্জিন (%)' },
    'market.step': { en: 'Rounding step (Tk)', bn: 'গোল করার ধাপ (টাকা)' },
    'market.inherits': { en: 'inherits {value}', bn: 'ক্যাটাগরি থেকে {value}' },
    'market.inherits_none': { en: 'no rule set', bn: 'কোনো নিয়ম নেই' },
    'market.formula': {
        en: 'Suggested = round up to step of the larger of cost x (1 + markup) and cost / (1 - margin).',
        bn: 'প্রস্তাবিত দাম = খরচ x (1 + মার্কআপ) আর খরচ / (1 - মার্জিন), এর বড়টি ধাপে উপরের দিকে গোল করে।',
    },

    'market.todays_price': { en: "Today's price", bn: 'আজকের দাম' },
    'market.todays_price_since': { en: "Today's price, since {time}", bn: 'আজকের দাম, {time} থেকে' },
    'market.set_todays_price': { en: "Set today's price", bn: 'আজকের দাম ঠিক করুন' },
    'market.cost_per_unit': { en: 'Cost per {unit}', bn: 'প্রতি {unit} খরচ' },
    'market.suggested': { en: 'Suggested', bn: 'প্রস্তাবিত' },
    'market.suggested_margin': { en: '{percent}% margin', bn: '{percent}% মার্জিন' },
    'market.min_margin_price': { en: 'Margin floor Tk {price} ({percent}%)', bn: 'মার্জিনের নিচসীমা {price} টাকা ({percent}%)' },
    'market.no_floor': { en: 'No minimum margin set', bn: 'ন্যূনতম মার্জিন দেওয়া নেই' },
    'market.current_price': { en: 'Now Tk {price}, since {time}', bn: 'এখন {price} টাকা, {time} থেকে' },
    'market.no_current_price': { en: 'No price yet: not sellable until confirmed', bn: 'এখনও দাম নেই: নিশ্চিত না হওয়া পর্যন্ত বিক্রি হবে না' },
    'market.price_label': { en: "Today's price (Tk per {unit})", bn: 'আজকের দাম (প্রতি {unit} টাকা)' },
    'market.leave_blank': {
        en: 'Leave blank to receive without confirming: the old price stays in force and the receipt is marked.',
        bn: 'নিশ্চিত না করে গ্রহণ করতে খালি রাখুন: আগের দাম বহাল থাকবে, রসিদে দাগ থাকবে।',
    },
    'market.below_cost': { en: 'Below cost: refused.', bn: 'খরচের নিচে: গ্রহণযোগ্য নয়।' },
    'market.below_margin': { en: 'Below the minimum margin: a reason is needed.', bn: 'ন্যূনতম মার্জিনের নিচে: কারণ লাগবে।' },
    'market.below_margin_no_permission': {
        en: 'Below the minimum margin: only someone who sets prices may confirm this.',
        bn: 'ন্যূনতম মার্জিনের নিচে: যিনি দাম ঠিক করেন শুধু তিনি এটা নিশ্চিত করতে পারেন।',
    },
    'market.reason': { en: 'Why this price', bn: 'এই দাম কেন' },
    'market.reason_placeholder': { en: 'e.g. soft fish, must clear today', bn: 'যেমন: নরম মাছ, আজই বিক্রি করতে হবে' },
    'market.confirm': { en: 'Confirm price', bn: 'দাম নিশ্চিত করুন' },
    'market.unconfirmed': { en: 'Price not confirmed', bn: 'দাম নিশ্চিত হয়নি' },
    'market.confirmed_at': { en: 'Tk {price} confirmed at {time}', bn: '{price} টাকা {time}-এ নিশ্চিত' },
    'market.unconfirmed_hint': {
        en: 'This lot was received without confirming its price. The old price stays in force until someone confirms one from this lot.',
        bn: 'এই লট দাম নিশ্চিত না করে গ্রহণ করা হয়েছে। এই লট থেকে কেউ দাম নিশ্চিত না করা পর্যন্ত আগের দাম বহাল।',
    },
    'market.hidden_unpriced': {
        en: 'Not on sale today (no confirmed price): {names}',
        bn: 'আজ বিক্রির জন্য নয় (দাম নিশ্চিত হয়নি): {names}',
    },

    'market.section_title': { en: "Today's market prices", bn: 'আজকের বাজারদর' },
    'market.section_intro': {
        en: 'Products priced from each lot. The receipt proposes, the receiver confirms; every change is kept with its cost, who and when.',
        bn: 'প্রতি লট থেকে দাম ঠিক হওয়া পণ্য। রসিদ প্রস্তাব করে, গ্রহণকারী নিশ্চিত করেন; প্রতিটি বদল খরচ, কে ও কখন সহ রাখা হয়।',
    },
    'market.none_yet': { en: 'No market-priced product has a confirmed price yet.', bn: 'কোনো বাজারদরের পণ্যের দাম এখনও নিশ্চিত হয়নি।' },
    'market.product': { en: 'Product', bn: 'পণ্য' },
    'market.cost': { en: 'Cost', bn: 'খরচ' },
    'market.price': { en: 'Price', bn: 'দাম' },
    'market.margin': { en: 'Margin', bn: 'মার্জিন' },
    'market.since': { en: 'Since', bn: 'থেকে' },
    'market.by': { en: 'By', bn: 'কে' },
    'market.history': { en: 'Price history', bn: 'দামের ইতিহাস' },
    'market.history_hide': { en: 'Hide history', bn: 'ইতিহাস লুকান' },
    'market.history_none': { en: 'No price changes yet.', bn: 'এখনও কোনো দাম বদলায়নি।' },
    'market.source.receipt': { en: 'Goods receipt', bn: 'মাল গ্রহণ' },
    'market.source.manual': { en: 'By hand', bn: 'হাতে' },
    'market.prior': { en: 'Was', bn: 'ছিল' },
    'market.override': { en: 'override', bn: 'বদলানো' },
    'market.choose_product': { en: 'Choose a market product', bn: 'বাজারদরের পণ্য বাছুন' },
    'market.proposal_loading': { en: 'Working out the proposal...', bn: 'প্রস্তাব হিসাব হচ্ছে...' },
    'market.proposal_failed': { en: 'The proposal could not be loaded. Try again.', bn: 'প্রস্তাব আনা যায়নি। আবার চেষ্টা করুন।' },
} satisfies Record<string, Entry>;
