import type { Entry } from './entry';

/**
 * ⭐ Commission agreements (Joy's Q18, 25 Oct 2026): nobody earns commission
 * unless an agreement made with them says so. Sales > Sales setup >
 * Commission agreements, the "Sold by / agent" picker on orders, counter
 * bills and invoices, and the commission report. Numbers, dates and money
 * never translate.
 */
export const commission = {
    'commission.nav': { en: 'Commission agreements', bn: 'কমিশন চুক্তি' },
    'commission.report_nav': { en: 'Commission report', bn: 'কমিশন রিপোর্ট' },
    'commission.title': { en: 'Commission agreements', bn: 'কমিশন চুক্তি' },
    'commission.intro': {
        en: 'Nobody earns commission automatically. Make an agreement with one person: what they sell, and what they get for it.',
        bn: 'কেউ নিজে থেকে কমিশন পায় না। একজনের সাথে চুক্তি করুন: তিনি কী বিক্রি করবেন, আর তার জন্য কী পাবেন।',
    },
    'commission.new': { en: 'New agreement', bn: 'নতুন চুক্তি' },
    'commission.edit': { en: 'Edit', bn: 'সম্পাদনা' },
    'commission.none_yet': { en: 'No commission agreements yet.', bn: 'এখনও কোনো কমিশন চুক্তি নেই।' },
    'commission.number': { en: 'Agreement', bn: 'চুক্তি' },
    'commission.person': { en: 'Person', bn: 'ব্যক্তি' },
    'commission.deal': { en: 'Deal', bn: 'চুক্তির ধরন' },
    'commission.dates': { en: 'Dates', bn: 'তারিখ' },
    'commission.sold': { en: 'Sold so far', bn: 'এ পর্যন্ত বিক্রি' },
    'commission.earned': { en: 'Earned so far', bn: 'এ পর্যন্ত অর্জিত' },
    'commission.settled': { en: 'Settled', bn: 'নিষ্পত্তি হয়েছে' },
    'commission.to_settle': { en: 'To settle', bn: 'নিষ্পত্তি বাকি' },
    'commission.status': { en: 'Status', bn: 'অবস্থা' },
    'commission.open_end': { en: 'open', bn: 'খোলা' },
    'commission.to': { en: 'to', bn: 'থেকে' },
    'commission.units': { en: '{qty} units', bn: '{qty} ইউনিট' },
    'commission.target_progress': { en: '{percent}% of the top target', bn: 'সর্বোচ্চ লক্ষ্যের {percent}%' },
    'commission.next_step': { en: '{gap} more to reach the next step (Tk {reward})', bn: 'পরের ধাপে (Tk {reward}) পৌঁছাতে আরও {gap}' },

    // The agreement popup
    'commission.dialog_new': { en: 'New commission agreement', bn: 'নতুন কমিশন চুক্তি' },
    'commission.dialog_edit': { en: 'Edit commission agreement', bn: 'কমিশন চুক্তি সম্পাদনা' },
    'commission.settled_lock': {
        en: 'Money has been settled on these terms, so only the end date and the note can change. For new terms, make a new agreement.',
        bn: 'এই শর্তে টাকা নিষ্পত্তি হয়ে গেছে, তাই শুধু শেষ তারিখ আর নোট বদলানো যাবে। নতুন শর্তের জন্য নতুন চুক্তি করুন।',
    },
    'commission.agreed_on': { en: 'Agreed on', bn: 'চুক্তির তারিখ' },
    'commission.party_type': { en: 'Who', bn: 'কার সাথে' },
    'commission.party_employee': { en: 'Employee', bn: 'কর্মী' },
    'commission.party_customer': { en: 'Customer or shop', bn: 'গ্রাহক বা দোকান' },
    'commission.party_supplier': { en: 'Outside agent (kept as a supplier)', bn: 'বাইরের এজেন্ট (সরবরাহকারী হিসেবে রাখা)' },
    'commission.party_pick': { en: 'Choose the person', bn: 'ব্যক্তি বাছাই করুন' },
    'commission.valid_from': { en: 'Valid from', bn: 'শুরু' },
    'commission.valid_to': { en: 'Valid to', bn: 'শেষ' },
    'commission.valid_to_hint': {
        en: 'Leave empty to keep it open. A target deal needs an end.',
        bn: 'খোলা রাখতে খালি রাখুন। লক্ষ্যভিত্তিক চুক্তিতে শেষ তারিখ লাগবে।',
    },
    'commission.products': { en: 'Products', bn: 'পণ্য' },
    'commission.products_hint': { en: 'No product and no category = every product.', bn: 'কোনো পণ্য বা ক্যাটাগরি না দিলে = সব পণ্য।' },
    'commission.add_product': { en: 'Add a product', bn: 'একটি পণ্য যোগ করুন' },
    'commission.categories': { en: 'Product families (categories)', bn: 'পণ্যের পরিবার (ক্যাটাগরি)' },
    'commission.add_category': { en: 'Add a category', bn: 'একটি ক্যাটাগরি যোগ করুন' },
    'commission.every_product': { en: 'Every product', bn: 'সব পণ্য' },
    'commission.remove': { en: 'Remove', bn: 'সরান' },
    'commission.deal_type': { en: 'Deal type', bn: 'চুক্তির ধরন' },
    'commission.deal_per_unit': { en: 'Tk per unit sold', bn: 'প্রতি ইউনিট বিক্রিতে টাকা' },
    'commission.deal_percent': { en: '% of the sale value', bn: 'বিক্রয়মূল্যের %' },
    'commission.deal_target': { en: 'Target: sell this much, get this amount', bn: 'লক্ষ্য: এতটা বিক্রি করলে এত টাকা' },
    'commission.rate_per_unit': { en: 'Tk per unit', bn: 'প্রতি ইউনিটে টাকা' },
    'commission.rate_percent': { en: 'Percent', bn: 'শতাংশ' },
    'commission.target_basis': { en: 'Target counts', bn: 'লক্ষ্য গোনা হবে' },
    'commission.basis_units': { en: 'Units', bn: 'ইউনিটে' },
    'commission.basis_amount': { en: 'Tk of sales', bn: 'বিক্রির টাকায়' },
    'commission.tiers': { en: 'Steps', bn: 'ধাপ' },
    'commission.tiers_hint': {
        en: 'The highest step reached inside the dates is paid, not the sum of the steps.',
        bn: 'তারিখের মধ্যে যে সর্বোচ্চ ধাপে পৌঁছানো হবে সেটাই দেওয়া হবে, সব ধাপের যোগফল নয়।',
    },
    'commission.threshold': { en: 'Sell', bn: 'বিক্রি' },
    'commission.reward': { en: 'Get Tk', bn: 'পাবেন টাকা' },
    'commission.add_step': { en: 'Add a step', bn: 'একটি ধাপ যোগ করুন' },
    'commission.which_sales': { en: 'Which sales count', bn: 'কোন বিক্রি গোনা হবে' },
    'commission.counts_credited': { en: 'Sales credited to this person (Sold by / agent)', bn: 'এই ব্যক্তির নামে দেওয়া বিক্রি (বিক্রেতা / এজেন্ট)' },
    'commission.counts_own': { en: "The customer's own purchases", bn: 'গ্রাহকের নিজের কেনা' },
    'commission.note': { en: 'Note', bn: 'নোট' },
    'commission.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'commission.save': { en: 'Save agreement', bn: 'চুক্তি সংরক্ষণ' },
    'commission.approval_hint': {
        en: 'A target deal promising more than the approval limit waits for a second person before its sales count.',
        bn: 'অনুমোদন সীমার বেশি প্রতিশ্রুতির লক্ষ্যভিত্তিক চুক্তি দ্বিতীয় একজনের অনুমোদনের পর বিক্রি গোনা শুরু করে।',
    },

    // The record page
    'commission.back': { en: 'All agreements', bn: 'সব চুক্তি' },
    'commission.made_by': { en: 'Made by {name}', bn: '{name} তৈরি করেছেন' },
    'commission.settle': { en: 'Settle', bn: 'নিষ্পত্তি' },
    'commission.settlements': { en: 'Settlements', bn: 'নিষ্পত্তি' },
    'commission.no_settlements': { en: 'Nothing settled yet.', bn: 'এখনও কিছু নিষ্পত্তি হয়নি।' },
    'commission.sales_window': { en: 'Sales', bn: 'বিক্রি' },
    'commission.amount': { en: 'Amount', bn: 'টাকা' },
    'commission.how': { en: 'How', bn: 'কীভাবে' },
    'commission.owed': { en: 'Owed', bn: 'পাওনা' },
    'commission.pay': { en: 'Pay', bn: 'পরিশোধ' },
    'commission.payments': { en: 'Payments', bn: 'পরিশোধ' },
    'commission.reversed': { en: 'Reversed', bn: 'রিভার্স হয়েছে' },
    'commission.legacy_title': { en: 'Older targets (read only)', bn: 'পুরনো লক্ষ্য (শুধু দেখার জন্য)' },
    'commission.legacy_hint': {
        en: 'Targets set before commission agreements. Nothing new is set here and none of them pays anything.',
        bn: 'কমিশন চুক্তির আগে দেওয়া লক্ষ্য। এখানে নতুন কিছু দেওয়া হয় না, আর এগুলো থেকে কোনো টাকা দেওয়া হয় না।',
    },
    'commission.legacy_month': { en: 'Month', bn: 'মাস' },
    'commission.legacy_target': { en: 'Target', bn: 'লক্ষ্য' },
    'commission.legacy_percent': { en: 'Commission %', bn: 'কমিশন %' },

    // The settle popup
    'commission.settle_title': { en: 'Settle {number}', bn: '{number} নিষ্পত্তি' },
    'commission.settle_hint': {
        en: 'Everything earned through the day you choose, less what was settled before, is settled. The amount is worked out, never typed.',
        bn: 'আপনার বাছাই করা দিন পর্যন্ত যা অর্জিত হয়েছে, আগের নিষ্পত্তি বাদ দিয়ে, তা নিষ্পত্তি হবে। টাকার অঙ্ক হিসাব করে বের হয়, লেখা হয় না।',
    },
    'commission.settled_on': { en: 'Settlement date', bn: 'নিষ্পত্তির তারিখ' },
    'commission.through': { en: 'Sales through', bn: 'বিক্রি পর্যন্ত' },
    'commission.method': { en: 'How it is paid', bn: 'কীভাবে দেওয়া হবে' },
    'commission.method_payable': { en: 'Owed: pay later', bn: 'পাওনা: পরে পরিশোধ' },
    'commission.method_paid_now': { en: 'Paid now from an account', bn: 'এখনই একটি অ্যাকাউন্ট থেকে পরিশোধ' },
    'commission.method_payroll': { en: 'On the next payroll (employees)', bn: 'পরের বেতনে (কর্মী)' },
    'commission.money_account': { en: 'Paid from', bn: 'যে অ্যাকাউন্ট থেকে' },
    'commission.settle_now': { en: 'Settle', bn: 'নিষ্পত্তি করুন' },
    'commission.waiting_now': { en: 'To settle now: Tk {amount}', bn: 'এখন নিষ্পত্তি বাকি: Tk {amount}' },

    // The pay popup
    'commission.pay_title': { en: 'Pay {number}', bn: '{number} পরিশোধ' },
    'commission.paid_on': { en: 'Paid on', bn: 'পরিশোধের তারিখ' },
    'commission.pay_now': { en: 'Pay', bn: 'পরিশোধ করুন' },

    // The report
    'commission.report_title': { en: 'Commission report', bn: 'কমিশন রিপোর্ট' },
    'commission.report_intro': {
        en: 'By person: commission earned on sales in the period, settled and paid in the period, and what is owed and still to settle today.',
        bn: 'ব্যক্তি অনুযায়ী: সময়ের মধ্যে বিক্রিতে অর্জিত কমিশন, সময়ের মধ্যে নিষ্পত্তি ও পরিশোধ, আর আজ পর্যন্ত পাওনা ও নিষ্পত্তি বাকি।',
    },
    'commission.from': { en: 'From', bn: 'থেকে' },
    'commission.until': { en: 'To', bn: 'পর্যন্ত' },
    'commission.show': { en: 'Show', bn: 'দেখুন' },
    'commission.paid': { en: 'Paid', bn: 'পরিশোধিত' },
    'commission.owed_today': { en: 'Owed today', bn: 'আজ পাওনা' },
    'commission.total': { en: 'Total', bn: 'মোট' },
    'commission.no_rows': { en: 'No active agreements.', bn: 'কোনো সক্রিয় চুক্তি নেই।' },

    // The "Sold by / agent" picker on orders, counter bills and invoices
    'commission.agent': { en: 'Sold by / agent', bn: 'বিক্রেতা / এজেন্ট' },
    'commission.agent_none': { en: 'Nobody', bn: 'কেউ না' },
    'commission.agent_hint': {
        en: 'Only people with a commission agreement are listed. Their agreement counts this sale.',
        bn: 'শুধু যাদের কমিশন চুক্তি আছে তারা তালিকায় আছেন। তাদের চুক্তিতে এই বিক্রি গোনা হবে।',
    },
} satisfies Record<string, Entry>;
