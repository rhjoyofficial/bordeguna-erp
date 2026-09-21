import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

/**
 * Cashier shifts (Module 3, Slice 1): custody of the till. Open with a
 * counted float, record cash in/out with reasons, close with a count.
 * The over/short is computed by the server, never typed here.
 */
interface Movement {
    id: number;
    type: 'cash_in' | 'cash_out';
    amount: string;
    reason: string;
    created_at: string;
}

interface OpenShift {
    id: number;
    money_account_id: number;
    till: string;
    opened_at: string;
    opened_by: string;
    is_mine: boolean;
    opening_float: string;
    money_in: number;
    money_out: number;
    movements_net: number;
    expected_cash: number;
    movements: Movement[];
}

interface ClosedShift {
    id: number;
    till: string;
    opened_at: string;
    closed_at: string;
    opening_float: string;
    expected_cash: string;
    closing_count: string;
    over_short: string;
    closed_by: string;
}

interface ShiftsProps {
    open_shifts: OpenShift[];
    tills: { id: number; name: string }[];
    closed_shifts: ClosedShift[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cashier shifts', href: '/sales/shifts' }];

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

function OpenForm({ tills }: { tills: ShiftsProps['tills'] }) {
    const form = useForm({ money_account_id: '', opening_float: '' });

    return (
        <form
            className="border-border bg-card max-w-md space-y-3 rounded-lg border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/sales/shifts/open', { preserveScroll: true });
            }}
        >
            <h2 className="font-semibold">Open a shift</h2>
            <p className="text-muted-foreground text-sm">
                Count the drawer first. The float you type here is what the close will be measured against.
            </p>
            <div className="space-y-1">
                <Label>Till</Label>
                <Select value={form.data.money_account_id} onValueChange={(value) => form.setData('money_account_id', value)}>
                    <SelectTrigger>
                        <SelectValue placeholder="Which cash drawer?" />
                    </SelectTrigger>
                    <SelectContent>
                        {tills.map((till) => (
                            <SelectItem key={till.id} value={String(till.id)}>
                                {till.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {form.errors.money_account_id && <p className="text-destructive text-xs">{form.errors.money_account_id}</p>}
            </div>
            <div className="space-y-1">
                <Label>Opening float (counted)</Label>
                <Input
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="e.g. 2000.00"
                    value={form.data.opening_float}
                    onChange={(event) => form.setData('opening_float', event.target.value)}
                />
                {form.errors.opening_float && <p className="text-destructive text-xs">{form.errors.opening_float}</p>}
            </div>
            <Button type="submit" disabled={form.processing}>
                Open shift
            </Button>
        </form>
    );
}

function MovementForm({ shiftId }: { shiftId: number }) {
    const form = useForm({ type: 'cash_out', amount: '', reason: '' });

    return (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/sales/shifts/' + shiftId + '/movement', {
                    preserveScroll: true,
                    onSuccess: () => form.reset('amount', 'reason'),
                });
            }}
        >
            <div className="space-y-1">
                <Label className="text-xs">Direction</Label>
                <Select value={form.data.type} onValueChange={(value) => form.setData('type', value)}>
                    <SelectTrigger className="w-32">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="cash_out">Cash out</SelectItem>
                        <SelectItem value="cash_in">Cash in</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Amount</Label>
                <Input
                    className="w-28"
                    type="number"
                    step="0.01"
                    min="0.01"
                    placeholder="0.00"
                    value={form.data.amount}
                    onChange={(event) => form.setData('amount', event.target.value)}
                />
            </div>
            <div className="min-w-56 flex-1 space-y-1">
                <Label className="text-xs">Reason (required)</Label>
                <Input
                    placeholder="e.g. change from the market / owner top-up"
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
                {form.errors.reason && <p className="text-destructive text-xs">{form.errors.reason}</p>}
            </div>
            <Button type="submit" variant="outline" disabled={form.processing}>
                Record
            </Button>
            {form.errors.amount && <p className="text-destructive w-full text-xs">{form.errors.amount}</p>}
        </form>
    );
}

function CloseForm({ shiftId, expected }: { shiftId: number; expected: number }) {
    const form = useForm({ closing_count: '', note: '' });
    const counted = Number(form.data.closing_count);
    const diff = form.data.closing_count === '' ? null : Math.round((counted - expected) * 100) / 100;

    return (
        <form
            className="border-border bg-card space-y-3 rounded-lg border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/sales/shifts/' + shiftId + '/close', { preserveScroll: true });
            }}
        >
            <h3 className="font-semibold">Close the shift</h3>
            <p className="text-muted-foreground text-sm">
                Count every note and coin in the drawer, then type the total. The system does the comparing.
            </p>
            <div className="flex flex-wrap items-end gap-3">
                <div className="space-y-1">
                    <Label>Counted cash</Label>
                    <Input
                        className="w-40"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="physical count"
                        value={form.data.closing_count}
                        onChange={(event) => form.setData('closing_count', event.target.value)}
                    />
                    {form.errors.closing_count && <p className="text-destructive text-xs">{form.errors.closing_count}</p>}
                </div>
                <div className="min-w-56 flex-1 space-y-1">
                    <Label>Note (optional)</Label>
                    <Input
                        placeholder="anything worth remembering"
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                    />
                </div>
                <Button type="submit" variant="destructive" disabled={form.processing}>
                    Close shift
                </Button>
            </div>
            {diff !== null && !Number.isNaN(diff) && (
                <p className={'text-sm font-medium ' + (diff === 0 ? 'text-emerald-600' : 'text-destructive')}>
                    {diff === 0
                        ? 'Exactly balanced.'
                        : diff > 0
                          ? money(diff) + ' over the expectation.'
                          : money(-diff) + ' short of the expectation.'}
                </p>
            )}
        </form>
    );
}

