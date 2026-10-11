import {
    AUTO_CLOSE_MS,
    connect,
    isNewLogin,
    isReturn,
    markLoginGreeted,
    markShown,
    recentlySeen,
    recordActivity,
    type GreetingMessage,
} from '@/lib/bismillah';
import { useTranslation } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * The Bismillah greeting (owner request, 11 Oct 2026).
 *
 * Opens on the first ERP page after a sign-in, and when the person comes back
 * after an hour with no activity in any ERP tab (lib/bismillah holds the
 * shared bookkeeping). One tab opens it; closing it closes it in every tab.
 * Closes on a click anywhere, any key, or by itself after six seconds.
 *
 * Mounted once in the app layout, so guest pages (sign-in, password reset) and
 * the print pages, which do not use that layout, never show it. Each person can
 * switch it off in Settings > Appearance.
 *
 * ⚠ The three lines are fixed text, not translated with the interface: the
 * Arabic, its Bangla pronunciation and its Bangla meaning, each with its own
 * `lang` so screen readers and fonts treat them correctly.
 */

const ARABIC = 'بِسْمِ ٱللَّٰهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ';
const PRONUNCIATION = 'বিসমিল্লাহির রাহমানির রাহিম';
const MEANING = 'পরম করুণাময়, অতি দয়ালু আল্লাহর নামে';

/** A click or key in the first moment belongs to whatever opened it, not to closing it. */
const CLOSE_GRACE_MS = 450;
/** Pointer moves are frequent; one check per this long is plenty. */
const CHECK_EVERY_MS = 5000;

/**
 * ⚠ Every Inertia page renders its own layout, so a page change re-creates this
 * component. The open window lives here, outside React, so a greeting opened
 * by the click that also followed a link carries on over the new page instead
 * of vanishing with the old one.
 */
let openUntil = 0;

const remaining = () => Math.max(0, openUntil - Date.now());

