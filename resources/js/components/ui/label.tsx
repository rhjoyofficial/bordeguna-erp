import * as LabelPrimitive from '@radix-ui/react-label';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const labelVariants = cva('text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70');

/**
 * ⚠⚠ THE RED STAR LIVES HERE, IN ONE PLACE (Joy, 10 September 2026: "add all
 * required filed with the * symbol with red color, full entire ERP"). Putting it
 * on the Label rather than in each of a hundred and thirty screens is what makes
 * "entire ERP" a change of one component plus a marking pass, instead of a
 * convention somebody forgets on the next form they build.
 *
 * ⚠ `aria-hidden` on the star: a screen reader announcing "star" after every
 * label is noise. The input itself carries the real `required` attribute, which
 * is what assistive technology reads, so the star is decoration for eyes only.
 */
const Label = React.forwardRef<
    React.ElementRef<typeof LabelPrimitive.Root>,
    React.ComponentPropsWithoutRef<typeof LabelPrimitive.Root> &
        VariantProps<typeof labelVariants> & { required?: boolean }
>(({ className, required = false, children, ...props }, ref) => (
    <LabelPrimitive.Root ref={ref} className={cn(labelVariants(), className)} {...props}>
        {children}
        {required && (
            <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                *
            </span>
        )}
    </LabelPrimitive.Root>
));
Label.displayName = LabelPrimitive.Root.displayName;

export { Label };
