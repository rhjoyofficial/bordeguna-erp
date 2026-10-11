import type { DashboardWidget } from './types';

/**
 * Grid packing (Joy, 11 Oct 2026: rows left holes, e.g. after Gross profit).
 *
 * The page grid is one column on a phone, two from md and four from xl. Each
 * widget asks for a size (sm one column, md two, lg the whole row). Walking the
 * widgets in the person's own order, a card that no longer fits on the current
 * row would leave a hole behind it, so the card before it stretches over the
 * rest of that row instead; the last card of the page does the same. The order
 * is never changed (no grid-flow-dense), only widths.
 *
 * ⚠ Tailwind only ships classes it can see written out, hence the literal maps.
 */
const MD_SPAN: Record<number, string> = { 1: 'md:col-span-1', 2: 'md:col-span-2' };
const XL_SPAN: Record<number, string> = { 1: 'xl:col-span-1', 2: 'xl:col-span-2', 3: 'xl:col-span-3', 4: 'xl:col-span-4' };

const WANT: Record<DashboardWidget['size'], number> = { sm: 1, md: 2, lg: Infinity };

/** Spans for one grid width: the wanted span per card, stretched so no row ends early. */
export function packSpans(sizes: DashboardWidget['size'][], columns: number): number[] {
    const spans = sizes.map((size) => Math.min(WANT[size], columns));
    let used = 0;

    spans.forEach((span, index) => {
        if (used > 0 && used + span > columns) {
            spans[index - 1] += columns - used;
            used = 0;
        }

        used = (used + span) % columns;
    });

    if (used > 0 && spans.length > 0) {
        spans[spans.length - 1] += columns - used;
    }

    return spans;
}

/** The grid classes per widget key: two columns from md, four from xl. */
export function spanClasses(widgets: Pick<DashboardWidget, 'key' | 'size'>[]): Record<string, string> {
    const sizes = widgets.map((w) => w.size);
    const md = packSpans(sizes, 2);
    const xl = packSpans(sizes, 4);

    return Object.fromEntries(widgets.map((w, i) => [w.key, `${MD_SPAN[md[i]]} ${XL_SPAN[xl[i]]}`]));
}

/** Where the section chips land: a card's anchor id (scroll-mt clears the sticky chip row). */
export const widgetAnchor = (key: string) => `widget-card-${key}`;
