import { CompactControls } from '@/components/form/compact-controls';
import { Field } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dhakaToday } from '@/lib/dates';
import { FormEventHandler, ReactNode } from 'react';

/**
 * The shell every printed-looking report page shares.
 *
 * Joy asked for the template pages he liked to be restyled rather than rebuilt.
 * Nine of them already existed and each had grown its own header block, its own
 * date form, its own table borders and its own footnote, all slightly different.
 * The differences were never decisions; they were what happens when the same
 * layout is typed out nine times.
 *
 * ⚠ PRESENTATION ONLY. Nothing here formats a number, decides a sign or works
 * out a total. Every figure still arrives from the server already formatted,
 * because a component that could do arithmetic is one that will eventually
 * disagree with the ledger it is drawing.
 */
export function ReportPage({ children }: { children: ReactNode }) {
    return <div className="flex flex-col gap-6 p-4">{children}</div>;
}

export function ReportHeading({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 className="text-lg font-semibold">{title}</h1>
                {description && <p className="text-muted-foreground max-w-2xl text-sm">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2 print:hidden">{actions}</div>}
        </div>
    );
}

/**
 * The date pickers above a statement.
 *
 * ⚠ `print:hidden`. A printed statement carries its period in the heading; the
 * controls that chose it are furniture, and furniture on a page somebody hands
 * to an accountant looks like a mistake.
 */
export function FilterBar({ onSubmit, children, label = 'Show' }: { onSubmit: FormEventHandler; children: ReactNode; label?: string }) {
    return (
        <CompactControls>
            <form onSubmit={onSubmit} className="flex flex-wrap items-end gap-3 print:hidden">
                {children}
                <Button variant="outline">{label}</Button>
            </form>
        </CompactControls>
    );
}

/**
 * The one thing a page exists to say, said before the detail.
 *
 * ⚠⚠ `bad` is not decoration. On a trial balance it means the books do not
 * balance, which is the single fact the page is for. It must not read like a
 * hint, and it must not be possible to scroll past it to the numbers.
 */
export function Verdict({ tone = 'plain', children }: { tone?: 'plain' | 'good' | 'bad'; children: ReactNode }) {
    const styles = {
        plain: 'border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground',
        good: 'border-emerald-600/40 bg-emerald-600/5 text-emerald-700 dark:text-emerald-400',
        bad: 'border-destructive/40 bg-destructive/5 text-destructive font-medium',
    }[tone];

    return <div className={`rounded-md border px-4 py-3 text-sm ${styles}`}>{children}</div>;
}

/**
 * A headline figure. The same tile the finance overview uses, so a number means
 * the same thing and looks the same wherever it appears.
 */
export function StatTile({ label, value, hint, tone }: { label: ReactNode; value: ReactNode; hint?: ReactNode; tone?: 'good' | 'bad' }) {
    const colour = tone === 'good' ? 'text-emerald-600 dark:text-emerald-400' : tone === 'bad' ? 'text-red-600 dark:text-red-400' : '';

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-4">
            <div className="text-muted-foreground text-sm">{label}</div>
            <div className={`text-2xl font-semibold tabular-nums ${colour}`}>{value}</div>
            {hint && <div className="text-muted-foreground text-xs">{hint}</div>}
        </div>
    );
}

