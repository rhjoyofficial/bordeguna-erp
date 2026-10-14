import { Eye, EyeOff } from 'lucide-react';
import * as React from 'react';

import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type PasswordInputProps = Omit<React.ComponentProps<'input'>, 'type'> & {
    /** Accessible name of the toggle while the password is hidden. */
    showLabel?: string;
    /** Accessible name of the toggle while the password is shown. */
    hideLabel?: string;
};

/**
 * A password box with a show/hide eye inside it, on the right.
 *
 * The visible state lives in this one field and is never stored anywhere, so
 * a reload, a reset or a second field never inherits it. The toggle is a real
 * button that stays in the tab order: it takes the field's own tabIndex, so on
 * a form that numbers its controls the eye still comes right after its box.
 *
 * ⚠ The input keeps a right padding wide enough for the button, so on a phone
 * the typed text never runs under the eye.
 */
const PasswordInput = React.forwardRef<HTMLInputElement, PasswordInputProps>(
    ({ className, disabled, tabIndex, showLabel = 'Show password', hideLabel = 'Hide password', ...props }, ref) => {
        const [visible, setVisible] = React.useState(false);

        return (
            <div className="relative w-full">
                <Input
                    ref={ref}
                    type={visible ? 'text' : 'password'}
                    disabled={disabled}
                    tabIndex={tabIndex}
                    className={cn('pr-11', className)}
                    {...props}
                />
                <button
                    type="button"
                    disabled={disabled}
                    tabIndex={tabIndex}
                    aria-label={visible ? hideLabel : showLabel}
                    aria-pressed={visible}
                    onClick={() => setVisible((current) => !current)}
                    className="text-muted-foreground hover:text-foreground focus-visible:ring-ring absolute top-1 right-1 bottom-1 flex w-9 items-center justify-center rounded-md transition-colors focus-visible:ring-2 focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50"
                >
                    {visible ? <EyeOff className="size-4" aria-hidden="true" /> : <Eye className="size-4" aria-hidden="true" />}
                </button>
            </div>
        );
    },
);

PasswordInput.displayName = 'PasswordInput';

export { PasswordInput };
