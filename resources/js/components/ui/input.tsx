import * as React from 'react';

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
 * ⚠ Only the date and time family. A number box shrunk to its content would
 * jump width as digits are typed, and a text box needs the room.
 *
 * ⚠ The width still comes LAST through cn(), so a screen that genuinely needs a
 * different one passes it and wins. This is a better default, not a rule.
 */
const SELF_SIZING = ['date', 'time', 'datetime-local', 'month', 'week'];

const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(({ className, type, ...props }, ref) => {
    const width = type && SELF_SIZING.includes(type) ? 'w-auto min-w-0' : 'w-full';

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
