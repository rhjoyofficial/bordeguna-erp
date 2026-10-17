import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

const Dialog = DialogPrimitive.Root;

const DialogTrigger = DialogPrimitive.Trigger;

const DialogPortal = DialogPrimitive.Portal;

const DialogClose = DialogPrimitive.Close;

const DialogOverlay = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Overlay>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Overlay
        ref={ref}
        className={cn(
            'fixed inset-0 z-50 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
            className,
        )}
        {...props}
    />
));
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName;

/**
 * ⭐ 17 Oct 2026 (practice run F11/F26): a popup form no longer throws away what
 * was typed when Escape, a click outside or the X closes it. When the form is
 * dirty those three ask "Discard what you typed?" first; a clean form closes as
 * before. A Cancel button inside the form is a deliberate choice and is not asked.
 *
 * Dirty is: the `dirty` prop when the form passes one (Inertia's `isDirty`, so
 * a value typed back to what it was counts as clean), else "the person edited
 * something in this window since it opened": a typed or changed field, or a
 * picked option. That fallback covers every popup in the ERP without each one
 * being wired by hand. `confirmDiscard={false}` turns the question off for a
 * popup whose fields are not work.
 *
 * The rules are plain functions so tests/js/dialog-discard.harness.ts runs them.
 */

/** Anything with Element.closest: the event target, or a stand-in in a test. */
export interface ClosestTarget {
    closest(selector: string): unknown;
}

/** A field whose change is the person's work. */
export const DISCARD_FIELD_SELECTOR =
    'input:not([type="hidden"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="search"]), textarea, select, [contenteditable="true"], [contenteditable=""]';

/** A click that picks something: a picker row, a checkbox, a switch, a radio. */
export const DISCARD_PICK_SELECTOR =
    '[cmdk-item], [role="option"], [role="checkbox"], [role="switch"], [role="radio"], [role="menuitemcheckbox"], [role="menuitemradio"]';

/** Marked by hand, or the type-to-filter box of a picker list (cmdk): typing a search is not work. */
export const DISCARD_IGNORE_SELECTOR = '[data-discard-ignore], [cmdk-input]';

/** Does this event, on this target, mean the person changed the form? */
export function marksEdited(kind: 'change' | 'pick' | 'enter', target: ClosestTarget | null | undefined): boolean {
    if (!target || typeof target.closest !== 'function') return false;

    switch (kind) {
        case 'change':
            return !target.closest(DISCARD_IGNORE_SELECTOR) && Boolean(target.closest(DISCARD_FIELD_SELECTOR));
        case 'pick':
            return !target.closest('[data-discard-ignore]') && Boolean(target.closest(DISCARD_PICK_SELECTOR));
        case 'enter':
            // Enter in a picker list chooses the highlighted row.
            return !target.closest('[data-discard-ignore]') && Boolean(target.closest('[cmdk-root]'));
    }
}

/** Escape, an outside click or the X: close at once, or ask first? */
export function closeNeedsConfirm({ dirty, edited, confirmDiscard = true }: { dirty?: boolean; edited: boolean; confirmDiscard?: boolean }): boolean {
    if (!confirmDiscard) return false;

    return dirty ?? edited;
}