export function BismillahGreeting() {
    const { bismillah } = usePage<SharedData>().props;
    const enabled = Boolean(bismillah?.enabled);
    const loginNonce = bismillah?.login ?? null;

    const { t } = useTranslation();
    const reduceMotion = useReducedMotion();
    const [open, setOpen] = useState(() => remaining() > 0);
    const openRef = useRef(open);
    openRef.current = open;
    const openedAt = useRef(openUntil - AUTO_CLOSE_MS);
    const dialog = useRef<HTMLDivElement>(null);
    const restoreFocus = useRef<HTMLElement | null>(null);
    const link = useRef<ReturnType<typeof connect> | null>(null);
    const enabledRef = useRef(enabled);
    enabledRef.current = enabled;

    const show = useCallback((now: number) => {
        markShown(now);
        openUntil = now + AUTO_CLOSE_MS;
        openedAt.current = now;
        restoreFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        setOpen(true);
        link.current?.send({ type: 'open', at: now });
    }, []);

    const close = useCallback((broadcast = true) => {
        openUntil = 0;

        if (openRef.current && broadcast) {
            link.current?.send({ type: 'close', at: Date.now() });
        }

        setOpen(false);
    }, []);

    // The other tabs: one closing it closes it here.
    useEffect(() => {
        link.current = connect((message: GreetingMessage) => {
            if (message.type === 'close') {
                close(false);
            }
        });

        return () => {
            link.current?.close();
            link.current = null;
        };
    }, [close]);

    // On this page's arrival: a new sign-in greets; otherwise a return after an
    // hour away does. Activity is recorded either way, so turning the greeting
    // on later never fires on stale history.
    useEffect(() => {
        const now = Date.now();
        const visible = document.visibilityState === 'visible';

        if (enabledRef.current && visible && isNewLogin(loginNonce)) {
            markLoginGreeted(loginNonce);
            show(now);
        } else if (enabledRef.current && visible && isReturn(now)) {
            show(now);
        }

        recordActivity(now, true);
    }, [loginNonce, show]);

    // Activity anywhere on the page, and the tab coming back into view.
    useEffect(() => {
        const onActivity = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            const now = Date.now();

            if (recentlySeen(now, CHECK_EVERY_MS)) {
                recordActivity(now);

                return;
            }

            if (enabledRef.current && isReturn(now)) {
                show(now);
            }

            recordActivity(now);
        };

        const options: AddEventListenerOptions = { passive: true, capture: true };
        const events = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'scroll'] as const;
        events.forEach((name) => window.addEventListener(name, onActivity, options));
        document.addEventListener('visibilitychange', onActivity);

        return () => {
            events.forEach((name) => window.removeEventListener(name, onActivity, options));
            document.removeEventListener('visibilitychange', onActivity);
        };
    }, [show]);

    // While open: any key closes it, it closes by itself, and focus moves in
    // and back out again.
    useEffect(() => {
        if (!open) {
            return;
        }

        dialog.current?.focus({ preventScroll: true });

        const onKey = (event: KeyboardEvent) => {
            if (Date.now() - openedAt.current < CLOSE_GRACE_MS) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
            }

            close();
        };
        const timer = window.setTimeout(() => close(), remaining());
        window.addEventListener('keydown', onKey);

        return () => {
            window.clearTimeout(timer);
            window.removeEventListener('keydown', onKey);

            const target = restoreFocus.current;

            if (target && target.isConnected) {
                target.focus({ preventScroll: true });
            }
        };
    }, [open, close]);

    const onPointer = () => {
        if (Date.now() - openedAt.current >= CLOSE_GRACE_MS) {
            close();
        }
    };

    if (typeof document === 'undefined') {
        return null;
    }

    const ease = [0.16, 1, 0.3, 1] as const;

    return createPortal(
        <AnimatePresence initial={false}>
            {open && (
                <motion.div
                    key="bismillah"
                    className="fixed inset-0 z-[100] grid place-items-center bg-white/35 p-4 backdrop-blur-[3px] dark:bg-black/45"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0, pointerEvents: 'none' }}
                    transition={{ duration: reduceMotion ? 0.15 : 0.35, ease }}
                    onPointerDown={onPointer}
                >
                    <motion.div
                        ref={dialog}
                        role="dialog"
                        aria-modal="true"
                        aria-label={t('bismillah.dialog_label')}
                        aria-describedby="bismillah-hint"
                        tabIndex={-1}
                        className="bismillah-card relative isolate w-full max-w-[34rem] overflow-hidden rounded-3xl px-6 pt-10 pb-11 text-center text-white ring-1 ring-white/10 outline-none sm:px-12 sm:pt-12 sm:pb-14"
                        initial={reduceMotion ? { opacity: 0 } : { opacity: 0, scale: 0.94, y: 8 }}
                        animate={reduceMotion ? { opacity: 1 } : { opacity: 1, scale: 1, y: 0 }}
                        exit={reduceMotion ? { opacity: 0 } : { opacity: 0, scale: 0.97, y: -4 }}
                        transition={{ duration: reduceMotion ? 0.15 : 0.55, ease }}
                    >
                        <p lang="ar" dir="rtl" className="bismillah-arabic text-[1.875rem] text-balance text-white/95 sm:text-[2.75rem]">
                            {ARABIC}
                        </p>

                        <div className="mx-auto mt-5 h-px w-12 bg-emerald-200/30 sm:mt-6" aria-hidden />

                        <p lang="bn" className="bismillah-bangla mt-5 text-lg text-emerald-100/90 sm:mt-6 sm:text-xl">
                            {PRONUNCIATION}
                        </p>
                        <p lang="bn" className="bismillah-bangla mt-2 text-sm text-white/65 sm:text-base">
                            {MEANING}
                        </p>

                        <p id="bismillah-hint" className="sr-only">
                            {t('bismillah.close_hint')}
                        </p>

                        {!reduceMotion && (
                            <motion.div
                                className="absolute inset-x-0 bottom-0 h-0.5 origin-center bg-emerald-200/45"
                                initial={{ scaleX: remaining() / AUTO_CLOSE_MS }}
                                animate={{ scaleX: 0 }}
                                transition={{ duration: remaining() / 1000, ease: 'linear' }}
                                aria-hidden
                            />
                        )}
                    </motion.div>
                </motion.div>
            )}
        </AnimatePresence>,
        document.body,
    );
}
