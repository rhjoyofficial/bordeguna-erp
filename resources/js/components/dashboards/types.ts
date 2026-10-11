import type { AttentionItemData } from '@/components/attention/attention-list';
/**
 * The role dashboards' payload shapes. Mirrors App\Support\Dashboards\Widget:
 * every widget returns one of these kinds. Money is taka as a number (computed
 * from paisa on the server), so it can be counted up and charted.
 */

export type Unit = 'money' | 'count' | 'percent' | 'days' | 'quantity' | 'text';
export type Tone = 'good' | 'warn' | 'bad' | null | undefined;
export type Basis = 'ledger' | 'operational' | 'estimate';
export type SoundClass = 'success' | 'attention' | 'error';

export interface Part {
    label: string;
    value: number | string | null;
    unit?: Unit;
    tone?: Tone;
    url?: string;
    previous?: number;
}

interface Common {
    basis?: Basis;
    parts?: Part[];
    caption?: string | null;
    /** Named counts compared between refreshes to decide whether something happened. */
    watch?: Record<string, number>;
}

export interface StatPayload extends Common {
    kind: 'stat';
    value: number | string;
    unit: Unit;
    previous?: number | null;
    tone?: Tone;
}

export interface SeriesPayload extends Common {
    kind: 'series';
    chart: 'area' | 'line' | 'bar';
    unit: Unit;
    series: { key: string; label: string }[];
    points: ({ date: string; label: string } & Record<string, number | string>)[];
    total?: number;
    total_label?: string;
    previous_total?: number;
}

export interface BreakdownItem {
    label: string;
    value: number;
    tone?: Tone;
    url?: string;
    hint?: string;
}

export interface BreakdownPayload extends Common {
    kind: 'breakdown';
    chart: 'bar' | 'donut';
    unit: Unit;
    items: BreakdownItem[];
    total?: number;
    headline?: string;
}

export interface Column {
    key: string;
    label: string;
    align?: 'right';
    unit?: Unit;
}

export interface Row {
    id: number | string;
    url?: string;
    tone?: Tone;
    cells: Record<string, string | number | null>;
}

export interface ListPayload extends Common {
    kind: 'list' | 'lookup';
    columns: Column[];
    rows: Row[];
    total_count: number;
}

export interface SignalsPayload extends Common {
    kind: 'signals';
    items: { key: string; label: string; count: number; url: string; hint: string }[];
}

export interface ProgressPayload extends Common {
    kind: 'progress';
    unit: Unit;
    value: number;
    target: number | null;
}

export interface EmptyPayload extends Common {
    kind: 'empty';
    message: string;
}

/** Needs attention's top items (NeedsAttentionWidget), shown with the page's own rows. */
export interface AttentionPayload extends Common {
    kind: 'attention';
    items: AttentionItemData[];
    total_count: number;
    counts: Record<'critical' | 'high' | 'normal', number>;
    failed: { key: string; area: string }[];
}

export type WidgetPayload =
    | StatPayload
    | SeriesPayload
    | BreakdownPayload
    | ListPayload
    | SignalsPayload
    | ProgressPayload
    | AttentionPayload
    | EmptyPayload;

export interface WidgetResult {
    key: string;
    status: 'ok' | 'error';
    data: WidgetPayload | null;
    message: string | null;
    generated_at: string;
}

export interface NotificationRule {
    watch: string;
    sound: SoundClass;
    message: string;
}

export interface WidgetMeta {
    key: string;
    title: string;
    description: string | null;
    category: string;
    scope: 'business' | 'team' | 'self';
    size: 'sm' | 'md' | 'lg';
    refresh_seconds: number;
    cache_seconds: number;
    date_basis: string | null;
    drill_down: string | null;
    empty_text: string;
    refresh_on: string[];
    notifications: NotificationRule[];
}

export interface DashboardWidget extends WidgetMeta {
    result: WidgetResult;
}

export interface SoundSettings {
    enabled: boolean;
    volume: number;
    categories: Record<SoundClass, boolean>;
    quiet_hours: { enabled: boolean; from: string; to: string };
    customised?: boolean;
}

export interface RangeState {
    preset: string;
    from: string;
    to: string;
    label: string;
    previous: { from: string; to: string };
    query: Record<string, string>;
}

export interface DashboardEvent {
    type: string;
    ids: Record<string, number | string>;
    widgets: string[];
    /** 'none' = a routine move: refresh the widgets, play nothing. */
    sound: SoundClass | 'none';
    actor_id: number | null;
    personal: boolean;
    at: string;
}
