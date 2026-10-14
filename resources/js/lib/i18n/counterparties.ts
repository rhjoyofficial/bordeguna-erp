import type { Entry } from './entry';

/**
 * Counterparties and the loan heads (loans plan v2, phase 1, 14 Oct 2026):
 * the picker on voucher and journal lines, and the Counterparties screens.
 */
export const counterparties = {
    // ── The picker on a loan line ────────────────────────────────────────
    'counterparties.picker.which': { en: 'Which counterparty?', bn: 'কোন প্রতিপক্ষ?' },
    'counterparties.picker.related': { en: '{name} (related party)', bn: '{name} (সম্পর্কিত পক্ষ)' },
    'counterparties.picker.new': { en: 'New counterparty', bn: 'নতুন প্রতিপক্ষ' },

    // ── The short note under a loan head in the ledger-head picker ──────
    'counterparties.hint.loan': {
        en: 'Names a counterparty. Money between our own businesses: Inter-business transfers.',
        bn: 'একজন প্রতিপক্ষের নাম লাগে। আমাদের নিজেদের ব্যবসার মধ্যে টাকা: আন্তঃব্যবসা স্থানান্তর।',
    },
    'counterparties.hint.related_only': {
        en: 'Related parties only. Money between our own businesses: Inter-business transfers.',
        bn: 'শুধু সম্পর্কিত পক্ষ। আমাদের নিজেদের ব্যবসার মধ্যে টাকা: আন্তঃব্যবসা স্থানান্তর।',
    },
    'counterparties.hint.not_related': {
        en: 'Not for a related party (use 11752). Money between our own businesses: Inter-business transfers.',
        bn: 'সম্পর্কিত পক্ষের জন্য নয় (11752 ব্যবহার করুন)। আমাদের নিজেদের ব্যবসার মধ্যে টাকা: আন্তঃব্যবসা স্থানান্তর।',
    },
    'counterparties.hint.owner_loan': {
        en: "Only for a documented owner loan agreed with the accountant; money the owner puts in is Owner's Capital 3100.",
        bn: 'শুধু হিসাবরক্ষকের সাথে ঠিক করা, লিখিত মালিক-ঋণের জন্য; মালিক যে টাকা ব্যবসায় দেন তা মালিকের মূলধন 3100।',
    },

    // ── The note under a line once a loan head is chosen ────────────────
    'counterparties.note.loan': {
        en: 'Every line on this head names its counterparty. Money between our own businesses is not a loan: use Finance & Accounts > Inter-business transfers.',
        bn: 'এই হেডের প্রতিটি লাইনে প্রতিপক্ষের নাম থাকে। আমাদের নিজেদের ব্যবসার মধ্যে টাকা ঋণ নয়: Finance & Accounts > Inter-business transfers ব্যবহার করুন।',
    },
    'counterparties.note.related_only': {
        en: 'Only a counterparty marked related can be chosen here. Money between our own businesses is not a loan: use Finance & Accounts > Inter-business transfers.',
        bn: 'এখানে শুধু সম্পর্কিত পক্ষ হিসেবে চিহ্নিত প্রতিপক্ষ বাছাই করা যায়। আমাদের নিজেদের ব্যবসার মধ্যে টাকা ঋণ নয়: Finance & Accounts > Inter-business transfers ব্যবহার করুন।',
    },
    'counterparties.note.not_related': {
        en: 'A loan to a related party goes on 11752 Loans to Related Parties. Money between our own businesses is not a loan: use Finance & Accounts > Inter-business transfers.',
        bn: 'সম্পর্কিত পক্ষকে দেওয়া ঋণ 11752 Loans to Related Parties-এ যায়। আমাদের নিজেদের ব্যবসার মধ্যে টাকা ঋণ নয়: Finance & Accounts > Inter-business transfers ব্যবহার করুন।',
    },
    'counterparties.note.owner_loan': {
        en: "Only for a documented owner loan agreed with the accountant; money the owner puts in is Owner's Capital 3100.",
        bn: 'শুধু হিসাবরক্ষকের সাথে ঠিক করা, লিখিত মালিক-ঋণের জন্য; মালিক যে টাকা ব্যবসায় দেন তা মালিকের মূলধন 3100।',
    },

    // ── Kinds ────────────────────────────────────────────────────────────
    'counterparties.kind.company': { en: 'Company', bn: 'কোম্পানি' },
    'counterparties.kind.individual': { en: 'Individual', bn: 'ব্যক্তি' },
    'counterparties.kind.bank': { en: 'Bank', bn: 'ব্যাংক' },
    'counterparties.kind.nbfi': { en: 'NBFI (finance company)', bn: 'এনবিএফআই (আর্থিক প্রতিষ্ঠান)' },
    'counterparties.kind.government': { en: 'Government body', bn: 'সরকারি প্রতিষ্ঠান' },
    'counterparties.kind.other': { en: 'Other', bn: 'অন্যান্য' },

    // ── The list ─────────────────────────────────────────────────────────
    'counterparties.nav': { en: 'Counterparties', bn: 'প্রতিপক্ষ' },
    'counterparties.title': { en: 'Counterparties', bn: 'প্রতিপক্ষ' },
    'counterparties.description': {
        en: "Banks, lenders and borrowers outside the group. Every line on a loan head names one of them; the balances are this business's books.",
        bn: 'গ্রুপের বাইরের ব্যাংক, ঋণদাতা ও ঋণগ্রহীতা। ঋণ হেডের প্রতিটি লাইনে এদের একজনের নাম থাকে; ব্যালান্সগুলো এই ব্যবসার খাতার।',
    },
    'counterparties.search': { en: 'Search', bn: 'খুঁজুন' },
    'counterparties.search_placeholder': { en: 'Name, TIN, phone or NID', bn: 'নাম, টিআইএন, ফোন বা এনআইডি' },
    'counterparties.all_kinds': { en: 'All kinds', bn: 'সব ধরন' },
    'counterparties.status': { en: 'Status', bn: 'অবস্থা' },
    'counterparties.status.active': { en: 'Active', bn: 'চালু' },
    'counterparties.status.inactive': { en: 'Switched off', bn: 'বন্ধ' },
    'counterparties.status.all': { en: 'All', bn: 'সব' },
    'counterparties.show_button': { en: 'Show', bn: 'দেখান' },
    'counterparties.col.name': { en: 'Name', bn: 'নাম' },
    'counterparties.col.kind': { en: 'Kind', bn: 'ধরন' },
    'counterparties.col.tin': { en: 'TIN', bn: 'টিআইএন' },
    'counterparties.col.phone': { en: 'Phone', bn: 'ফোন' },
    'counterparties.col.balances': { en: 'Balances per loan head', bn: 'ঋণ হেড অনুযায়ী ব্যালান্স' },
    'counterparties.col.owed_to_us': { en: 'Owes us', bn: 'আমাদের পাওনা' },
    'counterparties.col.we_owe': { en: 'We owe', bn: 'আমাদের দেনা' },
    'counterparties.related_badge': { en: 'Related party', bn: 'সম্পর্কিত পক্ষ' },
    'counterparties.off_badge': { en: 'Switched off', bn: 'বন্ধ' },
    'counterparties.empty': {
        en: 'No counterparties yet. Add a bank, lender or borrower with "New counterparty".',
        bn: 'এখনও কোনো প্রতিপক্ষ নেই। "নতুন প্রতিপক্ষ" দিয়ে একটি ব্যাংক, ঋণদাতা বা ঋণগ্রহীতা যোগ করুন।',
    },
    'counterparties.none_match': { en: 'Nothing matches these filters.', bn: 'এই ফিল্টারে কিছু মেলেনি।' },
    'counterparties.nothing_outstanding': { en: 'Nothing outstanding', bn: 'কিছু বাকি নেই' },
    'counterparties.dr': { en: 'Dr', bn: 'ডেবিট' },
    'counterparties.cr': { en: 'Cr', bn: 'ক্রেডিট' },
    'counterparties.heads.title': { en: 'Each loan head against the ledger', bn: 'প্রতিটি ঋণ হেড খাতার সাথে মিলিয়ে' },
    'counterparties.heads.col.head': { en: 'Loan head', bn: 'ঋণ হেড' },
    'counterparties.heads.col.parties': { en: 'Counterparties add up to', bn: 'প্রতিপক্ষদের যোগফল' },
    'counterparties.heads.col.ledger': { en: 'Ledger balance', bn: 'খাতার ব্যালান্স' },
    'counterparties.heads.agrees': { en: 'Agrees', bn: 'মিলেছে' },
    'counterparties.heads.differs': { en: 'Differs: open Reconciliation', bn: 'মেলেনি: Reconciliation খুলুন' },
    'counterparties.heads.empty': { en: 'No loan head has a posting yet.', bn: 'কোনো ঋণ হেডে এখনও পোস্টিং নেই।' },

    // ── The add and edit dialog ──────────────────────────────────────────
    'counterparties.form.edit_title': { en: 'Edit counterparty', bn: 'প্রতিপক্ষ সম্পাদনা' },
    'counterparties.form.legal_name': { en: 'Legal name', bn: 'আইনি নাম' },
    'counterparties.form.legal_name_hint': { en: 'As on the agreement or the TIN certificate.', bn: 'চুক্তি বা টিআইএন সনদে যেমন আছে।' },
    'counterparties.form.kind': { en: 'Kind', bn: 'ধরন' },
    'counterparties.form.related': {
        en: 'Related party (the owner, his family, or a firm the owner controls)',
        bn: 'সম্পর্কিত পক্ষ (মালিক, তাঁর পরিবার, বা মালিকের নিয়ন্ত্রণে থাকা প্রতিষ্ঠান)',
    },
    'counterparties.form.relationship': { en: 'How related', bn: 'কীভাবে সম্পর্কিত' },
    'counterparties.form.relationship_placeholder': { en: "e.g. The owner's company", bn: 'যেমন: মালিকের প্রতিষ্ঠান' },
    'counterparties.form.tin': { en: 'TIN', bn: 'টিআইএন' },
    'counterparties.form.bin': { en: 'BIN', bn: 'বিআইএন' },
    'counterparties.form.registration_no': { en: 'NID or registration no.', bn: 'এনআইডি বা নিবন্ধন নম্বর' },
    'counterparties.form.phone': { en: 'Phone', bn: 'ফোন' },
    'counterparties.form.email': { en: 'Email', bn: 'ইমেইল' },
    'counterparties.form.address': { en: 'Address', bn: 'ঠিকানা' },
    'counterparties.form.bank_details': { en: 'Bank details', bn: 'ব্যাংকের তথ্য' },
    'counterparties.form.bank_details_placeholder': {
        en: 'Bank, branch, account name and number, routing number',
        bn: 'ব্যাংক, শাখা, হিসাবের নাম ও নম্বর, রাউটিং নম্বর',
    },
    'counterparties.form.notes': { en: 'Notes', bn: 'নোট' },
    'counterparties.form.links': { en: 'The same party elsewhere in the ERP', bn: 'ইআরপির অন্য জায়গায় একই পক্ষ' },
    'counterparties.form.customer': { en: 'Also our customer', bn: 'আমাদের গ্রাহকও' },
    'counterparties.form.supplier': { en: 'Also our supplier', bn: 'আমাদের সরবরাহকারীও' },
    'counterparties.form.employee': { en: 'Also our employee', bn: 'আমাদের কর্মীও' },
    'counterparties.form.none': { en: 'None', bn: 'নেই' },
    'counterparties.form.internal_note': {
        en: 'Not for our own businesses: money between them is an Inter-business transfer, never a loan.',
        bn: 'আমাদের নিজেদের ব্যবসার জন্য নয়: তাদের মধ্যে টাকা আন্তঃব্যবসা স্থানান্তর, কখনও ঋণ নয়।',
    },
    'counterparties.form.cancel': { en: 'Cancel', bn: 'বাতিল' },
    'counterparties.form.save': { en: 'Save', bn: 'সংরক্ষণ' },
    'counterparties.form.saving': { en: 'Saving…', bn: 'সংরক্ষণ হচ্ছে…' },
    'counterparties.form.forbidden': {
        en: 'You are not allowed to add or change counterparties. Ask the Owner or Finance.',
        bn: 'আপনার প্রতিপক্ষ যোগ বা পরিবর্তনের অনুমতি নেই। মালিক বা ফাইন্যান্সকে বলুন।',
    },
    'counterparties.form.failed': { en: 'Could not save. Try again.', bn: 'সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।' },
    'counterparties.form.offline': { en: 'Could not reach the server. Try again.', bn: 'সার্ভারে পৌঁছানো যায়নি। আবার চেষ্টা করুন।' },

    // ── One counterparty ─────────────────────────────────────────────────
    'counterparties.show.edit': { en: 'Edit', bn: 'সম্পাদনা' },
    'counterparties.show.deactivate': { en: 'Switch off', bn: 'বন্ধ করুন' },
    'counterparties.show.activate': { en: 'Switch on', bn: 'চালু করুন' },
    'counterparties.show.statement': { en: 'Statement', bn: 'বিবরণী' },
    'counterparties.show.confirmation': { en: 'Balance confirmation letter', bn: 'ব্যালান্স নিশ্চিতকরণ চিঠি' },
    'counterparties.show.deactivate_title': { en: 'Switch off {name}?', bn: '{name} বন্ধ করবেন?' },
    'counterparties.show.deactivate_body': {
        en: 'It stays in every statement and report, and is no longer offered on vouchers. A counterparty that still holds a balance in any business cannot be switched off.',
        bn: 'এটি সব বিবরণী ও রিপোর্টে থাকবে, তবে ভাউচারে আর দেখাবে না। কোনো ব্যবসায় ব্যালান্স বাকি থাকলে প্রতিপক্ষ বন্ধ করা যায় না।',
    },
    'counterparties.show.details': { en: 'Details', bn: 'বিস্তারিত' },
    'counterparties.show.balances': { en: 'Balances in {business}', bn: '{business}-এ ব্যালান্স' },
    'counterparties.show.balances_note': {
        en: 'Other businesses of the group keep their own books with this counterparty.',
        bn: 'গ্রুপের অন্য ব্যবসাগুলো এই প্রতিপক্ষের সাথে নিজেদের আলাদা খাতা রাখে।',
    },
    'counterparties.show.col.head': { en: 'Loan head', bn: 'ঋণ হেড' },
    'counterparties.show.col.balance': { en: 'Balance', bn: 'ব্যালান্স' },
    'counterparties.show.col.meaning': { en: 'Means', bn: 'অর্থ' },
    'counterparties.show.owes_us': { en: '{name} owes us', bn: '{name}-এর কাছে আমাদের পাওনা' },
    'counterparties.show.we_owe': { en: 'We owe {name}', bn: '{name}-এর কাছে আমাদের দেনা' },
    'counterparties.show.no_balance': { en: 'Nothing outstanding in this business.', bn: 'এই ব্যবসায় কিছু বাকি নেই।' },
    'counterparties.show.linked': { en: 'Also in the ERP as', bn: 'ইআরপিতে আরও আছে' },
    'counterparties.show.created': { en: 'Added by {name} on {date}', bn: '{date} তারিখে {name} যোগ করেছেন' },
    'counterparties.show.updated': { en: 'Last changed by {name} on {date}', bn: '{date} তারিখে {name} শেষ পরিবর্তন করেছেন' },
    'counterparties.show.not_given': { en: 'Not given', bn: 'দেওয়া হয়নি' },

    // ── The statement ────────────────────────────────────────────────────
    'counterparties.statement.title': { en: 'Counterparty statement', bn: 'প্রতিপক্ষের বিবরণী' },
    'counterparties.statement.period': { en: '{from} to {to}', bn: '{from} থেকে {to}' },
    'counterparties.statement.from': { en: 'From', bn: 'থেকে' },
    'counterparties.statement.to': { en: 'To', bn: 'পর্যন্ত' },
    'counterparties.statement.print': { en: 'Print or save as PDF', bn: 'প্রিন্ট বা পিডিএফ হিসেবে সংরক্ষণ' },
    'counterparties.statement.back': { en: 'Back', bn: 'ফিরে যান' },
    'counterparties.statement.col.date': { en: 'Date', bn: 'তারিখ' },
    'counterparties.statement.col.voucher': { en: 'Voucher', bn: 'ভাউচার' },
    'counterparties.statement.col.particulars': { en: 'Particulars', bn: 'বিবরণ' },
    'counterparties.statement.col.source': { en: 'Source', bn: 'উৎস' },
    'counterparties.statement.col.debit': { en: 'Debit', bn: 'ডেবিট' },
    'counterparties.statement.col.credit': { en: 'Credit', bn: 'ক্রেডিট' },
    'counterparties.statement.col.balance': { en: 'Balance', bn: 'ব্যালান্স' },
    'counterparties.statement.opening': { en: 'Opening balance', bn: 'প্রারম্ভিক ব্যালান্স' },
    'counterparties.statement.closing': { en: 'Closing balance', bn: 'সমাপনী ব্যালান্স' },
    'counterparties.statement.no_entries': { en: 'No entries in this period.', bn: 'এই সময়ে কোনো এন্ট্রি নেই।' },
    'counterparties.statement.nothing': {
        en: 'Nothing on any loan head in this period, and nothing brought forward.',
        bn: 'এই সময়ে কোনো ঋণ হেডে কিছু নেই, আগের কোনো জেরও নেই।',
    },

    // ── The balance confirmation letter ──────────────────────────────────
    'counterparties.confirm.title': { en: 'Balance confirmation', bn: 'ব্যালান্স নিশ্চিতকরণ' },
    'counterparties.confirm.as_at': { en: 'As at', bn: 'তারিখ অনুযায়ী' },
    'counterparties.confirm.date': { en: 'Date: {date}', bn: 'তারিখ: {date}' },
    'counterparties.confirm.to': { en: 'To', bn: 'প্রাপক' },
    'counterparties.confirm.salutation': { en: 'Dear Sir or Madam,', bn: 'জনাব/জনাবা,' },
    'counterparties.confirm.intro': {
        en: 'According to the books of {business}, the balances between us as at {date} are:',
        bn: '{business}-এর খাতা অনুযায়ী {date} তারিখে আমাদের মধ্যে ব্যালান্স নিম্নরূপ:',
    },
    'counterparties.confirm.col.head': { en: 'Account', bn: 'হিসাব' },
    'counterparties.confirm.col.amount': { en: 'Amount (BDT)', bn: 'পরিমাণ (টাকা)' },
    'counterparties.confirm.col.owed_by': { en: 'Who owes', bn: 'কে দেনাদার' },
    'counterparties.confirm.owed_by_you': { en: 'You owe us', bn: 'আপনার কাছে আমাদের পাওনা' },
    'counterparties.confirm.owed_by_us': { en: 'We owe you', bn: 'আমাদের কাছে আপনার পাওনা' },
    'counterparties.confirm.nil': {
        en: 'Our books show no balance between us on this date.',
        bn: 'এই তারিখে আমাদের খাতায় আমাদের মধ্যে কোনো ব্যালান্স নেই।',
    },
    'counterparties.confirm.request': {
        en: 'Please confirm these balances by signing and returning a copy of this letter. If your records show a different balance, please tell us the difference and send a statement of your account within 15 days of receiving it.',
        bn: 'অনুগ্রহ করে এই চিঠির একটি কপিতে স্বাক্ষর করে ফেরত দিয়ে ব্যালান্সগুলো নিশ্চিত করুন। আপনার হিসাবে অন্য ব্যালান্স থাকলে, চিঠি পাওয়ার 15 দিনের মধ্যে পার্থক্যটি জানান এবং আপনার হিসাবের একটি বিবরণী পাঠান।',
    },
    'counterparties.confirm.not_demand': {
        en: 'This letter asks for confirmation only. It is not a demand for payment.',
        bn: 'এই চিঠি শুধু নিশ্চিতকরণের জন্য। এটি টাকা পরিশোধের দাবি নয়।',
    },
    'counterparties.confirm.for_business': { en: 'For {business}', bn: '{business}-এর পক্ষে' },
    'counterparties.confirm.signature': { en: 'Authorised signature', bn: 'অনুমোদিত স্বাক্ষর' },
    'counterparties.confirm.reply_title': { en: 'Confirmation', bn: 'নিশ্চিতকরণ' },
    'counterparties.confirm.reply_body': {
        en: 'We confirm that the balances above agree with our records, except as noted below.',
        bn: 'আমরা নিশ্চিত করছি যে উপরের ব্যালান্সগুলো আমাদের হিসাবের সাথে মেলে, নিচে উল্লেখ করা ব্যতিক্রম ছাড়া।',
    },
    'counterparties.confirm.differences': { en: 'Differences, if any', bn: 'পার্থক্য (যদি থাকে)' },
    'counterparties.confirm.for_counterparty': { en: 'For {name}', bn: '{name}-এর পক্ষে' },
    'counterparties.confirm.sign_lines': { en: 'Signature, name, designation, date and seal', bn: 'স্বাক্ষর, নাম, পদবি, তারিখ ও সিল' },
    'counterparties.confirm.show': { en: 'Show', bn: 'দেখান' },
} satisfies Record<string, Entry>;
