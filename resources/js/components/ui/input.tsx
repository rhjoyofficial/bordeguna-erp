import * as React from 'react';

import { DateInput, DateTimeInput, MonthInput } from '@/components/ui/date-input';
import { cn } from '@/lib/utils';

/**
 * ⚠⚠ A DATE BOX IS AS WIDE AS A DATE (Joy, 30 August 2026).
 *
 * Every input in this system was `w-full`, which is right for a name or an
 * address and wrong for a date: it stretched a control holding "31/08/2026" to
 * whatever the column happened to be, so filter rows across the ERP were mostly
 * empty box. The date family has a FIXED intrinsic content width - the browser
 * knows exactly how wide `dd/mm/yyyy` plus the picker icon is - so `w-auto`
 * gives the right answer everywhere without a per-screen decision.
 *
 * ⚠ Only the date and time family shrink to their content. A number box shrunk
 * that way would jump width as digits are typed.
 *
 * ⚠⚠ A NUMBER BOX IS AS WIDE AS A NUMBER (Joy again, 3 September 2026 - "why do
 * you always make long width input? use them as they need"). An amount, a
 * quantity, a percentage, a day of the month: none of them is ever long, and
 * `w-full` handed each one a whole grid column - 22 of them across 14 screens.
 * So numbers get a FIXED short width rather than a self-sizing one, which keeps
 * the box still while it is typed into.
 *
 * ⚠ `max-w-full` is what makes that safe inside a `Field`: a Field sets the
 * width on the WRAPPER and expects the control to fill it, so a `w-36` number
 * inside a `size="xs"` (w-24) Field would otherwise hang out of its own field.
 * Clamped, it takes the smaller of the two and both callers stay right.
 *
 * ⚠ The width still comes LAST through cn(), so a screen that genuinely needs a
 * different one passes it and wins. This is a better default, not a rule.
 */
const SELF_SIZING = ['date', 'time', 'datetime-local', 'month', 'week'];

const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(({ className, type, ...props }, ref) => {
    // ⚠⚠ 18 Oct 2026 (Joy): dates are DD/MM/YYYY. The browser's own date,
    // date-and-time and month boxes draw the computer's order (mm/dd/yyyy on
    // an American-set PC), so those three go to our own boxes; the value
    // contract (Y-m-d, Y-m-dTH:i, Y-m) is unchanged. See date-input.tsx.
    if (type === 'date') return <DateInput ref={ref} className={className} {...props} />;
    if (type === 'datetime-local') return <DateTimeInput ref={ref} className={className} {...props} />;
    if (type === 'month') return <MonthInput ref={ref} className={className} {...props} />;

    const width = type && SELF_SIZING.includes(type)
        ? 'w-auto min-w-0'
        : type === 'number'
          ? 'w-36 max-w-full'
          : 'w-full';

    return (
        <input
            type={type}
            className={cn(
                'border-input bg-background ring-offset-background file:text-foreground placeholder:text-muted-foreground focus-visible:ring-ring flex h-10 rounded-md border px-3 py-2 text-base file:border-0 file:bg-transparent file:text-sm file:font-medium focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                width,
                className,
            )}
            ref={ref}
            {...props}
        />
    );
});

Input.displayName = 'Input';

export { Input };
