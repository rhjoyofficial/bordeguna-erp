import { INVENTORY_SECTIONS } from '@/components/inventory/inventory-sections';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type ReactNode } from 'react';

/**
 * The small pieces the redesigned Inventory screens share (15 Oct 2026).
 *
 * The chips, pills, panels, stats, hints and compact tables are the SAME
 * components the Assets redesign introduced, re-exported so Inventory and
 * Assets cannot drift apart in look; what is here is only what Inventory
 * adds: the breadcrumb builder, the toolbar row and the reorder-level bar.
 *
 * ⚠ Presentation only. Every number arrives from the server.
 */
export { Chip, CompactTable, Hint, Panel, Pill, Stat, type Tone } from '@/components/assets/assets-ui';

/**
 * "Inventory / Stock / Current stock": the breadcrumbs of a tab, read from the
 * sections registry so a renamed tab renames its crumb. A sub-page appends
 * its own crumbs after. The section crumb links to the section's first tab.
 */
export function inventoryCrumbs(section: string, tab?: string, ...rest: BreadcrumbItem[]): BreadcrumbItem[] {
    const found = INVENTORY_SECTIONS.find((candidate) => candidate.title === section);
    const crumbs: BreadcrumbItem[] = [{ title: 'Inventory', href: '/inventory' }];

    if (found && found.title !== 'Overview') {
        crumbs.push({ title: found.title, href: found.tabs[0].url });
    }

    const tabFound = found?.tabs.find((candidate) => candidate.title === tab);
    if (tab && tabFound) {
        crumbs.push({ title: tabFound.title, href: tabFound.url });
    }

    return [...crumbs, ...rest];
}

/**
 * The row under the tab row: a quiet note on the left (what the list is, the
 * rule it follows), the page's buttons on the right. There is no heading: the
 * tab row and the breadcrumb already say where you are.
 */
export function Toolbar({ note, actions, className }: { note?: ReactNode; actions?: ReactNode; className?: string }) {
    if (!note && !actions) return null;

    return (
        <div className={cn('flex flex-wrap items-center justify-between gap-x-4 gap-y-2', className)}>
            <div className="text-muted-foreground min-w-0 text-sm">{note}</div>
            {actions && <div className="flex flex-wrap items-center gap-2 print:hidden">{actions}</div>}
        </div>
    );
}

/** A quiet bordered line for a hint that applies to the whole page. */
export function Note({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('text-muted-foreground rounded-lg border border-dashed px-3 py-2 text-sm', className)}>{children}</div>;
}

/**
 * On hand against the reorder level, as a short bar: full and green when the
 * level is met, amber when at or below it. Nothing is drawn for a product
 * with no level, because there is nothing to compare with.
 */
export function LevelBar({ quantity, threshold, className }: { quantity: number; threshold: number | null; className?: string }) {
    if (threshold === null || threshold <= 0) return null;

    const share = Math.max(0, Math.min(100, Math.round((quantity / threshold) * 100)));
    const low = quantity <= threshold;

    return (
        <span
            role="img"
            aria-label={`${quantity} on hand, reorder at ${threshold}`}
            className={cn('bg-muted inline-block h-1.5 w-20 overflow-hidden rounded-full align-middle', className)}
        >
            <span className={cn('block h-full rounded-full', low ? 'bg-amber-500' : 'bg-primary')} style={{ width: `${share}%` }} />
        </span>
    );
}

/** A horizontal bar with its label and value, one per row of a distribution. */
export function ShareRow({ label, value, share, aside }: { label: ReactNode; value: ReactNode; share: number; aside?: ReactNode }) {
    return (
        <div>
            <div className="flex items-baseline justify-between gap-2 text-[13px]">
                <span className="truncate">{label}</span>
                <span className="text-muted-foreground shrink-0 tabular-nums">
                    {aside}
                    <span className="text-foreground font-semibold">{value}</span>
                </span>
            </div>
            <div className="bg-muted mt-1.5 h-1.5 overflow-hidden rounded-full">
                <div className="bg-primary h-full rounded-full" style={{ width: `${Math.max(share, share > 0 ? 2 : 0)}%` }} />
            </div>
        </div>
    );
}
