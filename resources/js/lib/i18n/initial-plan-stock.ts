import type { Entry } from './entry';

/**
 * Joy's initial plan, stock slices (24 Oct 2026): B6 loss reasons with their
 * own heads, B7 the Not for sale (quarantine) warehouse with Move back to
 * stock, and B8 the batch every production input is taken from.
 */
export const ipStock = {
    // ── B6: loss reasons ─────────────────────────────────────────────────
    'ips.loss.head': { en: 'The loss goes to {head}.', bn: 'ক্ষতি যাবে {head} খাতে।' },
    'ips.loss.q_spoiled': { en: 'How many spoiled', bn: 'কতগুলো নষ্ট (পচে) গেছে' },
    'ips.loss.q_damaged_return': { en: 'How many came back damaged', bn: 'কতগুলো ভাঙা/নষ্ট অবস্থায় ফেরত এসেছে' },
    'ips.loss.report_title': { en: 'Losses by reason', bn: 'কারণ অনুযায়ী ক্ষতি' },
    'ips.loss.report_hint': {
        en: "Approved stock corrections in this period, at each batch's own cost. A minus amount is stock that turned up.",
        bn: 'এই সময়ের অনুমোদিত স্টক সংশোধন, প্রতিটি ব্যাচের নিজের দামে। মাইনাস মানে স্টক পাওয়া গেছে।',
    },
    'ips.loss.from': { en: 'From', bn: 'থেকে' },
    'ips.loss.to': { en: 'To', bn: 'পর্যন্ত' },
    'ips.loss.col_reason': { en: 'Reason', bn: 'কারণ' },
    'ips.loss.col_head': { en: 'Head', bn: 'খাত' },
    'ips.loss.col_count': { en: 'Corrections', bn: 'সংশোধন' },
    'ips.loss.col_amount': { en: 'Loss', bn: 'ক্ষতি' },
    'ips.loss.total': { en: 'Total', bn: 'মোট' },
    'ips.loss.none': { en: 'No approved stock corrections in this period.', bn: 'এই সময়ে কোনো অনুমোদিত স্টক সংশোধন নেই।' },
} satisfies Record<string, Entry>;
