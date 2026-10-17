import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useReducedMotion } from 'motion/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { widgetAnchor } from './layout';
import type { DashboardWidget } from './types';

/**
 * Section chips (Joy, 11 Oct 2026: the dashboard is long). One chip per
 * widget category this person actually has on the page (Money, Sales,
 * Couriers, People, Stock, ...), in the order the page shows them. A chip
 * scrolls to the first card of its group; the chip of the section being read
 * is lit, worked out by an IntersectionObserver (never a scroll listener).
 *
 * A slim sticky row of its own under the filters, so it never sits on top of
 * the date or brand buttons. On a phone the chips scroll sideways inside the
 * row, never the page.
 */
export function SectionChips({ widgets }: { widgets: DashboardWidget[] }) {
    const { t, tr } = useTranslation();
    const reduce = useReducedMotion();
    const groups = useMemo(() => {
        const seen: { group: string; first: string }[] = [];

        for (const widget of widgets) {
            if (!seen.some((s) => s.group === widget.category)) {
                seen.push({ group: widget.category, first: widget.key });
            }
        }

        return seen;
    }, [widgets]);
    const [active, setActive] = useState<string | null>(groups[0]?.group ?? null);
    const visible = useRef(new Set<string>());
    const rail = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (typeof IntersectionObserver === 'undefined') {
            return;
        }

        const cards = widgets.map((w) => document.getElementById(widgetAnchor(w.key))).filter((el): el is HTMLElement => el !== null);
        const seen = visible.current;
        seen.clear();

        // The reading band: from just under the sticky row to 55% of the screen.
        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        seen.add(entry.target.id);
                    } else {
                        seen.delete(entry.target.id);
                    }
                }

                const first = widgets.find((w) => seen.has(widgetAnchor(w.key)));

                if (first) {
                    setActive(first.category);

                    return;
                }

                // Nothing in the band (a tall card, or the hero at the top): the
                // last card already scrolled past says where the reader is, or
                // the first section when none has been reached yet.
                const passed = cards.filter((card) => card.getBoundingClientRect().top < 64).pop();
                const group = passed?.dataset.dashGroup ?? widgets[0]?.category;

                if (group) {
                    setActive(group);
                }
            },
            { rootMargin: '-64px 0px -55% 0px' },
        );

        cards.forEach((card) => observer.observe(card));

        return () => observer.disconnect();
    }, [widgets]);

    // Keep the lit chip in view inside the row on a phone, without moving the page.
    useEffect(() => {
        const row = rail.current;
        const chip = row?.querySelector<HTMLElement>('[aria-current="true"]');

        if (row && chip && row.scrollWidth > row.clientWidth) {
            const left = chip.offsetLeft - row.offsetLeft;
            const target = Math.max(0, left - (row.clientWidth - chip.offsetWidth) / 2);
            row.scrollTo({ left: target, behavior: reduce ? 'auto' : 'smooth' });
        }
    }, [active, reduce]);

    if (groups.length < 2) {
        return null;
    }

    const jump = (group: string, key: string) => {
        setActive(group);
        document.getElementById(widgetAnchor(key))?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    };

    return (
        <div className="bg-background/85 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-20 -mx-4 px-4 py-2 backdrop-blur-md">
            <nav aria-label={t('dash.jump.label')}>
                <div
                    ref={rail}
                    className="bg-muted/70 ring-border/60 flex w-max max-w-full gap-1 overflow-x-auto rounded-full p-1 ring-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                >
                    {groups.map(({ group, first }) => (
                        <button
                            key={group}
                            type="button"
                            onClick={() => jump(group, first)}
                            aria-current={active === group ? 'true' : undefined}
                            className={cn(
                                'shrink-0 rounded-full px-3 py-1 text-xs whitespace-nowrap transition-colors',
                                active === group
                                    ? 'bg-background text-foreground ring-border font-medium shadow-sm ring-1'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {tr(`dash.jump.${group}`, group)}
                        </button>
                    ))}
                </div>
            </nav>
        </div>
    );
}
