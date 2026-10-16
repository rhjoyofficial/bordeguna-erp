import type { TranslationKey } from '@/lib/i18n';
import type { DashboardWidget } from './types';

/**
 * Where each widget sits on the calm, role-aware dashboard (redesign round 3,
 * Joy, 16 Oct 2026; workspace-design MAPPING §3 and §5). Plain data and pure
 * functions, kept apart from the components so a test can run them
 * (tests/js/dashboard-arrangement.harness.ts).
 *
 * ⚠ Placement only. Which widgets a person gets is the server's (each widget's
 * own permission, WidgetRegistry); nothing here can add one.
 */

/** "Needs you today": list widgets whose rows join the block, per dashboard, with their chip. */
export const NEEDS_ROW_WIDGETS: Record<string, { key: string; chip: string }[]> = {
    finance: [{ key: 'finance_approvals', chip: 'approvals' }],
    manager: [{ key: 'followups_due', chip: 'followups' }],
    sales: [
        { key: 'orders_needing_action', chip: 'orders' },
        { key: 'followups_due', chip: 'followups' },
        { key: 'approvals_waiting', chip: 'approvals' },
    ],
    employee: [{ key: 'my_tasks', chip: 'todos' }],
};

/**
 * Widgets "Needs you today" draws, so the page does not draw them again as
 * cards. Approvals waiting is a Needs attention row on Owner and Manager, and
 * the business health signals are the strip at its foot. Finance's unmatched
 * bank lines are a Needs attention row (BankUnmatchedSource) too.
 */
export const NEEDS_WIDGETS: Record<string, string[]> = {
    owner: ['needs_attention', 'approvals_waiting', 'business_health'],
    finance: ['needs_attention', 'finance_approvals', 'bank_unmatched'],
    manager: ['needs_attention', 'approvals_waiting', 'business_health', 'followups_due'],
    sales: ['orders_needing_action', 'followups_due', 'approvals_waiting'],
    employee: ['my_tasks'],
};

export type Better = 'up' | 'down';

export interface KeyRecipe {
    key: string;
    /** Which direction of change is good news (the words' colour). */
    better: Better;
    /** A shorter name than the widget title. */
    label?: TranslationKey;
}

/** The four Key numbers per dashboard. Owner's fourth is Gross profit (decision 4, 16 Oct 2026). */
export const KEY_RECIPES: Record<string, KeyRecipe[]> = {
    owner: [
        { key: 'cash_position', better: 'up' },
        { key: 'income_trend', better: 'up' },
        { key: 'money_in_transit', better: 'down', label: 'dash.key.with_couriers' },
        { key: 'gross_profit', better: 'up' },
    ],
    finance: [
        { key: 'cash_position', better: 'up' },
        { key: 'receivables_ageing', better: 'down', label: 'dash.key.owed_to_us' },
        { key: 'payables_ageing', better: 'down', label: 'dash.key.we_owe' },
        { key: 'money_in_transit', better: 'down', label: 'dash.key.with_couriers' },
    ],
    manager: [
        { key: 'orders_to_dispatch', better: 'down' },
        { key: 'approvals_waiting', better: 'down' },
        { key: 'stock_alerts', better: 'down' },
        { key: 'team_attendance', better: 'up', label: 'dash.key.my_team_today' },
    ],
    sales: [
        { key: 'sales_vs_target', better: 'up' },
        { key: 'my_orders', better: 'up' },
        { key: 'orders_needing_action', better: 'down' },
        { key: 'followups_due', better: 'down' },
    ],
    employee: [
        { key: 'my_attendance', better: 'up', label: 'dash.hero.days_present' },
        { key: 'my_leave', better: 'up' },
        { key: 'my_payslip', better: 'up' },
        { key: 'my_claims', better: 'down' },
    ],
};

/** Widgets that are only a Key number on a dashboard, never a card as well. */
export const KEY_ONLY: Record<string, string[]> = {
    owner: ['cash_position', 'money_in_transit', 'gross_profit'],
    finance: ['cash_position', 'money_in_transit'],
    sales: ['sales_vs_target', 'my_orders'],
};

/** The courier widgets as three cards, each reading its widgets' payloads unchanged. */
export const COURIER_CARDS: { key: string; title: TranslationKey; widgets: string[] }[] = [
    { key: 'parcels', title: 'dash.courier.parcels', widgets: ['courier_parcels_by_status', 'courier_parcels_attention'] },
    { key: 'cod', title: 'dash.courier.cod', widgets: ['courier_cod_chain', 'courier_balances', 'courier_statements_pending'] },
    { key: 'connections', title: 'dash.courier.connections', widgets: ['courier_integration_health'] },
];

export const COURIER_WIDGETS = COURIER_CARDS.flatMap((card) => card.widgets);

/**
 * The detail areas below Shortcuts, in page order: every widget not drawn
 * above, grouped by its category, the courier widgets as one "Couriers" area.
 */
export function detailGroups(widgets: Pick<DashboardWidget, 'key' | 'category'>[], dashboardKey: string): { group: string; keys: string[] }[] {
    const drawnAbove = new Set([...(NEEDS_WIDGETS[dashboardKey] ?? []), ...(KEY_ONLY[dashboardKey] ?? [])]);
    const groups: { group: string; keys: string[] }[] = [];

    for (const widget of widgets) {
        if (drawnAbove.has(widget.key)) {
            continue;
        }

        const group = COURIER_WIDGETS.includes(widget.key) ? 'Couriers' : widget.category;
        const found = groups.find((g) => g.group === group);

        if (found) {
            found.keys.push(widget.key);
        } else {
            groups.push({ group, keys: [widget.key] });
        }
    }

    return groups;
}

/** Every widget key a dashboard draws somewhere: Needs, Key numbers or a detail area. */
export function placedKeys(widgets: Pick<DashboardWidget, 'key' | 'category'>[], dashboardKey: string): string[] {
    const present = new Set(widgets.map((w) => w.key));
    const needs = [...(NEEDS_WIDGETS[dashboardKey] ?? []), ...(NEEDS_ROW_WIDGETS[dashboardKey] ?? []).map((w) => w.key)].filter((key) =>
        present.has(key),
    );
    const key = (KEY_RECIPES[dashboardKey] ?? []).map((r) => r.key).filter((k) => present.has(k));
    const detail = detailGroups(widgets, dashboardKey).flatMap((g) => g.keys);

    return [...new Set([...needs, ...key, ...detail])];
}
