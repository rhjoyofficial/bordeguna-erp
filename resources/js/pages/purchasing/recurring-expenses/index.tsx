import InputError from '@/components/input-error';
import { ReportHeading, StatRow, StatTile } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Prefill {
    spent_on: string;
    money_account_id: number | null;
    supplier_id: number | null;
    reference: string | null;
    expense_account_id: number | null;
    amount: number;
    description: string;
}

interface DueRow {
    id: number;
    name: string | null;
    supplier: string | null;
    account: string | null;
    period_label: string;
    due_on: string;
    expected_amount: number;
    amount_varies: boolean;
    days_away: number;
    prefill: Prefill;
}

interface ScheduleRow {
    id: number;
    name: string;
    supplier: string | null;
    account: string | null;
    paid_from: string | null;
    amount: number;
    amount_varies: boolean;
    frequency: string;
    frequency_label: string;
    day_of_month: number;
    day_label: string;
    status: string;
    status_label: string;
    monthly_run_rate: number;
    annual_commitment: number;
    note: string | null;
}

interface Props {
    canRecord: boolean;
    due: DueRow[];
    schedules: ScheduleRow[];
    totals: { monthly: number; annual: number; count: number };
    frequencies: { value: string; label: string }[];
    expenseAccounts: { id: number; label: string }[];
    moneyAccounts: { id: number; name: string }[];
    suppliers: { id: number; name: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Recurring expenses', href: '/purchasing/recurring-expenses' },
];

const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4';

/**
 * ⚠ Overdue reads differently from due-soon, and both read differently from
 * "next month". A list that renders all three the same way is a list nobody
 * scans.
 */
function whenLabel(days: number) {
    if (days < 0) return { text: `${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'} overdue`, tone: 'destructive' as const };
    if (days === 0) return { text: 'due today', tone: 'default' as const };
    if (days <= 7) return { text: `in ${days} day${days === 1 ? '' : 's'}`, tone: 'default' as const };

    return { text: `in ${days} days`, tone: 'secondary' as const };
}

/**
 * Committing the business to a standing cost.
 *
 * ⛔ Creating one commits NOTHING to the ledger. It is a diary entry with
 * arithmetic: it predicts what will be due and reminds somebody. The money is
 * recorded on the expense screen, by a person, because a voucher here is paid
 * the moment it exists.
 */
function ScheduleDialog({ frequencies, expenseAccounts, moneyAccounts, suppliers }: Omit<Props, 'canRecord' | 'due' | 'schedules' | 'totals'>) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({
        name: '',
        supplier_id: '',
        expense_account_id: '',
        money_account_id: '',
        amount: '',
        amount_varies: false as boolean,
        frequency: 'monthly',
        day_of_month: '1',
        start_date: '',
        end_date: '',
        reference_prefix: '',
        lead_days: '3',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('purchasing.recurring-expenses.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button size="sm">Add a standing cost</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Add a standing cost</DialogTitle>
                    <DialogDescription>
                        A subscription or monthly bill. This only predicts what is due and reminds you; nothing is paid or posted until you record the
                        expense yourself.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-2">
                        <Label htmlFor="name">What is it</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Nikola Host shared hosting"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="supplier_id">Supplier (optional)</Label>
                            <SearchSelect id="supplier_id" value={data.supplier_id} onChange={(e) => setData('supplier_id', e.target.value)}>
                                <option value="">Not a supplier</option>
                                {suppliers.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.supplier_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="expense_account_id">Charge to</Label>
                            <SearchSelect
                                id="expense_account_id"
                                value={data.expense_account_id}
                                onChange={(e) => setData('expense_account_id', e.target.value)}
                            >
                                <option value="">Choose an expense account</option>
                                {expenseAccounts.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.expense_account_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="money_account_id">Usually paid from</Label>
                            <SearchSelect
                                id="money_account_id"
                                value={data.money_account_id}
                                onChange={(e) => setData('money_account_id', e.target.value)}
                            >
                                <option value="">Decide each time</option>
                                {moneyAccounts.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.money_account_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="amount">Amount</Label>
                            <Input
                                id="amount"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                                className="text-right"
                                placeholder="0.00"
                                inputMode="decimal"
                            />
                            <label className="text-muted-foreground flex items-center gap-2 text-xs">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={data.amount_varies}
                                    onChange={(e) => setData('amount_varies', e.target.checked)}
                                />
                                The amount changes each time (anything billed in dollars)
                            </label>
                            <InputError message={errors.amount} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="frequency">How often</Label>
                            <SearchSelect id="frequency" value={data.frequency} onChange={(e) => setData('frequency', e.target.value)}>
                                {frequencies.map((f) => (
                                    <option key={f.value} value={f.value}>
                                        {f.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.frequency} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="day_of_month">Due on day</Label>
                            <Input
                                id="day_of_month"
                                type="number"
                                min={1}
                                max={31}
                                value={data.day_of_month}
                                onChange={(e) => setData('day_of_month', e.target.value)}
                            />
                            {/* ⚠ Said in words because it is the thing people get wrong.
                                Nikola Host is due on the last day, which is the 28th in
                                February and the 31st in March. */}
                            <p className="text-muted-foreground text-xs">Use 31 for “the last day of the month”. Short months are handled.</p>
                            <InputError message={errors.day_of_month} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="start_date">First due from</Label>
                            <Input id="start_date" type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                            <InputError message={errors.start_date} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="end_date">Ends (optional)</Label>
                            <Input id="end_date" type="date" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                            <InputError message={errors.end_date} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="reference_prefix">Reference prefix</Label>
                            <Input
                                id="reference_prefix"
                                value={data.reference_prefix}
                                onChange={(e) => setData('reference_prefix', e.target.value)}
                                placeholder="NIKOLA"
                            />
                            <p className="text-muted-foreground text-xs">Each month becomes NIKOLA-2026-09. Your own reference, not the vendor's.</p>
                            <InputError message={errors.reference_prefix} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="lead_days">Warn this many days early</Label>
                            <Input
                                id="lead_days"
                                type="number"
                                min={0}
                                max={60}
                                value={data.lead_days}
                                onChange={(e) => setData('lead_days', e.target.value)}
                            />
                            <InputError message={errors.lead_days} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="note">Note</Label>
                        <Input
                            id="note"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                            placeholder="Which card, whose account, renewal terms"
                        />
                        <InputError message={errors.note} />
                    </div>
                </form>

                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button disabled={processing} onClick={submit}>
                        {processing ? 'Saving…' : 'Add'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** A month nobody took the service. ⚠ The reason is required and kept. */
function SkipDialog({ row }: { row: DueRow }) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="ghost">
                    Skip
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Skip {row.period_label}</DialogTitle>
                    <DialogDescription>
                        Use this when the service was not taken this period. It stops the reminder without pretending anything was paid.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor={`skip_${row.id}`}>Why</Label>
                    <Input
                        id={`skip_${row.id}`}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="e.g. cancelled before renewal"
                    />
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={reason.trim() === ''}
                        onClick={() =>
                            router.post(
                                route('purchasing.recurring-occurrences.skip', row.id),
                                { reason },
                                { preserveScroll: true, onSuccess: () => setOpen(false) },
                            )
                        }
                    >
                        Mark skipped
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function RecurringExpensesIndex({ canRecord, due, schedules, totals, frequencies, expenseAccounts, moneyAccounts, suppliers }: Props) {
    /**
     * ⚠⚠ AUTO-FILL, NEVER AUTO-POST (Joy: "auto fill is better, if needs them
     * change"). Pressing Record carries the whole prediction to the expense
     * screen, which opens with it filled in and every field still editable. The
     * amount especially: a prediction is not a receipt, and for anything billed
     * in dollars it will not match.
     */
    const record = (row: DueRow) => router.get('/purchasing/expenses', { from_recurring: row.id }, { preserveScroll: false });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recurring expenses" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Recurring expenses"
                    description="Subscriptions and monthly bills that arrive whether or not anybody remembers them. This predicts and reminds; the money is recorded on the expense screen, by you."
                    actions={
                        <>
                            {/* The engine's rendering of this same list: PDF, Excel,
                                CSV and a printable page with the letterhead and the
                                three signature blocks. */}
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/hrm/reports/purchasing_standing_costs">Print or export</Link>
                            </Button>
                            {canRecord && (
                                <ScheduleDialog
                                    frequencies={frequencies}
                                    expenseAccounts={expenseAccounts}
                                    moneyAccounts={moneyAccounts}
                                    suppliers={suppliers}
                                />
                            )}
                        </>
                    }
                />

                <StatRow columns={3}>
                    <StatTile label="Standing costs" value={totals.count} hint="Active schedules" />
                    <StatTile label="Monthly run rate" value={money(totals.monthly)} hint="Every frequency levelled to a month" />
                    <StatTile label="Annual commitment" value={money(totals.annual)} hint="At today's amounts" />
                </StatRow>

                <div className={cardClass}>
                    <h2 className="text-base font-medium">Due</h2>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Overdue first. Nothing here has been paid: recording the expense is what settles it.
                    </p>

                    {due.length === 0 ? (
                        <p className="text-muted-foreground py-6 text-center text-sm">Nothing due in the next 30 days.</p>
                    ) : (
                        <div className="mt-3 overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">What</th>
                                        <th className="p-3">Period</th>
                                        <th className="p-3">Due</th>
                                        <th className="p-3 text-right">Expected</th>
                                        <th className="p-3">Charge to</th>
                                        <th className="p-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {due.map((row) => {
                                        const when = whenLabel(row.days_away);

                                        return (
                                            <tr key={row.id} className="border-t">
                                                <td className="p-3">
                                                    <div className="font-medium">{row.name}</div>
                                                    {row.supplier && <div className="text-muted-foreground text-xs">{row.supplier}</div>}
                                                </td>
                                                <td className="p-3">{row.period_label}</td>
                                                <td className="p-3 whitespace-nowrap">
                                                    <div>{row.due_on}</div>
                                                    <Badge variant={when.tone} className="mt-1">
                                                        {when.text}
                                                    </Badge>
                                                </td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {money(row.expected_amount)}
                                                    {row.amount_varies && <div className="text-muted-foreground text-xs">varies</div>}
                                                </td>
                                                <td className="text-muted-foreground p-3">{row.account}</td>
                                                <td className="p-3 text-right whitespace-nowrap">
                                                    {canRecord && (
                                                        <>
                                                            <Button size="sm" onClick={() => record(row)}>
                                                                Record payment
                                                            </Button>
                                                            <SkipDialog row={row} />
                                                        </>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[52rem] table-fixed text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                {/* ⚠ Capped, and the table is fixed-layout so the cap
                                    holds: the note under a name is the longest text on
                                    the page, and left to auto-layout it starves every
                                    other column (Joy, 1 Sep 2026). */}
                                <th className="w-[40%] p-3">What</th>
                                <th className="p-3">Supplier</th>
                                <th className="p-3">Charge to</th>
                                <th className="p-3">Paid from</th>
                                <th className="p-3">How often</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3 text-right">A month</th>
                                <th className="p-3 text-right">A year</th>
                                <th className="p-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {schedules.map((s) => (
                                <tr key={s.id} className="border-t">
                                    <td className="p-3">
                                        <div className="font-medium">{s.name}</div>
                                        {s.note && <div className="text-muted-foreground text-xs">{s.note}</div>}
                                    </td>
                                    <td className="p-3">{s.supplier ?? '-'}</td>
                                    <td className="text-muted-foreground p-3">{s.account}</td>
                                    <td className="p-3">{s.paid_from ?? 'Decide each time'}</td>
                                    <td className="p-3">
                                        <div>{s.frequency_label}</div>
                                        <div className="text-muted-foreground text-xs">{s.day_label}</div>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {money(s.amount)}
                                        {s.amount_varies && <div className="text-muted-foreground text-xs">varies</div>}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{money(s.monthly_run_rate)}</td>
                                    <td className="p-3 text-right tabular-nums">{money(s.annual_commitment)}</td>
                                    <td className="p-3">
                                        <Badge variant={s.status === 'active' ? 'default' : 'secondary'}>{s.status_label}</Badge>
                                        {canRecord && s.status !== 'ended' && (
                                            <div className="mt-1">
                                                <button
                                                    type="button"
                                                    className="text-muted-foreground hover:text-foreground text-xs underline"
                                                    onClick={() =>
                                                        router.post(
                                                            route('purchasing.recurring-expenses.status', s.id),
                                                            { status: s.status === 'active' ? 'paused' : 'active' },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    {s.status === 'active' ? 'Pause' : 'Resume'}
                                                </button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {schedules.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground p-8 text-center">
                                        <p>No standing costs yet.</p>
                                        {canRecord && (
                                            <p className="mt-1 text-xs">
                                                Add your hosting, domains and subscriptions so they stop being remembered by hand.
                                            </p>
                                        )}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
