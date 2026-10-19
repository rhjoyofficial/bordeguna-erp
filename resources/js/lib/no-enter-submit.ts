import { useEffect } from 'react';

/**
 * ⛔ Joy, 19 Oct 2026: "no shortcut, remove all short cut".
 *
 * A browser submits a form when Enter is pressed in one of its text boxes.
 * That is a keyboard shortcut like any other, and the same hazard: a half-typed
 * order, payment or voucher saved by a stray key. Inside the ERP a form saves
 * only when its button is clicked.
 *
 * ⚠ Only the browser's DEFAULT action is stopped (preventDefault), never the
 * event itself: a box that gives Enter its own meaning still gets it - the
 * counter's barcode box (scanners end every code with Enter), search boxes,
 * the dropdown pickers choosing the highlighted option, the assistant's chat.
 * Text areas keep Enter for a new line. A box may opt out with data-enter-ok.
 */
function stopEnterSubmit(event: KeyboardEvent): void {
    if (event.key !== 'Enter' || event.defaultPrevented) return;

    const target = event.target as HTMLElement | null;
    if (!(target instanceof HTMLInputElement)) return;
    if (!target.form || target.closest('[data-enter-ok]')) return;
    if (['button', 'submit', 'reset', 'image'].includes(target.type)) return;

    event.preventDefault();
}

/** Installed once by the app layout, so it covers every screen inside the ERP (not the sign-in page). */
export function useNoEnterSubmit(): void {
    useEffect(() => {
        // Bubble phase on window: React's own handlers (on the root) run first,
        // so a box that handles Enter itself has already done so.
        window.addEventListener('keydown', stopEnterSubmit);

        return () => window.removeEventListener('keydown', stopEnterSubmit);
    }, []);
}
