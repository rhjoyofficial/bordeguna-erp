import { useCompactControls } from '@/components/form/compact-controls';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

export type SelectOption = {
    value: string;
    label: string;
    /** Extra text the search should match on but the row should not show: a SKU, a code, a phone number. */
    keywords?: string;
    disabled?: boolean;
    /** A second, smaller line under the label: why a greyed row cannot be chosen, and where to go instead. */
    hint?: string;
    /** A group title in the list (a chart heading): shown in bold, never chosen. */
    heading?: boolean;
};

/**
 * The one picker used everywhere a native <select> used to be.
 *
 * ⚠⚠ THE SEARCH BOX IS NOT ALWAYS SHOWN. Below `searchThreshold` options it
 * renders as a plain list, because putting a search field over "Active /
 * Inactive" is worse than the select it replaced, not better. The threshold is
 * a prop so a screen can force either behaviour, but the default is what makes
 * a blanket rollout safe: long lists get search, short ones do not.
 *
 * ⚠ `value` is a string, matching the native select it replaces, so it drops
 * straight into Inertia useForm state with no serialisation change. A number id
 * must be `String(id)` at the call site, exactly as it was for <select>.
 *
 * ⚠⚠ CLEARING IS OPT-IN. Re-picking the selected row used to clear the field,
 * which silently emptied required pickers when a user pressed the same row
 * twice. Now only `clearable` (used for filters that mean "all") does that.
 */