/** Runs once each time the content mounts, which is each time the popup opens. */
function OnOpen({ run }: { run: () => void }) {
    React.useLayoutEffect(() => {
        run();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return null;
}

type DialogContentProps = React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content> & {
    /** The form's own dirty flag (Inertia `isDirty`). Left out: "edited since it opened". */
    dirty?: boolean;
    /** false: never ask before closing (a popup with nothing worth keeping). */
    confirmDiscard?: boolean;
};

const DialogContent = React.forwardRef<React.ElementRef<typeof DialogPrimitive.Content>, DialogContentProps>(
    ({ className, children, dirty, confirmDiscard = true, onEscapeKeyDown, onPointerDownOutside, onChange, onInput, onClick, onKeyDown, ...props }, ref) => {
        const [edited, setEdited] = React.useState(false);
        const [asking, setAsking] = React.useState(false);
        const discardRef = React.useRef<HTMLButtonElement>(null);
        // Where the person was in the form, so "Keep editing" puts them back there.
        const resumeRef = React.useRef<HTMLElement | null>(null);
        const ask = () => {
            resumeRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            setAsking(true);
        };

        const needsConfirm = closeNeedsConfirm({ dirty, edited, confirmDiscard });
        const note = (kind: 'change' | 'pick' | 'enter', target: EventTarget | null) => {
            if (!edited && marksEdited(kind, target as unknown as ClosestTarget)) setEdited(true);
        };

        return (
            <DialogPortal>
                <DialogOverlay />
                <DialogPrimitive.Content
                    ref={ref}
                    className={cn(
                        // ⚠ 17 Oct 2026 (practice run F12): TOP-aligned, not centred. A
                        // centred popup moved up by half of every row it grew, so the next
                        // click (the second "Add component") landed on a different control.
                        // The top stays put; a long form scrolls inside.
                        'fixed top-[4vh] left-[50%] z-50 grid max-h-[92vh] w-full max-w-lg translate-x-[-50%] gap-4 overflow-y-auto border bg-background p-6 shadow-lg duration-200 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 sm:rounded-lg',
                        className,
                    )}
                    onEscapeKeyDown={(event) => {
                        onEscapeKeyDown?.(event);
                        if (event.defaultPrevented || !needsConfirm) return;
                        event.preventDefault();
                        ask();
                    }}
                    onPointerDownOutside={(event) => {
                        onPointerDownOutside?.(event);
                        if (event.defaultPrevented || !needsConfirm) return;
                        event.preventDefault();
                        ask();
                    }}
                    // React events bubble along the React tree, through portals, so a
                    // picker list drawn outside this box still reports here.
                    onChange={(event) => {
                        onChange?.(event);
                        note('change', event.target);
                    }}
                    onInput={(event) => {
                        onInput?.(event);
                        note('change', event.target);
                    }}
                    onClick={(event) => {
                        onClick?.(event);
                        note('pick', event.target);
                    }}
                    onKeyDown={(event) => {
                        onKeyDown?.(event);
                        if (event.key === 'Enter') note('enter', event.target);
                    }}
                    {...props}
                >
                    <OnOpen
                        run={() => {
                            setEdited(false);
                            setAsking(false);
                        }}
                    />
                    {children}
                    <DialogPrimitive.Close
                        onClick={(event) => {
                            if (!needsConfirm) return;
                            event.preventDefault();
                            ask();
                        }}
                        className="absolute right-4 top-4 rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-hidden focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:pointer-events-none data-[state=open]:bg-accent data-[state=open]:text-muted-foreground"
                    >
                        <X className="h-4 w-4" />
                        <span className="sr-only">Close</span>
                    </DialogPrimitive.Close>
                    {/* Discard closes the popup through its own onOpenChange, exactly as the X would. */}
                    <DialogPrimitive.Close ref={discardRef} hidden tabIndex={-1} aria-hidden="true" />
                    <DialogPrimitive.Root open={asking} onOpenChange={setAsking}>
                        <DialogPrimitive.Portal>
                            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/40" />
                            <DialogPrimitive.Content
                                data-discard-ignore
                                aria-describedby={undefined}
                                onCloseAutoFocus={(event) => {
                                    event.preventDefault();
                                    const resume = resumeRef.current;
                                    // ⚠ Deferred: focused at once, the field lost it again as this
                                    // layer finished closing (seen in the browser, 17 Oct 2026).
                                    window.setTimeout(() => {
                                        if (resume?.isConnected) resume.focus();
                                    }, 0);
                                }}
                                className="fixed top-[20vh] left-[50%] z-50 grid w-[calc(100%-2rem)] max-w-sm translate-x-[-50%] gap-3 rounded-lg border bg-background p-5 shadow-lg"
                            >
                                <DialogPrimitive.Title className="text-base font-semibold">Discard what you typed?</DialogPrimitive.Title>
                                <p className="text-sm text-muted-foreground">What you typed in this window is not saved yet.</p>
                                <div className="flex flex-wrap justify-end gap-2 pt-1">
                                    <DialogPrimitive.Close asChild>
                                        <button
                                            type="button"
                                            autoFocus
                                            className="inline-flex h-9 items-center rounded-md border border-primary/25 bg-secondary px-3 text-sm font-medium text-secondary-foreground hover:bg-accent"
                                        >
                                            Keep editing
                                        </button>
                                    </DialogPrimitive.Close>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setAsking(false);
                                            discardRef.current?.click();
                                        }}
                                        className="inline-flex h-9 items-center rounded-md bg-destructive px-3 text-sm font-medium text-destructive-foreground hover:bg-destructive/90"
                                    >
                                        Discard
                                    </button>
                                </div>
                            </DialogPrimitive.Content>
                        </DialogPrimitive.Portal>
                    </DialogPrimitive.Root>
                </DialogPrimitive.Content>
            </DialogPortal>
        );
    },
);
DialogContent.displayName = DialogPrimitive.Content.displayName;

const DialogHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn('flex flex-col space-y-1.5 text-center sm:text-left', className)} {...props} />
);
DialogHeader.displayName = 'DialogHeader';

const DialogFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn('flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2', className)} {...props} />
);
DialogFooter.displayName = 'DialogFooter';

const DialogTitle = React.forwardRef<React.ElementRef<typeof DialogPrimitive.Title>, React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>>(
    ({ className, ...props }, ref) => (
        <DialogPrimitive.Title ref={ref} className={cn('text-lg font-semibold leading-none tracking-tight', className)} {...props} />
    ),
);
DialogTitle.displayName = DialogPrimitive.Title.displayName;

const DialogDescription = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Description>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => <DialogPrimitive.Description ref={ref} className={cn('text-sm text-muted-foreground', className)} {...props} />);
DialogDescription.displayName = DialogPrimitive.Description.displayName;

export { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogOverlay, DialogPortal, DialogTitle, DialogTrigger };