/**
 * One open till. ⚠ Money in and out come from the LEDGER lines stamped with
 * this till since it opened (receipts, refunds, payment and contra vouchers),
 * so a cash refund or an expense paid from the drawer lowers "expected" as it
 * lowers the drawer (21 Sep 2026).
 */
function ShiftCard({ shift }: { shift: OpenShift }) {
    return (
        <section className="border-border space-y-4 rounded-xl border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-base font-semibold">
                    {shift.till}
                    {shift.is_mine && <span className="text-primary ml-2 text-xs font-medium">your till</span>}
                </h2>
                <p className="text-muted-foreground text-sm">
                    Opened by {shift.opened_by}, {shift.opened_at}
                </p>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div className="border-border bg-card rounded-lg border px-3 py-2">
                    <p className="text-muted-foreground text-xs">Opening float</p>
                    <p className="font-semibold tabular-nums">{money(shift.opening_float)}</p>
                </div>
                <div className="border-border bg-card rounded-lg border px-3 py-2">
                    <p className="text-muted-foreground text-xs">Money in</p>
                    <p className="font-semibold tabular-nums">{money(shift.money_in)}</p>
                </div>
                <div className="border-border bg-card rounded-lg border px-3 py-2">
                    <p className="text-muted-foreground text-xs">Money out</p>
                    <p className="font-semibold tabular-nums">{money(shift.money_out)}</p>
                </div>
                <div className="border-border bg-card rounded-lg border px-3 py-2">
                    <p className="text-muted-foreground text-xs">Till movements</p>
                    <p className="font-semibold tabular-nums">{money(shift.movements_net)}</p>
                </div>
                <div className="border-primary/40 bg-primary/5 col-span-2 rounded-lg border px-3 py-2 sm:col-span-1">
                    <p className="text-muted-foreground text-xs">Expected in the drawer</p>
                    <p className="text-lg font-semibold tabular-nums">{money(shift.expected_cash)}</p>
                </div>
            </div>
            <p className="text-muted-foreground text-xs">
                Money in and out are everything the books recorded for this till since it opened: sales, receipts, refunds, and payment or contra
                vouchers. To pay an expense or take cash to the bank, use a voucher from this till; a movement below only records custody, such as
                change brought in.
            </p>

            <div className="space-y-3">
                <h3 className="text-sm font-semibold">Till movements</h3>
                <MovementForm shiftId={shift.id} />
                {shift.movements.length > 0 && (
                    <table className="w-full text-sm">
                        <tbody>
                            {shift.movements.map((movement) => (
                                <tr key={movement.id} className="border-t">
                                    <td className="py-1.5 pr-3">{movement.type === 'cash_in' ? 'In' : 'Out'}</td>
                                    <td className="py-1.5 pr-3 text-right tabular-nums">
                                        {movement.type === 'cash_in' ? '' : '-'}
                                        {money(movement.amount)}
                                    </td>
                                    <td className="text-muted-foreground py-1.5">{movement.reason}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            <CloseForm shiftId={shift.id} expected={shift.expected_cash} />
        </section>
    );
}

export default function Shifts({ open_shifts, tills, closed_shifts }: ShiftsProps) {
    // Your own till first: it is the one you are here to count.
    const ordered = [...open_shifts].sort((a, b) => Number(b.is_mine) - Number(a.is_mine));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cashier shifts" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Cashier shifts</h1>
                    <p className="text-muted-foreground text-sm">
                        Each till opens with a counted float and closes with a count. The system works out what should be in the drawer; the
                        difference is booked to Cash Over and Short.
                    </p>
                </div>

                {ordered.map((shift) => (
                    <ShiftCard key={shift.id} shift={shift} />
                ))}

                {tills.length > 0 ? (
                    <OpenForm tills={tills} />
                ) : (
                    open_shifts.length === 0 && (
                        <p className="text-muted-foreground text-sm">
                            No cash box to open. Add one under{' '}
                            <a href="/accounting/money-accounts" className="underline">
                                Accounting &gt; Bank &amp; cash
                            </a>{' '}
                            (kind: Cash).
                        </p>
                    )
                )}

                <div className="space-y-2">
                    <h2 className="font-semibold">Closed shifts</h2>
                    {closed_shifts.length === 0 ? (
                        <p className="text-muted-foreground text-sm">None yet.</p>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-left">
                                        <th className="px-3 py-2 font-medium">Till</th>
                                        <th className="px-3 py-2 font-medium">Opened</th>
                                        <th className="px-3 py-2 font-medium">Closed</th>
                                        <th className="px-3 py-2 text-right font-medium">Float</th>
                                        <th className="px-3 py-2 text-right font-medium">Expected</th>
                                        <th className="px-3 py-2 text-right font-medium">Counted</th>
                                        <th className="px-3 py-2 text-right font-medium">Over / short</th>
                                        <th className="px-3 py-2 font-medium">By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {closed_shifts.map((shift) => (
                                        <tr key={shift.id} className="border-t">
                                            <td className="px-3 py-2">{shift.till}</td>
                                            <td className="px-3 py-2">{shift.opened_at}</td>
                                            <td className="px-3 py-2">{shift.closed_at}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{money(shift.opening_float)}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{money(shift.expected_cash)}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{money(shift.closing_count)}</td>
                                            <td
                                                className={
                                                    'px-3 py-2 text-right font-medium tabular-nums ' +
                                                    (Number(shift.over_short) === 0 ? 'text-emerald-600' : 'text-destructive')
                                                }
                                            >
                                                {money(shift.over_short)}
                                            </td>
                                            <td className="px-3 py-2">{shift.closed_by}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
