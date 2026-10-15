import { Hint } from '@/components/assets/assets-ui';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * The pieces the redesigned Purchases screens share (15 Oct 2026): the money
 * strip over Bills, Payments and Credit notes, the search box, the footer
 * that says how a document is corrected. Tokens only; colour by meaning
 * (red overdue, amber due soon). Every number arrives from the server
 * (PurchasesPulse::moneyStrip), the same figures the overview shows.
 */

export interface MoneyStripData {
    open: { count: number; amount: string; url: string };
    overdue: { count: number; amount: string; oldest_days: number | null; url: string };
    due_week: { count: number; amount: string; url: string };
    paid_month: { count: number; amount: string; label: string; by_account: { name: string; count: number }[]; url: string };
    to_bill: { count: number; numbers: string[]; url: string };
}

function plural(count: number, word: string, words = word + 's'): string {
    return `${count} ${count === 1 ? word : words}`;
}

function Kpi({
    label,
    value,
    hint,
    tone = 'ok',
    href,
}: {
    label: ReactNode;
    value: ReactNode;
    hint: ReactNode;
    tone?: 'ok' | 'warning' | 'danger';
    href: string;
}) {
    return (
        <Link href={href} className="hover:bg-muted/50 bg-card flex min-w-40 flex-1 basis-44 flex-col gap-0.5 rounded-lg border px-3.5 py-3">
            <span className="text-muted-foreground flex items-center gap-1.5 text-xs">{label}</span>
            <span
                className={cn(
                    'text-xl font-semibold tabular-nums',
                    tone === 'danger' && 'text-destructive',
                    tone === 'warning' && 'text-amber-700 dark:text-amber-400',
                )}
            >
                {value}
            </span>
            <span className="text-muted-foreground text-xs">{hint}</span>
        </Link>
    );
}

/** Open, overdue, due in seven days, paid this month, received and not yet billed. */
export function MoneyStrip({ strip }: { strip: MoneyStripData }) {
    return (
        <div className="flex flex-wrap gap-2.5">
            <Kpi
                label={
                    <>
                        Bills open
                        <Hint text="Bills not reversed with something still to pay, less payments, credit notes and adjustments. The same bills the payment form offers." />
                    </>
                }
                value={`Tk ${strip.open.amount}`}
                hint={plural(strip.open.count, 'bill')}
                href={strip.open.url}
            />
            <Kpi
                label="Overdue"
                value={`Tk ${strip.overdue.amount}`}
                tone={strip.overdue.count > 0 ? 'danger' : 'ok'}
                hint={
                    strip.overdue.count === 0
                        ? 'Nothing past its date'
                        : `${plural(strip.overdue.count, 'bill')}${strip.overdue.oldest_days !== null ? `, oldest ${plural(strip.overdue.oldest_days, 'day')}` : ''}`
                }
                href={strip.overdue.url}
            />
            <Kpi
                label="Due in 7 days"
                value={`Tk ${strip.due_week.amount}`}
                tone={strip.due_week.count > 0 ? 'warning' : 'ok'}
                hint={strip.due_week.count === 0 ? 'Nothing falls due this week' : plural(strip.due_week.count, 'bill')}
                href={strip.due_week.url}
            />
            <Kpi
                label={strip.paid_month.label}
                value={`Tk ${strip.paid_month.amount}`}
                hint={
                    strip.paid_month.count === 0
                        ? 'No payment yet this month'
                        : `${plural(strip.paid_month.count, 'payment')}${strip.paid_month.by_account.length > 0 ? ', ' : ''}${strip.paid_month.by_account
                              .map((row) => `${row.name} ${row.count}`)
                              .join(', ')}`
                }
                href={strip.paid_month.url}
            />
            <Kpi
                label="Received, not yet billed"
                value={plural(strip.to_bill.count, 'order')}
                tone={strip.to_bill.count > 0 ? 'warning' : 'ok'}
                hint={strip.to_bill.count === 0 ? 'Every delivery has its bill' : strip.to_bill.numbers.join(', ')}
                href={strip.to_bill.url}
            />
        </div>
    );
}

/** A search box only as wide as a bill number and a name. */
export function SearchField({
    value,
    onChange,
    placeholder,
    label,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    label: string;
}) {
    return (
        <label className="border-input bg-background flex h-8 w-60 max-w-full items-center gap-2 rounded-md border px-2.5">
            <Search className="text-muted-foreground size-3.5 shrink-0" aria-hidden="true" />
            <input
                type="search"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                aria-label={label}
                className="w-full bg-transparent text-sm outline-none"
            />
        </label>
    );
}

/** The foot of a document list: how many rows, and that a correction is a reversal (PUR-003). */
export function ListFooter({ count, word, children }: { count: number; word: string; children?: ReactNode }) {
    return (
        <div className="text-muted-foreground flex flex-wrap justify-between gap-2 border-t px-4 py-2.5 text-xs">
            <span>{plural(count, word)} on this page</span>
            <span>{children}</span>
        </div>
    );
}
