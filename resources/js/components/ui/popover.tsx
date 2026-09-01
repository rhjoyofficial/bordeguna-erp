import { cn } from '@/lib/utils';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import * as React from 'react';

const Popover = PopoverPrimitive.Root;
const PopoverTrigger = PopoverPrimitive.Trigger;
const PopoverAnchor = PopoverPrimitive.Anchor;

/**
 * ⚠⚠⚠ `pointer-events-auto` IS LOAD-BEARING. DO NOT REMOVE IT.
 *
 * A Radix modal Dialog sets `pointer-events: none` on `document.body` while it
 * is open, and only its own content is exempt. This popover renders through a
 * Portal into `body`, OUTSIDE the dialog - so inside a dialog it inherited
 * `pointer-events: none`, and every click on an option went straight THROUGH
 * the list to whatever field lay beneath it. The list looked normal, the
 * highlight followed the mouse, and nothing could be chosen.
 *
 * That killed every picker inside a dialog: 17 screens, including the loan
 * request form, the payroll adjustment form and the disbursement form. Joy hit
 * it on cutover day - "i can not also select manually" - trying to enter three
 * salary advances. Confirmed in his own browser: body `pointer-events: none`,
 * the popover portalled to BODY, computed `pointer-events: none` on the
 * content, and a real click landing on the input underneath.
 *
 * A descendant may re-enable hit testing that an ancestor switched off, so one
 * class on the content is the whole fix; the wrapper Radix puts around it stays
 * `none` and does not matter. Guarded by PortalledOverlayIsClickableTest.
 */
const PopoverContent = React.forwardRef<
    React.ElementRef<typeof PopoverPrimitive.Content>,
    React.ComponentPropsWithoutRef<typeof PopoverPrimitive.Content>
>(({ className, align = 'center', sideOffset = 4, ...props }, ref) => (
    <PopoverPrimitive.Portal>
        <PopoverPrimitive.Content
            ref={ref}
            align={align}
            sideOffset={sideOffset}
            className={cn(
                'bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 pointer-events-auto z-50 w-72 rounded-md border p-4 shadow-md outline-none',
                className,
            )}
            {...props}
        />
    </PopoverPrimitive.Portal>
));
PopoverContent.displayName = PopoverPrimitive.Content.displayName;

export { Popover, PopoverAnchor, PopoverContent, PopoverTrigger };
