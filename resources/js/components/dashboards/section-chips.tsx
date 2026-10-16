import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useReducedMotion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';

/** Where a section of the dashboard starts: the chips scroll here (scroll-mt clears the sticky row). */
export const sectionAnchor = (group: string) => `dash-section-${group}`;

/**
 * "Jump to" chips (Joy, 11 Oct 2026: the dashboard is long; round 3, 16 Oct:
 * one per detail SECTION, since the courier widgets became three cards). One
 * chip per area this person actually has on the page (Money, Sales, Couriers,
 * People, Stock, ...), in page order. A chip scrolls to its section; the chip
 * of the section being read is lit, worked out by an IntersectionObserver
 * (never a scroll listener).
 *
 * A slim sticky row of its own, so it never sits on top of the date or brand
 * buttons. On a phone the chips scroll sideways inside the row, never the page.
 */
export function SectionChips({ groups }: { groups: string[] }) {
    const { t, tr } = useTranslation();
    const reduce = useReducedMotion();
    const [active, setActive] = useState<string | null>(groups[0] ?? null);
    const visible = useRef(new Set<string>());
    const rail = useRef<HTMLDivElement>(null);
    const key = groups.join('|');

    useEffect(() => {
        if (typeof IntersectionObserver === 'undefined') {
            return;
        }

        const sections = groups.map((group) => document.getElementById(sectionAnchor(group))).filter((el): el is HTMLElement => el !== null);
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

                const first = groups.find((group) => seen.has(sectionAnchor(group)));

                if (first) {
                    setActive(first);

                    return;
                }

                // Nothing in the band (a tall section): the last one already
                // scrolled past says where the reader is.
                const passed = sections.filter((section) => section.getBoundingClientRect().top < 64).pop();
                setActive(passed?.dataset.dashGroup ?? groups[0] ?? null);
            },
            { rootMargin: '-64px 0px -55% 0px' },
        );

        sections.forEach((section) => observer.observe(section));

        return () => observer.disconnect();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- `key` stands for the list of groups
    }, [key]);

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

    const jump = (group: string) => {
        setActive(group);
        document.getElementById(sectionAnchor(group))?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    };

    return (
        <div className="bg-background/85 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-20 -mx-4 px-4 py-2 backdrop-blur-md">
            <nav aria-label={t('dash.jump.label')}>
                <div
                    ref={rail}
                    className="bg-muted/70 ring-border/60 flex w-max max-w-full gap-1 overflow-x-auto rounded-full p-1 ring-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                >
                    {groups.map((group) => (
                        <button
                            key={group}
                            type="button"
                            onClick={() => jump(group)}
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
