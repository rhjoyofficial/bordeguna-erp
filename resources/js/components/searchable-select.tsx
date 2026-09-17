import { useCompactControls } from '@/components/form/compact-controls';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Check, ChevronsUpDown } from 'lucide-react';
import { useMemo, useState } from 'react';

export type SelectOption = {
    value: string;
    label: string;
    /** Extra text the search should match on but the row should not show: a SKU, a code, a phone number. */
    keywords?: string;
    disabled?: boolean;
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
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.value === value);

    // ⚠ Content-width inside a filter bar unless the page says otherwise. See
    // CompactControls for why this is a context and not a prop on every page.
    const compact = useCompactControls();
    const fits = fit ?? compact;
    const showSearch = options.length >= searchThreshold;

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

            <Popover open={open} onOpenChange={disabled ? undefined : setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        disabled={disabled}
                        aria-expanded={open}
                        aria-invalid={invalid || undefined}
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
                <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
                    <Command>
                        {showSearch ? <CommandInput placeholder={searchPlaceholder} /> : null}
                        <CommandList>
                            <CommandEmpty>{emptyText}</CommandEmpty>
                            <CommandGroup>
                                {options.map((option, index) => (
                                    <CommandItem
                                        key={option.value}
                                        value={searchValues[index]}
                                        disabled={option.disabled}
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
                                        {option.label}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
        </>
    );
}
