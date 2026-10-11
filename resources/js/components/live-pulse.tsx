import { onLiveRefreshed } from '@/lib/live-reload';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useEffect, useState } from 'react';

const SHOWN_MS = 2500;

/**
 * "Updated just now": a small, quiet pill after the page refreshed itself
 * because something changed (realtime everywhere, 11 Oct 2026). It says the
 * figures moved on their own, so nobody wonders why a row appeared.
 *
 * ⚠ No sound outside the dashboards. ⚠ Reduced motion: it fades, never slides.
 * ⚠ pointer-events none: it can never cover a button somebody is reaching for.
 */
export function LivePulse() {
    const [shownAt, setShownAt] = useState<number | null>(null);
    const reduce = useReducedMotion();

    useEffect(() => onLiveRefreshed(() => setShownAt(Date.now())), []);

    useEffect(() => {
        if (shownAt === null) {
            return;
        }

        const timer = window.setTimeout(() => setShownAt(null), SHOWN_MS);

        return () => window.clearTimeout(timer);
    }, [shownAt]);

    return (
        <div className="pointer-events-none fixed top-3 left-1/2 z-40 -translate-x-1/2" aria-live="polite">
            <AnimatePresence>
                {shownAt !== null && (
                    <motion.div
                        key={shownAt}
                        initial={reduce ? { opacity: 0 } : { opacity: 0, y: -8, scale: 0.96 }}
                        animate={reduce ? { opacity: 1 } : { opacity: 1, y: 0, scale: 1 }}
                        exit={reduce ? { opacity: 0 } : { opacity: 0, y: -4 }}
                        transition={{ duration: reduce ? 0.15 : 0.25, ease: 'easeOut' }}
                        className="bg-background/95 text-muted-foreground flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs shadow-sm backdrop-blur"
                    >
                        <span className="relative flex h-2 w-2">
                            {!reduce && <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60" />}
                            <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                        </span>
                        Updated just now
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}