export function StatRow({ children, columns = 4 }: { children: ReactNode; columns?: 2 | 3 | 4 }) {
    const grid = { 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-2 lg:grid-cols-4' }[columns];

    return <div className={`grid gap-4 ${grid}`}>{children}</div>;
}

/**
 * The table shell.
 *
 * ⚠ The horizontal scroll lives on the wrapper, never on the page. A statement
 * with six money columns is wider than a phone, and a page that scrolls sideways
 * as a whole loses its own heading while you read a figure.
 */
export function ReportTable({
    head,
    children,
    foot,
    minWidth = '52rem',
}: {
    // ⚠ Optional: a statement laid out in sections (profit and loss, balance
    // sheet) has no column headings, because its rows are not the same kind of
    // thing as each other.
    head?: ReactNode;
    children: ReactNode;
    foot?: ReactNode;
    minWidth?: string;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm" style={{ minWidth }}>
                {head && (
                    <thead>
                        <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">{head}</tr>
                    </thead>
                )}
                <tbody>{children}</tbody>
                {foot && <tfoot className="border-sidebar-border/70 dark:border-sidebar-border border-t">{foot}</tfoot>}
            </table>
        </div>
    );
}

export function EmptyRow({ colSpan, children }: { colSpan: number; children: ReactNode }) {
    return (
        <tr>
            <td colSpan={colSpan} className="text-muted-foreground py-6 text-center">
                {children}
            </td>
        </tr>
    );
}

/**
 * The caveat under a report.
 *
 * ⚠ Joy's own principle, and it belongs on the page rather than in a memo: a
 * balanced trial balance does not prove the accounting is correct. Every
 * statement that could be mistaken for proof says what it does not prove.
 */
export function Footnote({ children }: { children: ReactNode }) {
    return <p className="text-muted-foreground max-w-2xl text-sm">{children}</p>;
}

export interface DateRange {
    from: string;
    to: string;
}

function iso(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** Dhaka's calendar day as a local-midnight Date, so the arithmetic below is calendar arithmetic. */
function dhakaTodayDate(): Date {
    const [y, m, d] = dhakaToday().split('-').map(Number);

    return new Date(y, m - 1, d);
}

/**
 * The three periods somebody asks a statement for without thinking about
 * dates. Worked out on Dhaka's calendar (18 Oct 2026), the same "today" the
 * server defaults to, whatever the viewer's PC clock says.
 *
 * ⚠ The fiscal year is named by the year it STARTS in and starts on the
 * business's `fiscal_year_start_month` (July for Bor de Guna, C-GL-4). A page
 * that does not pass the month gets a calendar year, which is what the
 * server does for a business without one - the two must never disagree.
 */
export function rangePresets(fiscalYearStartMonth: number | null | undefined, today = dhakaTodayDate()): { label: string; range: DateRange }[] {
    const startMonth = fiscalYearStartMonth && fiscalYearStartMonth >= 1 && fiscalYearStartMonth <= 12 ? fiscalYearStartMonth : 1;
    const thisMonthStart = new Date(today.getFullYear(), today.getMonth(), 1);
    const lastMonthStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
    const fiscalStartYear = today.getMonth() + 1 >= startMonth ? today.getFullYear() : today.getFullYear() - 1;
    const fiscalStart = new Date(fiscalStartYear, startMonth - 1, 1);

    return [
        { label: 'This month', range: { from: iso(thisMonthStart), to: iso(today) } },
        { label: 'Last month', range: { from: iso(lastMonthStart), to: iso(lastMonthEnd) } },
        { label: startMonth === 1 ? 'This year' : 'This financial year', range: { from: iso(fiscalStart), to: iso(today) } },
    ];
}

/**
 * The from/to pair above a statement, with the presets beside it.
 *
 * Three statements each had their own copy of this bar (30 Aug 2026 audit:
 * one a bordered box, one a bare form, one the FilterBar) and none offered
 * "last month", so every month-end meant typing two dates. Typing still
 * works; a preset applies at once through `onApply`.
 */
export function DateRangeBar({
    from,
    to,
    onChange,
    onSubmit,
    onApply,
    fiscalYearStartMonth,
    label,
}: {
    from: string;
    to: string;
    onChange: (range: DateRange) => void;
    onSubmit: FormEventHandler;
    /** A preset was chosen: navigate now. Falls back to onChange. */
    onApply?: (range: DateRange) => void;
    fiscalYearStartMonth?: number | null;
    label?: string;
}) {
    const apply = onApply ?? onChange;

    return (
        <FilterBar onSubmit={onSubmit} label={label}>
            <Field label="From" htmlFor="from">
                <Input id="from" type="date" value={from} onChange={(e) => onChange({ from: e.target.value, to })} />
            </Field>
            <Field label="To" htmlFor="to">
                <Input id="to" type="date" value={to} onChange={(e) => onChange({ from, to: e.target.value })} />
            </Field>
            <div className="flex flex-wrap items-center gap-1">
                {rangePresets(fiscalYearStartMonth).map((preset) => (
                    <Button
                        key={preset.label}
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-muted-foreground"
                        onClick={() => apply(preset.range)}
                    >
                        {preset.label}
                    </Button>
                ))}
            </div>
        </FilterBar>
    );
}
