import {
    Activity,
    ArrowLeftRight,
    BadgeCheck,
    Banknote,
    CalendarCheck,
    CalendarClock,
    FileText,
    HandCoins,
    Inbox,
    Landmark,
    LayoutDashboard,
    Link2,
    ListTodo,
    Lock,
    type LucideIcon,
    Package,
    PackageCheck,
    PackageX,
    PhoneCall,
    PiggyBank,
    PlugZap,
    Receipt,
    ReceiptText,
    Search,
    ShoppingBag,
    ShoppingCart,
    Target,
    TreePalm,
    TrendingUp,
    TriangleAlert,
    Trophy,
    Truck,
    Users,
    Wallet,
} from 'lucide-react';
import type { DashboardWidget, Tone } from './types';

/**
 * The dashboards' colour language (Joy, 11 Oct 2026). Presentation only: the
 * payloads, permissions and endpoints are untouched.
 *
 * ⚠ The values live in app.css (--dash-*, .dash-acc-*), light and dark, so a
 * colour is changed in one place and dark mode is chosen, not flipped.
 */
export type Accent = 'money' | 'sales' | 'couriers' | 'stock' | 'people' | 'attention' | 'me';

const CATEGORY_ACCENT: Record<string, Accent> = {
    Money: 'money',
    Sales: 'sales',
    Couriers: 'couriers',
    Stock: 'stock',
    People: 'people',
    Work: 'attention',
    Me: 'me',
};

// The Me widgets each belong to a domain a person already knows by colour.
const KEY_ACCENT: Record<string, Accent> = {
    my_payslip: 'money',
    my_claims: 'money',
    my_attendance: 'people',
    my_leave: 'stock',
    my_tasks: 'attention',
    team_attendance: 'people',
};

const KEY_ICON: Record<string, LucideIcon> = {
    cash_position: Wallet,
    money_in_transit: Truck,
    income_trend: TrendingUp,
    gross_profit: PiggyBank,
    business_health: Activity,
    bank_unmatched: Landmark,
    period_lock: Lock,
    receivables_ageing: HandCoins,
    payables_ageing: Receipt,
    receipts_vs_payments: ArrowLeftRight,
    money_accounts: Banknote,
    claims_to_pay: ReceiptText,
    finance_approvals: BadgeCheck,
    approvals_waiting: Inbox,
    followups_due: PhoneCall,
    orders_by_state: ShoppingBag,
    orders_to_dispatch: PackageCheck,
    orders_needing_action: ShoppingCart,
    my_orders: ShoppingBag,
    sales_vs_target: Target,
    top_products: Trophy,
    stock_alerts: PackageX,
    stock_lookup: Search,
    attendance_today: Users,
    team_attendance: Users,
    leave_pending: CalendarClock,
    my_attendance: CalendarCheck,
    my_leave: TreePalm,
    my_payslip: Wallet,
    my_claims: Receipt,
    my_tasks: ListTodo,
    courier_balances: Truck,
    courier_cod_chain: Link2,
    courier_integration_health: PlugZap,
    courier_parcels_attention: TriangleAlert,
    courier_parcels_by_status: Package,
    courier_statements_pending: FileText,
};

export function accentFor(widget: Pick<DashboardWidget, 'key' | 'category'>): Accent {
    return KEY_ACCENT[widget.key] ?? CATEGORY_ACCENT[widget.category] ?? 'money';
}

export function iconFor(key: string): LucideIcon {
    return KEY_ICON[key] ?? LayoutDashboard;
}

/** Chart series in the validated fixed order. A ninth series reuses nothing: callers fold it. */
export const SERIES = [1, 2, 3, 4, 5, 6, 7, 8].map((n) => `var(--dash-series-${n})`);

export const STATUS = {
    good: 'var(--dash-good)',
    info: 'var(--dash-info)',
    warn: 'var(--dash-warn)',
    serious: 'var(--dash-serious)',
    bad: 'var(--dash-bad)',
    neutral: 'var(--dash-neutral)',
} as const;

export type StatusKey = keyof typeof STATUS;

