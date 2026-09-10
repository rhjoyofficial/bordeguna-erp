import { CompactControls } from '@/components/form/compact-controls';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * One labelled control in a form row, as wide as what it holds.
 *
 * ⚠⚠ THIS IS ROOT CAUSE B OF THE 30 AUG 2026 AUDIT. Forms put every field in
 * an equal `md:grid-cols-4` grid, so a date, a three-letter code, a phone
 * number and an address each got exactly a quarter of the screen - "each
 * input field should be as wide as it needs" (Joy, on Discounts). A grid
 * decides width by column; this decides it by content and lets the row wrap.
 *
 * The sizes are the handful that keep coming up. Pick by what the value looks
 * like, not by how important the field is:
 *   xs   a quantity, a year, a two-digit month              w-24
 *   sm   a code, an amount, a short reference               w-36
 *   md   a phone number, a person's name                    w-48
 *   lg   a product picker, an email address                 w-64
 *   grow a name or address that should take what is left   flex-1 min-w-56
 *   auto the control sizes itself (dates, fitted selects)   nothing added
 *
 * ⚠ A date input is already `w-auto` (Input.tsx, 30 Aug) and a select inside
 * a FormRow already fits, so `auto` is the right size for both - a `w-36` on a
 * date box would be a second opinion about a width the browser already knows.
 */
const SIZES = {
    xs: 'w-24',
    sm: 'w-36',
    md: 'w-48',
    lg: 'w-64',
    grow: 'min-w-56 flex-1',
    auto: '',
} as const;

export type FieldSize = keyof typeof SIZES;

export function Field({
    label,
    htmlFor,
    size = 'auto',
    error,
    hint,
    className,
    required = false,
    children,
}: {
    label: ReactNode;
    htmlFor?: string;
    size?: FieldSize;
    error?: string;
    hint?: ReactNode;
    className?: string;
    /**
     * ⚠⚠ Draws the red star (Joy, 10 Sep 2026). It should say what the SERVER
     * will refuse, not what the designer felt was important - a star on an
     * optional box teaches people to ignore stars, and a missing one on a
     * mandatory box is the error message they were going to get anyway.
     */
    required?: boolean;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-1.5', SIZES[size], className)}>
            <Label htmlFor={htmlFor} required={required}>
                {label}
            </Label>
            {children}
            {hint ? <p className="text-muted-foreground text-xs">{hint}</p> : null}
            <InputError message={error} />
        </div>
    );
}

/**
 * A row of Fields that wraps when the screen runs out.
 *
 * ⚠ `items-start`, not `items-end`. Seen live on Discounts (30 Aug 2026):
 * with bottoms aligned, a field that has a hint under it is lifted by the
 * height of that hint, so its input floats above its neighbours. With tops
 * aligned the labels sit on one line and the inputs directly under them
 * line up, and a hint simply hangs lower. The one thing that still breaks
 * the line is a label that WRAPS - keep labels short on narrow fields.
 */
export function FormRow({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <CompactControls>
            <div className={cn('flex flex-wrap items-start gap-4', className)}>{children}</div>
        </CompactControls>
    );
}

/**
 * A FormRow with a small title above it, for a long form that reads better
 * as "Identity / Commercial / Contact" than as one wall of thirteen boxes.
 */
export function FormGroup({ title, children, className }: { title: ReactNode; children: ReactNode; className?: string }) {
    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{title}</p>
            <FormRow>{children}</FormRow>
        </div>
    );
}
