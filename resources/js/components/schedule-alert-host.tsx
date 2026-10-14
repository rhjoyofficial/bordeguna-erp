import { playSample } from '@/lib/audio-unlock';
import { useTranslation } from '@/lib/i18n';
import { inQuietHours } from '@/lib/popup-engine';
import { BUILT_IN_SOUND, CHECK_EVERY_MS, KIND_STYLE, claim, connect, dueSlots, formatClock, markSeen, syncClock } from '@/lib/schedule-engine';
import { cn } from '@/lib/utils';
import { type ScheduleSlot, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * The work schedule alert (14 Oct 2026, Joy: "when time come notification
 * arrives with sound and screen color blinks and show the reason, after some
 * second disappear"). When a slot starts, the edge of the screen glows in the
 * slot's colour three gentle times and a compact note at the top names the
 * slot, then both fade after the configured seconds.
 *
 * ⚠ NON-BLOCKING. Nothing here takes focus, dims the page or catches clicks
 * (pointer-events none), except the note's own Close button: it shows in the
 * middle of a sale at the counter and must not get in the way, so it ignores
 * the popup hold gate on purpose. It sits below the popups (z-[90] under
 * z-[100]).
 *
 * ⚠ FLASHING. Three pulses of about 1.2 s each, far under the WCAG limit of
 * three flashes a second; with reduced motion the edge is a steady colour.
 *
 * Mounted once in the app layout, beside the popup host, so guest and print
 * pages never show it. Each person can switch it, and its sound, off in
 * Settings > Appearance; the managers can switch it off for everybody.
 */

/** One pulse of the edge glow; three of them. */
const PULSE_SECONDS = 1.2;
/** The sound recordings are mastered loud. */
const GAIN = 0.4;

/**
 * ⚠ Every Inertia page renders its own layout, so a page change re-creates this
 * component. The alert on screen lives here, outside React, so a link clicked
 * during an alert does not cut it short.
 */
let current: ScheduleSlot | null = null;
let openUntil = 0;

export function ScheduleAlertHost() {
    const { schedule, popups } = usePage<SharedData>().props;
    const feed = schedule ?? null;

    const { t } = useTranslation();
    const reduceMotion = useReducedMotion();
    const [open, setOpen] = useState<ScheduleSlot | null>(() => (openUntil > Date.now() ? current : null));
    const feedRef = useRef(feed);
    feedRef.current = feed;
    const quietRef = useRef(popups?.quiet_hours ?? null);
    quietRef.current = popups?.quiet_hours ?? null;
    const link = useRef<ReturnType<typeof connect> | null>(null);
    const checking = useRef(false);

    // The server's clock, every page load.
    useEffect(() => {
        syncClock(feed?.server_now);
    }, [feed?.server_now]);

    const close = useCallback(() => {
        current = null;
        openUntil = 0;
        setOpen(null);
    }, []);

    const show = useCallback((slot: ScheduleSlot) => {
        const settings = feedRef.current;
        const seconds = settings?.alert_seconds ?? 8;

        current = slot;
        openUntil = Date.now() + seconds * 1000;
        setOpen(slot);

        // Best effort, never late: a locked browser or quiet hours mean silence.
        if (settings?.sound && !inQuietHours(quietRef.current)) {
            playSample(settings.sound_url ?? BUILT_IN_SOUND, GAIN);
        }
    }, []);

    const check = useCallback(async () => {
        const settings = feedRef.current;

        if (!settings || checking.current) {
            return;
        }

        // ⚠ Called even while switched off or hidden: the first call after a
        // page load records what has already started, and a slot that was due
        // while switched off must not fire the moment it is switched on.
        const due = dueSlots(settings.slots);

        if (due.length === 0 || !settings.enabled || document.visibilityState !== 'visible') {
            if (!settings.enabled) {
                due.forEach(({ key }) => markSeen(key));
            }

            return;
        }

        checking.current = true;

        try {
            // The latest start wins when two are due at once (a tab waking up).
            for (const { slot, key } of [...due].reverse()) {
                if (await claim(key)) {
                    link.current?.send({ type: 'shown', key });
                    show(slot);
                    break;
                }
            }
        } finally {
            checking.current = false;
        }
    }, [show]);

    useEffect(() => {
        link.current = connect();

        return () => {
            link.current?.close();
            link.current = null;
        };
    }, []);

    useEffect(() => {
        void check();

        const timer = window.setInterval(() => void check(), CHECK_EVERY_MS);
        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                void check();
            }
        };
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [check]);

    // Fades by itself after its seconds.
    useEffect(() => {
        if (!open) {
            return;
        }

        const timer = window.setTimeout(close, Math.max(0, openUntil - Date.now()));

        return () => window.clearTimeout(timer);
    }, [open, close]);

    if (typeof document === 'undefined') {
        return null;
    }

    const style = open ? (KIND_STYLE[open.kind] ?? KIND_STYLE.focus) : null;
    const Icon = style?.icon;
    const ease = [0.16, 1, 0.3, 1] as const;

    return createPortal(
        <AnimatePresence>
            {open && style && Icon && (
                <motion.div
                    key={`schedule-edge-${open.id}`}
                    aria-hidden
                    className="pointer-events-none fixed inset-0 z-[90]"
                    style={{ boxShadow: `inset 0 0 0 4px ${style.edge}, inset 0 0 48px 12px ${style.edge}` }}
                    initial={{ opacity: 0 }}
                    animate={reduceMotion ? { opacity: 1 } : { opacity: [0, 1, 0.25, 1, 0.25, 1, 0.6] }}
                    exit={{ opacity: 0 }}
                    transition={reduceMotion ? { duration: 0.2 } : { duration: PULSE_SECONDS * 3, ease: 'easeInOut' }}
                />
            )}
            {open && style && Icon && (
                <motion.div
                    key={`schedule-note-${open.id}`}
                    className="pointer-events-none fixed inset-x-0 top-3 z-[90] flex justify-center px-4"
                    initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: -16 }}
                    animate={reduceMotion ? { opacity: 1 } : { opacity: 1, y: 0 }}
                    exit={reduceMotion ? { opacity: 0 } : { opacity: 0, y: -12 }}
                    transition={{ duration: reduceMotion ? 0.15 : 0.45, ease }}
                >
                    <div
                        role="status"
                        aria-live="polite"
                        className={cn('flex w-full max-w-md items-start gap-3 rounded-2xl px-4 py-3 shadow-lg ring-1 backdrop-blur-sm', style.card)}
                    >
                        <span className={cn('grid size-9 shrink-0 place-items-center rounded-full', style.chip)}>
                            <Icon className="size-4.5" aria-hidden />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-[0.7rem] font-medium tracking-wide uppercase opacity-70">
                                {t('schedule.alert_label')} ·{' '}
                                {t('schedule.time_range', { start: formatClock(open.starts_at), end: formatClock(open.ends_at) })}
                            </p>
                            <p className="font-semibold">{open.title}</p>
                            {open.subtitle && <p className="text-sm opacity-80">{open.subtitle}</p>}
                        </div>
                        <button
                            type="button"
                            onClick={close}
                            aria-label={t('schedule.close')}
                            className="pointer-events-auto -mt-1 -mr-1 rounded-full p-1.5 opacity-70 transition hover:bg-black/10 hover:opacity-100 dark:hover:bg-white/10"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                </motion.div>
            )}
        </AnimatePresence>,
        document.body,
    );
}