export function toneStatus(tone: Tone): StatusKey | null {
    return tone === 'good' ? 'good' : tone === 'warn' ? 'warn' : tone === 'bad' ? 'bad' : null;
}

/**
 * What a status WORD means, for pills and courier states. Read from the words
 * the server already sends, so nothing new is asked of the payload.
 * delivered green, in transit blue, failed red, on hold amber, returned slate.
 */
export function statusForText(text: string): StatusKey {
    const t = text.toLowerCase();

    if (/return/.test(t)) {
        return 'neutral';
    }

    if (/(fail|lost|cancel|reject|error|damag|overdue|absent|unpaid|refus|undeliver|not deliver)/.test(t)) {
        return 'bad';
    }

    if (/(hold|pending|wait|draft|submitted|review|late|sent back|by hand)/.test(t)) {
        return 'warn';
    }

    // Still on the way: "To deliver", "Part delivered", "Out for delivery".
    if (/(to deliver|part deliver|out for deliver)/.test(t)) {
        return 'info';
    }

    if (/(deliver|collect|paid|settled|approved|complete|done|\bok\b|healthy|present|connected|posted)/.test(t)) {
        return 'good';
    }

    if (/(transit|picked|pickup|shipped|dispatch|booked|assigned|out for|processing|confirmed|packed|new|open)/.test(t)) {
        return 'info';
    }

    return 'neutral';
}

/** Pill classes per status: tinted fill, AA text in both modes, a dot as a second cue. */
export const PILL: Record<StatusKey, string> = {
    good: 'bg-emerald-500/12 text-emerald-800 ring-emerald-600/20 dark:text-emerald-300 dark:ring-emerald-400/25',
    info: 'bg-blue-500/12 text-blue-800 ring-blue-600/20 dark:text-blue-300 dark:ring-blue-400/25',
    warn: 'bg-amber-500/15 text-amber-800 ring-amber-600/25 dark:text-amber-300 dark:ring-amber-400/25',
    serious: 'bg-orange-500/12 text-orange-800 ring-orange-600/20 dark:text-orange-300 dark:ring-orange-400/25',
    bad: 'bg-red-500/12 text-red-800 ring-red-600/20 dark:text-red-300 dark:ring-red-400/25',
    neutral: 'bg-slate-500/12 text-slate-700 ring-slate-500/20 dark:text-slate-300 dark:ring-slate-400/25',
};

/** Text in a tone, AA on the card (700 steps in light, 400 in dark). */
export const toneText = (tone: Tone) =>
    tone === 'bad'
        ? 'text-red-700 dark:text-red-400'
        : tone === 'warn'
          ? 'text-amber-700 dark:text-amber-400'
          : tone === 'good'
            ? 'text-emerald-700 dark:text-emerald-400'
            : '';

/**
 * A brand keeps its colour wherever it appears. Bionic Garden is green by
 * name; LaLaDia takes the warm orange. ⚠ An assumption for Joy to correct in
 * this one map if the brand books say otherwise.
 */
export function brandColour(name: string, index: number): string {
    const n = name.toLowerCase();

    if (n.includes('bionic')) {
        return 'var(--dash-series-6)';
    }

    if (n.includes('laladia')) {
        return 'var(--dash-series-2)';
    }

    return SERIES[index % SERIES.length];
}

/** Two initials for a person's chip. */
export function initials(name: string): string {
    const words = name
        .replace(/[^\p{L}\p{N}\s]/gu, ' ')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (words.length === 0) {
        return '?';
    }

    return (words[0][0] + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase();
}

const AVATAR_HUES = [160, 243, 32, 199, 347, 262, 14, 188];

/** A stable soft colour per name, so the same person always wears the same chip. */
export function avatarStyle(name: string): { background: string; color: string } {
    let hash = 0;

    for (const ch of name) {
        hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
    }

    const hue = AVATAR_HUES[hash % AVATAR_HUES.length];

    return { background: `hsla(${hue}, 75%, 50%, 0.16)`, color: `hsl(${hue}, 70%, var(--dash-avatar-l, 32%))` };
}
