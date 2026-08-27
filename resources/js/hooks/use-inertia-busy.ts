import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Whether an Inertia request is currently in flight.
 *
 * ⚠⚠ THE POINT IS NOT DECORATION, IT IS THE SECOND PRESS. This ERP posts
 * journals, approves payroll and records payments through ordinary buttons. On a
 * slow connection nothing on screen changes between the click and the response,
 * so the natural human reaction is to click again - and the second click is a
 * second document. A spinner that also disables the control is the cheap fix.
 *
 * ⚠ There is a deliberate delay before this reports true. Without it every fast
 * action flickers a disabled state, which reads as the interface stuttering and
 * makes the system feel slower than it is. Below the threshold the response
 * arrives first and nobody sees anything at all.
 */
export function useInertiaBusy(delayMs = 250): boolean {
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let timer: ReturnType<typeof setTimeout> | null = null;

        const clear = () => {
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
        };

        const offStart = router.on('start', () => {
            clear();
            timer = setTimeout(() => setBusy(true), delayMs);
        });

        // `finish` fires for success, validation failure, cancellation and
        // error alike. Anything narrower leaves a button stuck disabled after a
        // refused save, which is worse than the double-click it was preventing.
        const offFinish = router.on('finish', () => {
            clear();
            setBusy(false);
        });

        return () => {
            clear();
            offStart();
            offFinish();
        };
    }, [delayMs]);

    return busy;
}