export function SearchableSelect({
    value,
    onChange,
    options,
    id,
    name,
    placeholder = 'Select…',
    searchPlaceholder = 'Search…',
    emptyText = 'No results found.',
    className,
    disabled = false,
    clearable = false,
    invalid = false,
    searchThreshold = 8,
    fit,
    onCreate,
    createLabel = 'Add new',
    required = false,
}: {
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    id?: string;
    name?: string;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    className?: string;
    disabled?: boolean;
    clearable?: boolean;
    invalid?: boolean;
    searchThreshold?: number;
    /**
     * ⚠ Size to the option instead of to the column (Joy, 30 August 2026).
     *
     * A filter that reads "All warehouses" does not need half a screen, and a
     * row of them stretched to equal thirds is mostly empty box. It is opt-in
     * rather than the default because a picker INSIDE A FORM should still line
     * up with the fields above and below it - there, full width is right.
     *
     * ⚠⚠ Bounded at both ends on purpose. Pure content width would make the
     * control CHANGE SIZE as the selection changes, so the filter row would
     * reflow under the pointer as somebody used it. The minimum keeps a short
     * option clickable; the maximum keeps a long product name from taking the
     * row back over.
     */
    fit?: boolean;
    /**
     * A pinned row under the list that adds what is missing (a ledger head)
     * without leaving the form. Receives whatever was typed into the search.
     */
    onCreate?: (typed: string) => void;
    createLabel?: string;
    /** Announced to screen readers; the red star itself lives on the field's Label. */
    required?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [inDialog, setInDialog] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const [typed, setTyped] = useState('');
    const selected = options.find((option) => option.value === value);

    // ⚠ Content-width inside a filter bar unless the page says otherwise. See
    // CompactControls for why this is a context and not a prop on every page.
    const compact = useCompactControls();
    const fits = fit ?? compact;
    const showSearch = options.length >= searchThreshold || onCreate !== undefined;

    // cmdk filters on the `value` prop of each item, so a list of two products
    // sharing a name would collapse to one match. The option's own value and
    // its keywords are folded in to keep every row individually reachable.
    const searchValues = useMemo(() => {
        const seen = new Map<string, number>();

        return options.map((option) => {
            const base = [option.label, option.keywords ?? '', option.value].filter(Boolean).join(' ');
            const count = (seen.get(base) ?? 0) + 1;
            seen.set(base, count);

            return count === 1 ? base : `${base} ${count}`;
        });
    }, [options]);

    return (
        <>
            {/* Mirrors the native select's contribution to a plain form post. Inertia
                reads component state, but a screen that still submits normally, or a
                test that fills by name, keeps working. */}
            {name ? <input type="hidden" name={name} value={value} /> : null}

            {/* ⚠⚠ `modal` INSIDE A DIALOG IS LOAD-BEARING (19 Sep 2026, Joy's accountant on
                the expense form). The list is portalled to <body>, OUTSIDE any open Dialog.
                A Dialog traps focus and blocks wheel/touch scrolling for everything outside
                itself, so the search box never received a keystroke and the list would not
                scroll. A modal popover takes its own focus scope and scroll allowance.

                ⚠ ONLY inside a dialog. A modal popover switches pointer events off on the
                page until its exit animation ends, so on a full-page form the click made
                straight after choosing an option was swallowed - which is exactly how a
                fast accountant enters a voucher. */}
            <Popover
                modal={inDialog}
                open={open}
                onOpenChange={
                    disabled
                        ? undefined
                        : (next) => {
                              if (next) {
                                  setInDialog(triggerRef.current?.closest('[role="dialog"]') != null);
                              }
                              setOpen(next);
                          }
                }
            >
                <PopoverTrigger asChild>
                    <Button
                        ref={triggerRef}
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        disabled={disabled}
                        aria-expanded={open}
                        aria-invalid={invalid || undefined}
                        aria-required={required || undefined}
                        className={cn(
                            'bg-background h-10 justify-between font-normal',
                            // ⚠ min-w-0: a long label is truncated, but a button still asks
                            // for its whole text as its minimum, which widened a phone page
                            // to 405px (17 Sep 2026, an outside party with a long name).
                            fits ? 'w-auto max-w-[18rem] min-w-[9rem]' : 'w-full min-w-0',
                            (!selected || value === '') && 'text-muted-foreground',
                            invalid && 'border-destructive',
                            className,
                        )}
                    >
                        <span className="truncate">{selected ? selected.label : placeholder}</span>
                        <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent // ⚠ Tailwind 4 spells a bare custom property `w-(--x)`; the v3 spelling
                    // `w-[--x]` emitted nothing, so every list fell back to 18rem.
                    className="w-(--radix-popover-trigger-width) min-w-[14rem] p-0"
                    align="start"
                >
                    <Command
                        // ⚠ Every typed word must appear in the row (19 Sep 2026). cmdk's
                        // default is a fuzzy score, which answered "rent" with "Employee
                        // Refreshment" - in a chart of 200 heads that reads as broken.
                        filter={(rowValue, search) => {
                            const row = rowValue.toLowerCase();

                            return search
                                .toLowerCase()
                                .split(/\s+/)
                                .filter(Boolean)
                                .every((word) => row.includes(word))
                                ? 1
                                : 0;
                        }}
                    >
                        {showSearch ? <CommandInput placeholder={searchPlaceholder} value={typed} onValueChange={setTyped} /> : null}
                        <CommandList className="max-h-[min(20rem,var(--radix-popover-content-available-height,20rem))]">
                            <CommandEmpty>{emptyText}</CommandEmpty>
                            <CommandGroup>
                                {options.map((option, index) =>
                                    option.heading ? (
                                        <CommandItem
                                            key={option.value}
                                            value={searchValues[index]}
                                            disabled
                                            className="text-foreground pt-3 font-semibold data-[disabled=true]:opacity-100"
                                        >
                                            {option.label}
                                        </CommandItem>
                                    ) : (
                                        <CommandItem
                                            key={option.value}
                                            value={searchValues[index]}
                                            disabled={option.disabled}
                                            // A greyed row with a reason must stay readable: the reason is the point.
                                            className={option.hint ? 'data-[disabled=true]:opacity-70' : undefined}
                                            onSelect={() => {
                                                if (option.value === value && clearable) {
                                                    onChange('');
                                                } else {
                                                    onChange(option.value);
                                                }
                                                setOpen(false);
                                            }}
                                        >
                                            <Check className={cn('mr-2 size-4', value === option.value ? 'opacity-100' : 'opacity-0')} />
                                            {option.hint ? (
                                                <span className="min-w-0">
                                                    <span className="block">{option.label}</span>
                                                    <span className="text-muted-foreground block text-xs">{option.hint}</span>
                                                </span>
                                            ) : (
                                                option.label
                                            )}
                                        </CommandItem>
                                    ),
                                )}
                            </CommandGroup>
                        </CommandList>
                        {onCreate ? (
                            <button
                                type="button"
                                className="hover:bg-accent text-primary flex w-full items-center gap-2 border-t px-3 py-2 text-left text-sm font-medium"
                                onClick={() => {
                                    setOpen(false);
                                    onCreate(typed.trim());
                                }}
                            >
                                <Plus className="size-4 shrink-0" />
                                <span className="shrink-0">{createLabel}</span>
                                {typed.trim() !== '' ? <span className="text-muted-foreground truncate font-normal">"{typed.trim()}"</span> : null}
                            </button>
                        ) : null}
                    </Command>
                </PopoverContent>
            </Popover>
        </>
    );
}
