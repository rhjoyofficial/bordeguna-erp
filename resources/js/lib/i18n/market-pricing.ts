import type { Entry } from './entry';

/**
 * Market-priced goods (Phase 2 / 1B, 17 Oct 2026; rule boxes and the
 * suggestion removed 18 Oct 2026, Joy D-E): the pricing mode on the product popup, the "Set today's price" cell on
 * the Receive goods step, the receipt history, the counter badge and the
 * Selling prices section. Numbers, dates and money never translate.
 */
export const marketPricing = {
    'market.mode': { en: 'How it is priced', bn: 'দাম কীভাবে ঠিক হয়' },
    'market.mode.fixed_mrp': { en: 'Fixed MRP and price list', bn: 'নির্দিষ্ট MRP ও দামের তালিকা' },
    'market.mode.market': { en: 'Market price (set from each lot)', bn: 'বাজারদর (প্রতি লটে ঠিক হয়)' },
    'market.mode_hint': {
        en: "A market product has no printed MRP: whoever receives a lot (or sets prices) types today's price, seeing the batch cost and the previous price. It is never priced from an MRP.",
        bn: 'বাজারদরের পণ্যে ছাপানো MRP থাকে না: লট গ্রহণকারী (বা যিনি দাম ঠিক করেন) ব্যাচের খরচ ও আগের দাম দেখে আজকের দাম লেখেন। MRP থেকে কখনও দাম হয় না।',
    },

    'market.todays_price': { en: "Today's price", bn: 'আজকের দাম' },
    'market.todays_price_since': { en: "Today's price, since {time}", bn: 'আজকের দাম, {time} থেকে' },
    'market.set_todays_price': { en: "Set today's price", bn: 'আজকের দাম ঠিক করুন' },
    'market.no_current_price': {
        en: 'No price yet: not on sale today until a price is confirmed',
        bn: 'এখনও দাম নেই: দাম নিশ্চিত না হওয়া পর্যন্ত আজ বিক্রি হবে না',
    },
    'market.price_label': { en: "Today's price (Tk per {unit})", bn: 'আজকের দাম (প্রতি {unit} টাকা)' },
    'market.leave_blank': {
        en: 'Leave blank to receive without confirming: the old price stays in force and the receipt is marked.',
        bn: 'নিশ্চিত না করে গ্রহণ করতে খালি রাখুন: আগের দাম বহাল থাকবে, রসিদে দাগ থাকবে।',
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
        en: "Products priced by the day. Today's price is typed at goods receipt or here, against a batch cost; every change is kept with its batch, who, when and why.",
        bn: 'দিনের দামে বিক্রি হওয়া পণ্য। আজকের দাম মাল গ্রহণের সময় বা এখানে, ব্যাচের খরচ দেখে লেখা হয়; প্রতিটি বদল ব্যাচ, কে, কখন ও কেন সহ রাখা হয়।',
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
    'market.choose_product': { en: 'Choose a market product', bn: 'বাজারদরের পণ্য বাছুন' },
    'market.proposal_loading': { en: 'Working out the proposal...', bn: 'প্রস্তাব হিসাব হচ্ছে...' },
    'market.proposal_failed': { en: 'The proposal could not be loaded. Try again.', bn: 'প্রস্তাব আনা যায়নি। আবার চেষ্টা করুন।' },
    // ⭐ 18 Oct 2026 (Joy, D-E): no suggestion; batch cost, previous price, markdown.
    'market.batch_cost': { en: 'Batch cost per {unit}', bn: 'প্রতি {unit} ব্যাচের খরচ' },
    'market.previous_price': { en: 'Previous price Tk {price}, since {date} {time}', bn: 'আগের দাম {price} টাকা, {date} {time} থেকে' },
    'market.markdown_warning': {
        en: 'Below the batch cost of Tk {cost}: a markdown. Reason required.',
        bn: 'ব্যাচের খরচ {cost} টাকার নিচে: এটি মার্কডাউন। কারণ লিখতে হবে।',
    },
    'market.markdown_no_permission': {
        en: 'Your role may not mark a price down; ask the Owner or a Manager.',
        bn: 'আপনার ভূমিকায় দাম খরচের নিচে নামানোর অনুমতি নেই; মালিক বা ম্যানেজারকে বলুন।',
    },
    'market.markdown': { en: 'markdown', bn: 'মার্কডাউন' },
    'market.price_set_where': {
        en: "Price is set as today's price at each goods receipt or under Sales setup > Selling prices.",
        bn: 'দাম প্রতিটি মাল গ্রহণের সময় আজকের দাম হিসেবে, অথবা Sales setup > Selling prices-এ ঠিক করা হয়।',
    },
    'market.batch': { en: 'Batch on hand', bn: 'মজুদে থাকা ব্যাচ' },
    'market.batch_option': {
        en: '{batch}: cost Tk {cost}, {on_hand} {unit} on hand',
        bn: '{batch}: খরচ {cost} টাকা, মজুদ {on_hand} {unit}',
    },
} satisfies Record<string, Entry>;
