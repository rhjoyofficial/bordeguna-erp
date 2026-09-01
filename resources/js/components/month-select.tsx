import { SearchSelect } from '@/components/search-select';

/**
 * A month, chosen by its name.
 *
 * Joy, 1 September 2026: "the payroll screen needs to select month number, like
 * current is 9, but it is not user friendly, use name instead of number."
 *
 * ⚠ The VALUE stays the number as a string ('1'..'12'), because that is what
 * every payroll route, form and validator already expects. Only the reading
 * changes. A control that also changed the value would have meant touching the
 * server for a wording fix.
 *
 * ⚠ English names for now. This is the one place they are written down, so the
 * Bangla pass over the management screens is a single edit here rather than a
 * hunt through every screen that shows a month.
 */
export const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
] as const;

export const monthName = (month: number | string): string => MONTH_NAMES[Number(month) - 1] ?? String(month);

export function MonthSelect({
    id,
    value,
    onChange,
    className = 'w-36',
    disabled,
}: {
    id?: string;
    /** '1' to '12', matching the number every payroll route already takes. */
    value: string;
    onChange: (value: string) => void;
    className?: string;
    disabled?: boolean;
}) {
    return (
        <SearchSelect id={id} value={value} onChange={(e) => onChange(e.target.value)} className={className} disabled={disabled}>
            {MONTH_NAMES.map((name, index) => (
                <option key={name} value={index + 1}>
                    {name}
                </option>
            ))}
        </SearchSelect>
    );
}
