import type { Entry } from './entry';

// Courier booking panel, the courier's timeline, the outcome form's door
// resolutions, lost or damaged parcels and the claim on the courier.
export const courierParcel = {
    // ── Fee estimate on the booking form ────────────────────────────────
    'parcel.estimate_failed': { en: 'The courier charge could not be worked out just now.', bn: 'কুরিয়ার চার্জ এখন হিসাব করা গেল না।' },
    'parcel.estimate_working': { en: 'Working out the courier charge...', bn: 'কুরিয়ার চার্জ হিসাব হচ্ছে...' },
    'parcel.estimate_about': { en: 'Courier charge about Tk {amount}', bn: 'কুরিয়ার চার্জ আনুমানিক Tk {amount}' },
    'parcel.estimate_insufficient': { en: 'Insufficient data', bn: 'যথেষ্ট তথ্য নেই' },
    'parcel.estimate_parts': { en: 'delivery {delivery}, COD fee {cod}', bn: 'ডেলিভারি {delivery}, COD ফি {cod}' },
    'parcel.estimate_confidence': { en: 'Confidence: {label}', bn: 'নির্ভরযোগ্যতা: {label}' },
    'parcel.confidence.high': { en: 'High', bn: 'বেশি' },
    'parcel.confidence.medium': { en: 'Medium', bn: 'মাঝারি' },
    'parcel.confidence.low': { en: 'Low', bn: 'কম' },
    'parcel.confidence.none': { en: 'None', bn: 'নেই' },
    'parcel.estimate_updating': { en: 'updating...', bn: 'হালনাগাদ হচ্ছে...' },
    'parcel.estimate_return_fee': {
        en: 'If it comes back, a return fee of about Tk {amount} may apply (not included).',
        bn: 'পার্সেল ফেরত এলে আনুমানিক Tk {amount} রিটার্ন ফি লাগতে পারে (এখানে ধরা হয়নি)।',
    },

    // ── Timeline sources ────────────────────────────────────────────────
    'parcel.source.webhook': { en: 'courier', bn: 'কুরিয়ার' },
    'parcel.source.poll': { en: 'asked', bn: 'জিজ্ঞেস করা' },
    'parcel.source.booking': { en: 'ERP', bn: 'ইআরপি' },

    // ── Booking through the courier's API ───────────────────────────────
    'parcel.courier_account': { en: 'Courier account', bn: 'কুরিয়ার অ্যাকাউন্ট' },
    'parcel.sandbox': { en: ' (sandbox)', bn: ' (স্যান্ডবক্স)' },
    'parcel.amount_to_collect': { en: 'Amount to collect (whole taka)', bn: 'আদায়ের টাকা (পূর্ণ টাকা)' },
    'parcel.zero_if_paid': { en: '0 if already paid', bn: 'আগেই পরিশোধ হলে 0' },
    'parcel.weight_g': { en: 'Weight (g)', bn: 'ওজন (g)' },
    'parcel.delivery_type': { en: 'Delivery type', bn: 'ডেলিভারির ধরন' },
    'parcel.item_type': { en: 'Item type', bn: 'পণ্যের ধরন' },
    'parcel.what_inside': { en: 'What is inside', bn: 'ভেতরে কী আছে' },
    'parcel.rider_note': { en: 'Note for the rider', bn: 'রাইডারের জন্য নোট' },
    'parcel.optional': { en: 'Optional', bn: 'ইচ্ছা হলে' },
    'parcel.picked_up_from': { en: 'Picked up from {store}.', bn: '{store} থেকে পিকআপ হবে।' },
    'parcel.goes_to': { en: 'Goes to {where} on the courier list.', bn: 'কুরিয়ারের তালিকায় {where}-এ যাবে।' },
    'parcel.open_locations': { en: 'Open Locations', bn: 'লোকেশন খুলুন' },
    'parcel.paisa_rounded': {
        en: 'This challan bills {due}. Couriers collect whole taka, so {whole} is offered (rounded up, never down).',
        bn: 'এই চালানের বিল {due}। কুরিয়ার পূর্ণ টাকা আদায় করে, তাই {whole} দেওয়া হচ্ছে (সবসময় উপরের দিকে রাউন্ড, কখনো নিচে নয়)।',
    },
    'parcel.missing_weight': { en: 'Some items have no weight. Weigh the parcel and type it.', bn: 'কিছু পণ্যের ওজন নেই। পার্সেলটি মেপে ওজন লিখুন।' },
    'parcel.booking': { en: 'Booking...', bn: 'বুকিং হচ্ছে...' },
    'parcel.book_with': { en: 'Book with {courier}', bn: '{courier}-এ বুক করুন' },
    'parcel.the_courier': { en: 'the courier', bn: 'কুরিয়ার' },

    // ── A booking with no clear answer ──────────────────────────────────
    'parcel.booking_refused': { en: 'Last booking with {courier} was refused: {error}', bn: '{courier}-এ শেষ বুকিং প্রত্যাখ্যাত হয়েছে: {error}' },
    'parcel.booking_on_way': {
        en: 'A booking with {courier} is on its way. Reload in a moment.',
        bn: '{courier}-এ একটি বুকিং চলছে। একটু পরে আবার লোড করুন।',
    },
    'parcel.no_clear_answer': {
        en: '{courier} gave no clear answer to the booking of {order} ({at}).',
        bn: '{order}-এর বুকিংয়ে {courier} কোনো পরিষ্কার উত্তর দেয়নি ({at})।',
    },
    'parcel.may_not_exist': {
        en: 'The parcel may or may not exist there, so the ERP will not send it again by itself.',
        bn: 'পার্সেলটি সেখানে থাকতেও পারে, না-ও পারে, তাই ইআরপি নিজে থেকে আবার পাঠাবে না।',
    },
    'parcel.check_at': { en: 'Check at {courier}', bn: '{courier}-এ যাচাই করুন' },
    'parcel.number_in_panel': { en: 'Number in the {courier} panel', bn: '{courier} প্যানেলে থাকা নম্বর' },
    'parcel.it_was_booked': { en: 'It was booked', bn: 'বুক হয়েছিল' },
    'parcel.it_was_not_booked': { en: 'It was not booked', bn: 'বুক হয়নি' },
    'parcel.not_booked_title': { en: 'Not booked?', bn: 'বুক হয়নি?' },
    'parcel.not_booked_description': {
        en: 'Only if {order} is NOT in the {courier} panel. The challan can then be booked again.',
        bn: 'শুধু তখনই, যদি {order} {courier} প্যানেলে না থাকে। তারপর চালানটি আবার বুক করা যাবে।',
    },

    // ── What the courier says ───────────────────────────────────────────
    'parcel.courier_says': { en: 'Courier says:', bn: 'কুরিয়ার বলছে:' },
    'parcel.no_word_yet': { en: 'No word yet', bn: 'এখনও কোনো খবর নেই' },
    'parcel.last_heard': { en: 'last heard {at}', bn: 'শেষ খবর {at}' },
    'parcel.not_asked_yet': { en: 'not asked yet', bn: 'এখনও জিজ্ঞেস করা হয়নি' },
    'parcel.delivery_fee_part': { en: ', delivery fee {amount}', bn: ', ডেলিভারি ফি {amount}' },
    'parcel.cod_fee_part': { en: ', COD fee {amount}', bn: ', COD ফি {amount}' },
    'parcel.hub_weight_part': { en: ', hub weight {grams} g', bn: ', হাবের ওজন {grams} g' },
    'parcel.courier_invoice_part': { en: ', courier invoice {ref}', bn: ', কুরিয়ার ইনভয়েস {ref}' },
    'parcel.asking': { en: 'Asking...', bn: 'জিজ্ঞেস করা হচ্ছে...' },
    'parcel.refresh_status': { en: 'Refresh status', bn: 'অবস্থা হালনাগাদ করুন' },
    'parcel.cancel_at_courier': { en: 'Cancel at the courier', bn: 'কুরিয়ারে বাতিল করুন' },
    'parcel.cancel_title': { en: 'Cancel this parcel at the courier?', bn: 'এই পার্সেলটি কুরিয়ারে বাতিল করবেন?' },
    'parcel.cancel_description': {
        en: 'Only before pickup. Afterwards record it as returned so the goods go back to stock.',
        bn: 'শুধু পিকআপের আগে। পরে হলে ফেরত হিসেবে লিখুন, যাতে মাল স্টকে ফিরে আসে।',
    },
    'parcel.cancel_parcel': { en: 'Cancel parcel', bn: 'পার্সেল বাতিল' },
    'parcel.why': { en: 'Why', bn: 'কেন' },
    'parcel.hide_timeline': { en: 'Hide timeline', bn: 'টাইমলাইন লুকান' },
    'parcel.timeline': { en: 'Timeline ({count})', bn: 'টাইমলাইন ({count})' },
    'parcel.estimate_kept': { en: 'ERP estimate kept at booking: {basis}', bn: 'বুকিংয়ের সময় রাখা ইআরপির অনুমান: {basis}' },
    'parcel.event_late': { en: ', late, did not change the status', bn: ', দেরিতে এসেছে, অবস্থা বদলায়নি' },
    'parcel.event_no_change': { en: ', no status change', bn: ', অবস্থার পরিবর্তন নেই' },

    // ── Closed period ───────────────────────────────────────────────────
    'parcel.books_closed': {
        en: 'The books are closed before {open}. This keeps {date} as the date the courier gave and posts on {open}.',
        bn: '{open}-এর আগের হিসাব বন্ধ। কুরিয়ারের তারিখ হিসেবে {date} থাকবে, আর পোস্ট হবে {open}-এ।',
    },

    // ── Door resolutions ────────────────────────────────────────────────
    'parcel.door_help': {
        en: 'If the courier collected a different amount from what the customer owed, say how the difference is settled. A discount above your limit goes to the Owner and nothing is posted until the Owner approves it.',
        bn: 'ক্রেতার যা দেওয়ার কথা তার চেয়ে কুরিয়ার ভিন্ন টাকা আদায় করলে, পার্থক্যটা কীভাবে মিটবে বলুন। আপনার সীমার বেশি ছাড় মালিকের কাছে যাবে, আর মালিক অনুমোদন না দেওয়া পর্যন্ত কিছুই পোস্ট হবে না।',
    },
    'parcel.if_amount_differs': { en: 'If the amount differs', bn: 'টাকা মিলে না গেলে' },
    'parcel.it_matches': { en: 'It matches what was owed', bn: 'যা পাওনা ছিল তার সাথে মিলে গেছে' },
    'parcel.door.discount': { en: 'Discount at the door', bn: 'দরজায় ছাড়' },
    'parcel.door.price_correction': { en: 'Price correction', bn: 'দাম সংশোধন' },
    'parcel.door.short_collection': { en: 'Short collection (customer owes the rest)', bn: 'কম আদায় (বাকিটা ক্রেতার কাছে পাওনা)' },
    'parcel.door.rounding': { en: 'Rounding (under Tk 1)', bn: 'রাউন্ডিং (Tk 1-এর কম)' },
    'parcel.product': { en: 'Product', bn: 'পণ্য' },
    'parcel.quantity': { en: 'Quantity', bn: 'পরিমাণ' },
    'parcel.corrected_unit_price': { en: 'Corrected unit price', bn: 'সংশোধিত একক দাম' },
    'parcel.unchanged': { en: 'unchanged', bn: 'অপরিবর্তিত' },

    // ── Lost or damaged ─────────────────────────────────────────────────
    'parcel.report_loss': { en: 'Report lost or damaged', bn: 'হারানো বা ক্ষতিগ্রস্ত জানান' },
    'parcel.loss_help': {
        en: 'The goods are written off through an inventory adjustment (its approver decides), and a claim is opened on the courier. Its compensation closes the claim when it arrives on a payout statement or in the bank.',
        bn: 'মাল ইনভেন্টরি অ্যাডজাস্টমেন্টের মাধ্যমে বাদ দেওয়া হবে (তার অনুমোদনকারী সিদ্ধান্ত নেবেন), আর কুরিয়ারের কাছে একটি দাবি খোলা হবে। ক্ষতিপূরণ পেআউট স্টেটমেন্টে বা ব্যাংকে এলে দাবিটি বন্ধ হবে।',
    },
    'parcel.what_happened': { en: 'What happened', bn: 'কী ঘটেছে' },
    'parcel.lost_by_courier': { en: 'Lost by the courier', bn: 'কুরিয়ার হারিয়েছে' },
    'parcel.damaged_by_courier': { en: 'Damaged by the courier', bn: 'কুরিয়ার নষ্ট করেছে' },
    'parcel.reported_on': { en: 'Reported on', bn: 'জানানোর তারিখ' },
    'parcel.claim_from_courier': { en: 'Claim from the courier', bn: 'কুরিয়ারের কাছে দাবি' },
    'parcel.notes_optional': { en: 'Notes (optional)', bn: 'নোট (ইচ্ছা হলে)' },
    'parcel.record_open_claim': { en: 'Record and open the claim', bn: 'লিখে রাখুন ও দাবি খুলুন' },
    'parcel.cancel': { en: 'Cancel', bn: 'বাতিল' },

    // ── The courier's answer to a claim, and compensation ───────────────
    'parcel.courier_answer': { en: 'Answer from the courier', bn: 'কুরিয়ারের উত্তর' },
    'parcel.courier_accepted': { en: 'Courier accepted', bn: 'কুরিয়ার মেনে নিয়েছে' },
    'parcel.courier_rejected': { en: 'Courier rejected', bn: 'কুরিয়ার প্রত্যাখ্যান করেছে' },
    'parcel.amount_accepted': { en: 'Amount accepted', bn: 'মেনে নেওয়া টাকা' },
    'parcel.note': { en: 'Note', bn: 'নোট' },
    'parcel.why_required': { en: 'Why (required)', bn: 'কেন (আবশ্যক)' },
    'parcel.note_optional': { en: 'Note (optional)', bn: 'নোট (ইচ্ছা হলে)' },
    'parcel.save_answer': { en: 'Save answer', bn: 'উত্তর সংরক্ষণ করুন' },
    'parcel.paid_outside': { en: 'Paid outside a statement:', bn: 'স্টেটমেন্টের বাইরে পরিশোধ:' },
    'parcel.arrived_in': { en: 'Arrived in', bn: 'যেখানে এসেছে' },
    'parcel.arrived_in_pick': { en: 'Arrived in...', bn: 'যেখানে এসেছে...' },
    'parcel.amount_received': { en: 'Amount received', bn: 'পাওয়া টাকা' },
    'parcel.received_on': { en: 'Received on', bn: 'পাওয়ার তারিখ' },
    'parcel.bank_reference': { en: 'Bank reference', bn: 'ব্যাংক রেফারেন্স' },
    'parcel.reference': { en: 'Reference', bn: 'রেফারেন্স' },
    'parcel.record_compensation': { en: 'Record compensation', bn: 'ক্ষতিপূরণ লিখে রাখুন' },

    // ── What was recorded about the parcel's money ──────────────────────
    'parcel.discount_waiting': {
        en: 'A {amount} discount at the door is waiting for the Owner',
        bn: 'দরজায় দেওয়া {amount} ছাড় মালিকের সিদ্ধান্তের অপেক্ষায়',
    },
    'parcel.asked_by': { en: ' (asked by {name})', bn: ' ({name} চেয়েছেন)' },
    'parcel.nothing_posted_until': { en: 'Nothing is posted until the Owner decides.', bn: 'মালিক সিদ্ধান্ত না নেওয়া পর্যন্ত কিছুই পোস্ট হবে না।' },
    'parcel.difference_part': { en: ', difference {amount}', bn: ', পার্থক্য {amount}' },
    'parcel.claim_on_courier': { en: 'Claim on the courier', bn: 'কুরিয়ারের কাছে দাবি' },
    'parcel.claim_kind.lost': { en: 'Lost', bn: 'হারানো' },
    'parcel.claim_kind.damaged': { en: 'Damaged', bn: 'ক্ষতিগ্রস্ত' },
    'parcel.claim_status.open': { en: 'Open', bn: 'খোলা' },
    'parcel.claim_status.accepted': { en: 'Accepted by the courier', bn: 'কুরিয়ার মেনে নিয়েছে' },
    'parcel.claim_status.paid': { en: 'Compensation received', bn: 'ক্ষতিপূরণ পাওয়া গেছে' },
    'parcel.claim_status.rejected': { en: 'Rejected by the courier', bn: 'কুরিয়ার প্রত্যাখ্যান করেছে' },
    'parcel.claim_summary': { en: '{kind}. Goods at cost {cost}, claimed {claimed}', bn: '{kind}। মালের ক্রয়মূল্য {cost}, দাবি {claimed}' },
    'parcel.claim_received': { en: ', received {amount}', bn: ', পাওয়া গেছে {amount}' },
    'parcel.on_payout_statement': { en: ' on a payout statement', bn: ' পেআউট স্টেটমেন্টে' },
    'parcel.write_off': { en: ' Write-off: {items}.', bn: ' বাদ দেওয়া: {items}।' },
    'parcel.pending': { en: 'pending', bn: 'অপেক্ষমাণ' },
    'parcel.not_for_this_order': { en: ', not for this order', bn: ', এই অর্ডারের জন্য নয়' },
} satisfies Record<string, Entry>;
