import { isAudioUnlocked, playSample } from '@/lib/audio-unlock';
import { useTranslation } from '@/lib/i18n';
import {
    AUTO_CLOSE_MS,
    byPriority,
    connect,
    inQuietHours,
    isIdleDue,
    isNewLogin,
    markLoginGreeted,
    markShown,
    onPopupsReleased,
    popupsHeld,
    recentlySeen,
    recordActivity,
    xsrfToken,
    type PopupMessage,
} from '@/lib/popup-engine';
import { cn } from '@/lib/utils';
import { type PopupItem, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * The popup host (14 Oct 2026): one popup at a time, from the list the server
 * hands this person (Settings > Popups), highest priority first.
 *
 * Opens on the first ERP page after a sign-in (every popup marked "on
 * sign-in") and when the person comes back after a popup's idle time with no
 * activity in any ERP tab (lib/popup-engine holds the shared bookkeeping).
 * One tab opens it; closing it closes it in every tab. A text popup closes on
 * a click anywhere, any key, its Close button, or by itself after its time; a
 * video popup closes only on its Close button or Escape, and never by itself.
 *
 * A once-a-day or once-per-person popup is CLAIMED on the server right before
 * it opens; the tab that loses the claim stays quiet. A screen that holds
 * popups (the counter with a sale open) keeps the queue until it lets go.
 *
 * Mounted once in the app layout, so guest pages (sign-in, password reset) and
 * the print pages, which do not use that layout, never show it. Each person
 * can switch popups off, and the sound off, in Settings > Appearance.
 *
 * ⚠ Popup lines are plain text rendered as text. Nothing here ever sets HTML.
 */

/** A click or key in the first moment belongs to whatever opened it, not to closing it. */
const CLOSE_GRACE_MS = 450;
/** Pointer moves are frequent; one check per this long is plenty. */
const CHECK_EVERY_MS = 5000;
/** The recordings are mastered loud; this keeps a popup level with the dashboard sounds. */
const POPUP_GAIN = 0.35;

/**
 * ⚠ Every Inertia page renders its own layout, so a page change re-creates this
 * component. The open popup and the queue live here, outside React, so a popup
 * opened by the click that also followed a link carries on over the new page
 * instead of vanishing with the old one.
 */
let current: PopupItem | null = null;
let openUntil = 0;
let openedAt = 0;
const queue: PopupItem[] = [];
let claiming = false;

/** A video or YouTube popup: it has sound of its own, so it never closes by a stray key or click. */
const playable = (item: PopupItem | null): boolean => item?.media?.type === 'video' || item?.media?.type === 'youtube';

const remaining = () => (openUntil > 0 ? Math.max(0, openUntil - Date.now()) : 0);

function enqueue(items: PopupItem[]): void {
    for (const item of items) {
        if (current?.id !== item.id && !queue.some((queued) => queued.id === item.id)) {
            queue.push(item);
        }
    }

    queue.sort(byPriority);
}

async function claim(item: PopupItem): Promise<boolean> {
    if (item.frequency === 'every') {
        return true;
    }

    try {
        const response = await fetch(`/popups/${item.id}/claim`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        });

        if (!response.ok) {
            return false;
        }

        const body = (await response.json()) as { shown?: boolean };

        return body.shown === true;
    } catch {
        return false;
    }
}

export function PopupHost() {
    const { popups } = usePage<SharedData>().props;
    const feed = popups ?? null;
    const enabled = Boolean(feed?.enabled);
    const loginNonce = feed?.login ?? null;
    const items = feed?.items ?? [];

    const { t } = useTranslation();
    const reduceMotion = useReducedMotion();
    const [open, setOpen] = useState<PopupItem | null>(() => current);
    const dialog = useRef<HTMLDivElement>(null);
    const restoreFocus = useRef<HTMLElement | null>(null);
    const link = useRef<ReturnType<typeof connect> | null>(null);
    const feedRef = useRef(feed);
    feedRef.current = feed;
    const enabledRef = useRef(enabled);
    enabledRef.current = enabled;
    const itemsRef = useRef(items);
    itemsRef.current = items;

    const show = useCallback((item: PopupItem, now: number) => {
        markShown(item, now);
        current = item;
        openedAt = now;
        openUntil = item.close_after_seconds ? now + item.close_after_seconds * 1000 : 0;
        restoreFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        setOpen(item);
        link.current?.send({ type: 'open', id: item.id, at: now });

        const settings = feedRef.current;

        // Best effort, never late: a locked browser or quiet hours mean silence.
        if (item.sound && settings?.sound && !inQuietHours(settings.quiet_hours) && isAudioUnlocked()) {
            playSample(item.sound, POPUP_GAIN);
        }
    }, []);

    /** Take the next popup off the queue, claim it if it is a once-rule one, open it. */
    const pump = useCallback(() => {
        if (current !== null || claiming || queue.length === 0 || popupsHeld() || document.visibilityState !== 'visible') {
            return;
        }

        const item = queue.shift()!;
        claiming = true;

        void claim(item).then((won) => {
            claiming = false;

            if (won && current === null) {
                show(item, Date.now());
            } else {
                pump();
            }
        });
    }, [show]);

    const close = useCallback(
        (broadcast = true) => {
            const closing = current;
            current = null;
            openUntil = 0;

            if (closing && broadcast) {
                link.current?.send({ type: 'close', id: closing.id, at: Date.now() });
            }

            setOpen(null);
            // The next one, if any, after the exit animation.
            window.setTimeout(pump, 400);
        },
        [pump],
    );

    // The other tabs: one closing it closes it here; one opening it means this
    // tab need not (the shared shownAt already says so for the idle rule).
    useEffect(() => {
        link.current = connect((message: PopupMessage) => {
            if (message.type === 'close' && current?.id === message.id) {
                close(false);
            }
        });

        return () => {
            link.current?.close();
            link.current = null;
        };
    }, [close]);

    // On this page's arrival: a new sign-in queues every sign-in popup;
    // otherwise any popup whose idle time has passed. Activity is recorded
    // either way, so turning popups on later never fires on stale history.
    useEffect(() => {
        const now = Date.now();
        const visible = document.visibilityState === 'visible';

        if (enabledRef.current && visible) {
            if (isNewLogin(loginNonce)) {
                markLoginGreeted(loginNonce);
                enqueue(itemsRef.current.filter((item) => item.on_login));
            } else {
                enqueue(itemsRef.current.filter((item) => isIdleDue(item, now)));
            }

            pump();
        }

        recordActivity(now, true);
    }, [loginNonce, pump]);

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

            if (enabledRef.current) {
                enqueue(itemsRef.current.filter((item) => isIdleDue(item, now)));
                pump();
            }

            recordActivity(now);
        };

        const options: AddEventListenerOptions = { passive: true, capture: true };
        const events = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'scroll'] as const;
        events.forEach((name) => window.addEventListener(name, onActivity, options));
        document.addEventListener('visibilitychange', onActivity);
        const releaseGate = onPopupsReleased(pump);

        return () => {
            events.forEach((name) => window.removeEventListener(name, onActivity, options));
            document.removeEventListener('visibilitychange', onActivity);
            releaseGate();
        };
    }, [pump]);

    // While open: keys close it (any key for text, Escape for a video), it
    // closes by itself when it has a time, and focus moves in and back out.
    useEffect(() => {
        if (!open) {
            return;
        }

        dialog.current?.focus({ preventScroll: true });

        const onKey = (event: KeyboardEvent) => {
            if (Date.now() - openedAt < CLOSE_GRACE_MS) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                close();

                return;
            }

            if (!playable(open)) {
                close();
            }
        };
        const timer = openUntil > 0 ? window.setTimeout(() => close(), remaining()) : null;
        window.addEventListener('keydown', onKey);

        return () => {
            if (timer !== null) {
                window.clearTimeout(timer);
            }
            window.removeEventListener('keydown', onKey);

            const target = restoreFocus.current;

            if (target && target.isConnected) {
                target.focus({ preventScroll: true });
            }
        };
    }, [open, close]);

    const onBackdrop = () => {
        if (!playable(open) && Date.now() - openedAt >= CLOSE_GRACE_MS) {
            close();
        }
    };

    if (typeof document === 'undefined') {
        return null;
    }

    const ease = [0.16, 1, 0.3, 1] as const;
    const dark = open?.style === 'bismillah' || open?.style === 'calm';
    const total = open?.close_after_seconds ? open.close_after_seconds * 1000 : AUTO_CLOSE_MS;

    return createPortal(
        <AnimatePresence initial={false}>
            {open && (
                <motion.div
                    key={`popup-${open.id}`}
                    className="fixed inset-0 z-[100] grid place-items-center bg-white/35 p-4 backdrop-blur-[3px] dark:bg-black/45"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0, pointerEvents: 'none' }}
                    transition={{ duration: reduceMotion ? 0.15 : 0.35, ease }}
                    onPointerDown={onBackdrop}
                >
                    <motion.div
                        ref={dialog}
                        role="dialog"
                        aria-modal="true"
                        aria-label={open.title}
                        aria-describedby="popup-hint"
                        tabIndex={-1}
                        onPointerDown={(event) => playable(open) && event.stopPropagation()}
                        className={cn(
                            'relative isolate w-full overflow-hidden rounded-3xl px-6 pt-10 pb-11 text-center ring-1 outline-none sm:px-12 sm:pt-12 sm:pb-14',
                            open.media && open.media.type !== 'image' ? 'max-w-[46rem]' : 'max-w-[34rem]',
                            dark && 'bismillah-card text-white ring-white/10',
                            open.style === 'notice' && 'bg-card text-card-foreground ring-border shadow-2xl',
                            open.style === 'warning' &&
                                'bg-amber-50 text-amber-950 shadow-2xl ring-amber-300 dark:bg-amber-950 dark:text-amber-50 dark:ring-amber-700',
                        )}
                        initial={reduceMotion ? { opacity: 0 } : { opacity: 0, scale: 0.94, y: 8 }}
                        animate={reduceMotion ? { opacity: 1 } : { opacity: 1, scale: 1, y: 0 }}
                        exit={reduceMotion ? { opacity: 0 } : { opacity: 0, scale: 0.97, y: -4 }}
                        transition={{ duration: reduceMotion ? 0.15 : 0.55, ease }}
                    >
                        <button
                            type="button"
                            onClick={() => close()}
                            aria-label={t('popup.close')}
                            className={cn(
                                'absolute top-3 right-3 rounded-full p-2 transition-colors',
                                dark
                                    ? 'text-white/60 hover:bg-white/10 hover:text-white'
                                    : 'text-muted-foreground hover:bg-foreground/10 hover:text-foreground',
                            )}
                        >
                            <X className="size-4" />
                        </button>

                        {open.lines.map((line, index) => {
                            if (line.kind === 'arabic') {
                                return (
                                    <p
                                        key={index}
                                        lang="ar"
                                        dir="rtl"
                                        className={cn(
                                            'bismillah-arabic text-[1.875rem] text-balance sm:text-[2.75rem]',
                                            dark ? 'text-white/95' : 'text-foreground',
                                        )}
                                    >
                                        {line.text}
                                    </p>
                                );
                            }

                            if (line.kind === 'heading') {
                                return (
                                    <div key={index}>
                                        {index > 0 && open.lines[index - 1].kind === 'arabic' && (
                                            <div
                                                className={cn(
                                                    'mx-auto mt-5 mb-5 h-px w-12 sm:mt-6 sm:mb-6',
                                                    dark ? 'bg-emerald-200/30' : 'bg-border',
                                                )}
                                                aria-hidden
                                            />
                                        )}
                                        <p
                                            lang="bn"
                                            className={cn(
                                                'bismillah-bangla text-lg sm:text-xl',
                                                dark ? 'text-emerald-100/90' : 'text-foreground',
                                                index > 0 && 'mt-2',
                                            )}
                                        >
                                            {line.text}
                                        </p>
                                    </div>
                                );
                            }

                            if (line.kind === 'body') {
                                return (
                                    <p
                                        key={index}
                                        lang="bn"
                                        className={cn(
                                            'bismillah-bangla mt-3 text-base whitespace-pre-line',
                                            dark ? 'text-white/85' : 'text-foreground',
                                        )}
                                    >
                                        {line.text}
                                    </p>
                                );
                            }

                            return (
                                <p
                                    key={index}
                                    lang="bn"
                                    className={cn('bismillah-bangla mt-2 text-sm sm:text-base', dark ? 'text-white/65' : 'text-muted-foreground')}
                                >
                                    {line.text}
                                </p>
                            );
                        })}

                        {open.media?.type === 'youtube' && (
                            <div className="mt-6 aspect-video w-full overflow-hidden rounded-2xl bg-black">
                                <iframe
                                    className="h-full w-full"
                                    src={`https://www.youtube-nocookie.com/embed/${open.media.id}?autoplay=1&mute=1&rel=0&playsinline=1`}
                                    title={open.title}
                                    allow="autoplay; encrypted-media; picture-in-picture"
                                    referrerPolicy="strict-origin-when-cross-origin"
                                    allowFullScreen
                                />
                            </div>
                        )}

                        {open.media?.type === 'image' && (
                            <img src={open.media.url} alt="" className="mx-auto mt-6 max-h-[60vh] w-auto rounded-2xl object-contain" />
                        )}

                        {open.media?.type === 'video' && (
                            <div className="mt-6 aspect-video w-full overflow-hidden rounded-2xl bg-black">
                                {/* Muted autoplay is what browsers allow; the controls are the person's way in. */}
                                <video className="h-full w-full" src={open.media.url} controls muted autoPlay playsInline preload="metadata" />
                            </div>
                        )}

                        <p id="popup-hint" className="sr-only">
                            {playable(open) ? t('popup.close_hint_media') : t('bismillah.close_hint')}
                        </p>

                        {!reduceMotion && openUntil > 0 && (
                            <motion.div
                                className={cn('absolute inset-x-0 bottom-0 h-0.5 origin-center', dark ? 'bg-emerald-200/45' : 'bg-foreground/30')}
                                initial={{ scaleX: remaining() / total }}
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
