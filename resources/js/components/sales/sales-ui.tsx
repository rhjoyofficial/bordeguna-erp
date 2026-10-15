import { Chip, CompactTable, Hint, Meter, Panel, Pill, Stat, type Tone } from '@/components/assets/assets-ui';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Settings2 } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * The small pieces the redesigned Sales screens share (15 Oct 2026). The
 * chips, stats, panels, pills, meters and hints are the ones the Assets
 * redesign introduced (assets-ui), re-exported so a Sales page imports one
 * module; the rest is Sales' own: the courier's word as a pill, the actions
 * row that replaced the page title, and the header link to Settings › Couriers.
 *
 * ⚠ Presentation only. Every figure and every word arrives from the server.
 */
export { Chip, CompactTable, Hint, Meter, Panel, Pill, Stat };
export type { Tone };

/** What the server says the courier says, as the orders list and the overview show it. */
export interface CourierWord {
    label: string;
    tone: 'ok' | 'info' | 'warning' | 'danger' | 'muted';
    note: string | null;
    consignment_id: number;
    delivery_id: number;
}

const WORD_TONE: Record<CourierWord['tone'], Tone> = { ok: 'ok', info: 'info', warning: 'warning', danger: 'danger', muted: 'muted' };

/** "Courier says": the pill and the quiet line under it. */
export function CourierSaysCell({ word }: { word: CourierWord | null }) {
    if (!word) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <span className="inline-flex flex-col items-start gap-0.5">
            <Pill tone={WORD_TONE[word.tone]}>{word.label}</Pill>
            {word.note && <span className="text-muted-foreground text-xs">{word.note}</span>}
        </span>
    );
}

/**
 * The row above a list where the page title used to be: the tab row names
 * the screen now, so only the actions remain, on the right, and a one-line
 * note on the left when the page has one.
 */
export function ActionsRow({ note, children, className }: { note?: ReactNode; children?: ReactNode; className?: string }) {
    if (!note && !children) return null;

    return (
        <div className={cn('flex flex-wrap items-center justify-between gap-3', className)}>
            <div className="text-muted-foreground text-sm">{note}</div>
            {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
        </div>
    );
}

/**
 * The link from a Parcels page to Settings › Couriers: accounts, locations
 * and rate cards live there and are not repeated as Sales tabs. Shown to
 * whoever may open that area (the same two flags its tabs use).
 */
export function CourierSettingsLink() {
    const { canManageCourierSettings, canProposeRateCards } = usePage<SharedData>().props;
    const { t } = useTranslation();

    if (!canManageCourierSettings && !canProposeRateCards) return null;

    return (
        <Link
            href={canManageCourierSettings ? '/settings/couriers' : '/settings/couriers/rate-cards'}
            className="text-muted-foreground hover:text-foreground inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs whitespace-nowrap"
        >
            <Settings2 className="size-3.5" />
            {t('nav.courier_settings')}
        </Link>
    );
}

/** Taka with grouping and no decimals, for a card; the server sends the number. */
export function money0(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '-';
    const number = Number(String(value).replace(/,/g, ''));

    return Number.isFinite(number) ? number.toLocaleString('en-US', { maximumFractionDigits: 0 }) : String(value);
}

/** Taka with two decimals, as every money figure in the ERP reads. */
export function money2(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '-';
    const number = Number(String(value).replace(/,/g, ''));

    return Number.isFinite(number) ? number.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : String(value);
}
