import { Button, type ButtonProps } from '@/components/ui/button';
import { useInertiaBusy } from '@/hooks/use-inertia-busy';
import { Loader2 } from 'lucide-react';

/**
 * A button that refuses a second press while the first is still travelling.
 *
 * ⚠⚠ USE THIS WHEREVER A PRESS MOVES MONEY OR CREATES A DOCUMENT. Approve,
 * disburse, post, pay, record, apply an import. On those, a double press is not
 * an annoyance, it is a duplicate journal or a second payment, and the ERP will
 * happily accept both because each request is individually valid.
 *
 * ⚠ It watches Inertia's own request lifecycle rather than taking a `processing`
 * flag, so a plain `router.post(...)` in an onClick is covered without the call
 * site remembering to thread state through. The cost is that it also disables
 * during an unrelated visit started elsewhere on the page; on a screen that
 * fires one action at a time - which is every screen here - that is the safe
 * direction to be wrong in.
 *
 * ⛔ NOT for navigation, filters or a plain Link. Disabling those while a page
 * loads makes a fast system feel broken.
 */
export function BusyButton({
    children,
    busyLabel,
    disabled,
    ...props
}: ButtonProps & {
    /** Shown while the request is in flight. Defaults to the button's own label. */
    busyLabel?: string;
}) {
    const busy = useInertiaBusy();

    return (
        <Button {...props} disabled={disabled || busy}>
            {busy ? (
                <>
                    <Loader2 className="animate-spin" />
                    {busyLabel ?? children}
                </>
            ) : (
                children
            )}
        </Button>
    );
}
