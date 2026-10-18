import type { Entry } from './entry';

/**
 * Staff purchase at cost, Phase 2 (17 Oct 2026, ChatGPT ruling): the price
 * is the reserved batch's cost frozen at approval, an estimate before that;
 * the allowance is measured at the reference price (MRP, or the market price
 * for goods without one); a defect is replaced at no charge; the authority's
 * screen has Requests, Monthly statement and Audit tabs.
 */
export const staffPurchase = {
    'staff_purchase.estimate': { en: 'estimated', bn: 'আনুমানিক' },
    'staff_purchase.estimate_hint': {
        en: 'The staff price is the cost of the batch the store reserves for you when the application is approved. Until then this is an estimate from the current stock.',
        bn: 'স্টাফ মূল্য হলো সেই ব্যাচের খরচ যা আবেদন অনুমোদনের সময় স্টোর আপনার জন্য সংরক্ষণ করে। তার আগে এটি বর্তমান স্টক থেকে একটি আনুমানিক দাম।',
    },
    'staff_purchase.approved_price': { en: 'Approved price', bn: 'অনুমোদিত দাম' },
    'staff_purchase.reference_price': { en: 'Reference price', bn: 'রেফারেন্স দাম' },
    'staff_purchase.reference_mrp': { en: 'MRP', bn: 'এমআরপি' },
    'staff_purchase.reference_market': { en: 'reference price (market)', bn: 'রেফারেন্স দাম (বাজার)' },
    'staff_purchase.allowance_reference': { en: "This month's allowance (at the reference price)", bn: 'এই মাসের সীমা (রেফারেন্স দাম ধরে)' },
    'staff_purchase.selection_uses_reference': { en: 'This selection uses (at the reference price)', bn: 'এই বাছাইয়ে লাগবে (রেফারেন্স দাম ধরে)' },
    'staff_purchase.next_unit_at_reference': {
        en: 'Already taken this month at the staff price; another unit is priced at the reference price.',
        bn: 'এই মাসে স্টাফ মূল্যে একবার নেওয়া হয়েছে; আরেকটি নিলে রেফারেন্স দামে ধরা হবে।',
    },
    'staff_purchase.priced_at_reference': { en: 'at the reference price', bn: 'রেফারেন্স দামে' },
    'staff_purchase.status_needs_reapproval': { en: 'Needs re-approval (price changed)', bn: 'পুনঃঅনুমোদন দরকার (দাম বদলেছে)' },
    'staff_purchase.status_cancelled': { en: 'Cancelled', bn: 'বাতিল' },
    'staff_purchase.no_products': {
        en: 'Nothing can be requested right now: no product has both a reference price and stock to issue.',
        bn: 'এখন কিছু আবেদন করা যাচ্ছে না: কোনো পণ্যের রেফারেন্স দাম এবং দেওয়ার মতো স্টক দুটোই নেই।',
    },

    // The authority's screen
    'staff_purchases.title': { en: 'Staff purchases', bn: 'স্টাফ ক্রয়' },
    'staff_purchases.tab_requests': { en: 'Requests', bn: 'আবেদন' },
    'staff_purchases.tab_statement': { en: 'Monthly statement', bn: 'মাসিক বিবরণী' },
    'staff_purchases.tab_audit': { en: 'Audit', bn: 'অডিট' },
    'staff_purchases.month': { en: 'Month', bn: 'মাস' },
    'staff_purchases.none': { en: 'No applications yet.', bn: 'এখনও কোনো আবেদন নেই।' },
    'staff_purchases.approve': { en: 'Approve', bn: 'অনুমোদন' },
    'staff_purchases.approve_hint': {
        en: 'Approval reserves the FIFO batch and freezes its cost as the staff price.',
        bn: 'অনুমোদন দিলে FIFO ব্যাচ সংরক্ষিত হয় এবং তার খরচই স্টাফ মূল্য হিসেবে স্থির হয়।',
    },
    'staff_purchases.reject': { en: 'Reject', bn: 'নামঞ্জুর' },
    'staff_purchases.reason_if_rejecting': { en: 'reason if rejecting', bn: 'নামঞ্জুর করলে কারণ' },
    'staff_purchases.cancel': { en: 'Cancel application', bn: 'আবেদন বাতিল' },
    'staff_purchases.cancel_reason': { en: 'reason for cancelling', bn: 'বাতিলের কারণ' },
    'staff_purchases.hand_over': { en: 'Hand over', bn: 'হস্তান্তর' },
    'staff_purchases.paid_into': { en: 'Paid into...', bn: 'টাকা জমা হবে...' },
    'staff_purchases.salary_note': {
        en: 'salary deduction settles the invoice at the next payroll',
        bn: 'বেতন কাটা পরের পে-রোলে ইনভয়েস মিটিয়ে দেবে',
    },
    'staff_purchases.open_sale': { en: 'Open the sale', bn: 'বিক্রি দেখুন' },
    'staff_purchases.replace': { en: 'Replace (defect)', bn: 'বদলে দিন (ত্রুটি)' },
    'staff_purchases.replace_title': { en: 'Replace a defective unit at no charge', bn: 'ত্রুটিপূর্ণ পণ্য বিনামূল্যে বদলে দিন' },
    'staff_purchases.replace_hint': {
        en: 'The replacement leaves stock at its batch cost as a loss, linked to this application. No new invoice, no quota used. The defective unit is written off and never goes back on the shelf.',
        bn: 'বদলি পণ্যটি তার ব্যাচ খরচে ক্ষতি হিসেবে স্টক থেকে বের হয়, এই আবেদনের সাথে যুক্ত থাকে। নতুন ইনভয়েস নেই, মাসিক সীমা খরচ হয় না। ত্রুটিপূর্ণ পণ্যটি রাইট-অফ হয়, আর কখনো তাকে বিক্রির স্টকে ফেরানো হয় না।',
    },
    'staff_purchases.replace_line': { en: 'Which item', bn: 'কোন পণ্য' },
    'staff_purchases.replace_reason': { en: 'Reason', bn: 'কারণ' },
    'staff_purchases.replace_evidence': { en: 'Evidence seen', bn: 'যে প্রমাণ দেখা হয়েছে' },
    'staff_purchases.replace_confirm': { en: 'Replace at no charge', bn: 'বিনামূল্যে বদলে দিন' },
    'staff_purchases.replaced': { en: 'replaced', bn: 'বদলে দেওয়া হয়েছে' },
    'staff_purchases.cost_basis': { en: 'Cost basis', bn: 'খরচের ভিত্তি' },
    'staff_purchases.cost_basis_hint': {
        en: 'Visible to the Owner and Finance only: the reserved batch and its unit cost behind the staff price.',
        bn: 'শুধু মালিক ও ফাইন্যান্স দেখতে পান: স্টাফ মূল্যের পেছনের সংরক্ষিত ব্যাচ ও তার একক খরচ।',
    },
    'staff_purchases.batch': { en: 'Batch', bn: 'ব্যাচ' },
    'staff_purchases.unit_cost': { en: 'Unit cost', bn: 'একক খরচ' },
    'staff_purchases.reapproval_reason': { en: 'Sent back for re-approval', bn: 'পুনঃঅনুমোদনের জন্য ফেরত' },

    // Monthly statement
    'staff_purchases.statement_hint': {
        en: 'Per employee for the month: what was handed over, its reference price, the price charged, the difference, and what was settled through payroll or at the till. Tax evidence only; no tax is calculated.',
        bn: 'মাসে কর্মীভিত্তিক: কী হস্তান্তর হয়েছে, রেফারেন্স দাম, নেওয়া দাম, পার্থক্য, আর পে-রোল বা কাউন্টারে কতটা মেটানো হয়েছে। শুধু করের প্রমাণ; কোনো কর হিসাব করা হয় না।',
    },
    'staff_purchases.statement_none': { en: 'Nothing was handed over this month.', bn: 'এই মাসে কিছু হস্তান্তর হয়নি।' },
    'staff_purchases.col_date': { en: 'Date', bn: 'তারিখ' },
    'staff_purchases.col_item': { en: 'Item', bn: 'পণ্য' },
    'staff_purchases.col_reference': { en: 'Reference', bn: 'রেফারেন্স' },
    'staff_purchases.col_price': { en: 'Price', bn: 'দাম' },
    'staff_purchases.col_difference': { en: 'Difference', bn: 'পার্থক্য' },
    'staff_purchases.settled_payroll': { en: 'Settled via payroll', bn: 'পে-রোলে মেটানো' },
    'staff_purchases.settled_cash': { en: 'Settled at the till', bn: 'কাউন্টারে মেটানো' },
    'staff_purchases.outstanding': { en: 'Outstanding', bn: 'বাকি' },

    // Audit
    'staff_purchases.audit_hint': {
        en: 'Every step this month: application, approval with its price, re-approval, handover, settlement, replacement; who and when.',
        bn: 'এই মাসের প্রতিটি ধাপ: আবেদন, দামসহ অনুমোদন, পুনঃঅনুমোদন, হস্তান্তর, নিষ্পত্তি, বদল; কে এবং কখন।',
    },
    'staff_purchases.audit_none': { en: 'No activity this month.', bn: 'এই মাসে কোনো কার্যক্রম নেই।' },
    'staff_purchases.col_when': { en: 'When', bn: 'কখন' },
    'staff_purchases.col_action': { en: 'Action', bn: 'কাজ' },
    'staff_purchases.col_who': { en: 'Who', bn: 'কে' },
    'staff_purchases.col_details': { en: 'Details', bn: 'বিবরণ' },
    'staff_purchases.action_applied': { en: 'Applied', bn: 'আবেদন' },
    'staff_purchases.action_approved': { en: 'Approved (price frozen)', bn: 'অনুমোদিত (দাম স্থির)' },
    'staff_purchases.action_rejected': { en: 'Rejected', bn: 'নামঞ্জুর' },
    'staff_purchases.action_cancelled': { en: 'Cancelled', bn: 'বাতিল' },
    'staff_purchases.action_needs_reapproval': { en: 'Sent back for re-approval', bn: 'পুনঃঅনুমোদনে ফেরত' },
    'staff_purchases.action_handed_over': { en: 'Handed over', bn: 'হস্তান্তর' },
    'staff_purchases.action_payroll_offset': { en: 'Settled from salary', bn: 'বেতন থেকে মেটানো' },
    'staff_purchases.action_payroll_offset_withdrawn': { en: 'Salary settlement withdrawn', bn: 'বেতন নিষ্পত্তি প্রত্যাহার' },
    'staff_purchases.action_replaced': { en: 'Replaced (defect)', bn: 'বদলে দেওয়া (ত্রুটি)' },
    // P17 (18 Oct 2026, practice run): the audit details in words, not JSON.
    'staff_purchases.detail_product': { en: 'product', bn: 'পণ্য' },
    'staff_purchases.detail_price': { en: 'price', bn: 'দাম' },
    'staff_purchases.detail_was': { en: 'was', bn: 'আগে ছিল' },
    'staff_purchases.detail_reference': { en: 'reference', bn: 'তুলনার দাম' },
    'staff_purchases.detail_quantity': { en: 'quantity', bn: 'পরিমাণ' },
    'staff_purchases.detail_total': { en: 'total', bn: 'মোট' },
    'staff_purchases.detail_amount': { en: 'amount', bn: 'পরিমাণ টাকা' },
    'staff_purchases.detail_cost': { en: 'cost', bn: 'খরচ' },
    'staff_purchases.detail_batch': { en: 'batch', bn: 'ব্যাচ' },
    'staff_purchases.detail_invoice': { en: 'invoice', bn: 'ইনভয়েস' },
    'staff_purchases.detail_warehouse': { en: 'from', bn: 'গুদাম' },
    'staff_purchases.detail_payment': { en: 'paid by', bn: 'পরিশোধ' },
    'staff_purchases.detail_payroll': { en: 'payroll', bn: 'বেতন' },
    'staff_purchases.detail_reason': { en: 'reason', bn: 'কারণ' },
    'staff_purchases.detail_evidence': { en: 'evidence', bn: 'প্রমাণ' },
} satisfies Record<string, Entry>;
