import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

/** How long a refused visit gets to render its own errors before the net looks. */
const RENDER_GRACE_MS = 150;

/** More than this many unrendered refusals are summarised, not stacked. */
const MAX_REFUSAL_TOASTS = 3;

const squash = (text: string) => text.replace(/\s+/g, ' ').trim();

// Fire a toast whenever the server flashes success/error/warning. Inertia keeps
// the same flash object identity across a partial reload of unrelated props, so
// we track the last messages we showed to avoid re-toasting on every render.
//
// ⚠⚠ AND THE SAFETY NET FOR REFUSALS NO PAGE RENDERS (Sales UX audit #1,
// 21 Sep 2026). A validation error reached a person only if the page rendered
// that exact key, and at least eight everyday refusals were written on keys
// nothing rendered - over-receipt, over-delivery, quotation accept, close short,
// over-refund, a line discount. The server said no in a full sentence and the
// button simply looked broken.
//
// So after every visit that comes back with errors, the net reads the SCREEN:
// any message whose words are not visible anywhere on the page is toasted.
// ⚠ Read from what is shown, not from a list of keys each page declares - a
// registry is one more thing a new page forgets, and a key that IS rendered but
// sits in a closed dialog or a hidden section was never seen either (innerText
// skips what is not displayed). Pages should still render their own keys next
// to the field; this only catches what they miss.
export function useFlashToast() {
    const flash = usePage<SharedData>().props.flash;
    const lastShown = useRef<string | null>(null);
    const flashNow = useRef(flash);
    flashNow.current = flash;

    useEffect(() => {
        const key = [flash?.success, flash?.warning, flash?.error].map((message) => message ?? '').join('\u0000');

        if (!flash?.success && !flash?.warning && !flash?.error) {
            lastShown.current = null;
            return;
        }

        if (key === lastShown.current) {
            return;
        }
        lastShown.current = key;

        if (flash.success) {
            toast.success(flash.success);
        }
        if (flash.warning) {
            toast.warning(flash.warning);
        }
        if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash]);

    useEffect(() => {
        let timer: number | undefined;

        const stop = router.on('error', (event) => {
            const messages = Object.values(event.detail.errors ?? {})
                .map((value) => (Array.isArray(value) ? value[0] : value))
                .filter((value): value is string => typeof value === 'string' && value.trim() !== '');

            if (messages.length === 0) {
                return;
            }

            window.clearTimeout(timer);

            // ⚠ Deferred on purpose. The error event fires as the page is being
            // swapped; the page, and a useForm's own error state, render after
            // it. Looking straight away would find nothing and toast everything.
            timer = window.setTimeout(() => {
                const onScreen = squash(document.body.innerText);
                const flashed = [flashNow.current?.error, flashNow.current?.warning].filter(Boolean).map((text) => squash(String(text)));

                const unseen = [...new Set(messages.map(squash))].filter((message) => !onScreen.includes(message) && !flashed.includes(message));

                unseen.slice(0, MAX_REFUSAL_TOASTS).forEach((message) => toast.error(message));

                if (unseen.length > MAX_REFUSAL_TOASTS) {
                    toast.error(`${unseen.length - MAX_REFUSAL_TOASTS} more problem(s) stopped this from being saved.`);
                }
            }, RENDER_GRACE_MS);
        });

        return () => {
            window.clearTimeout(timer);
            stop();
        };
    }, []);
}
