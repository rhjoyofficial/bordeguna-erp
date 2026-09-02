import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
    opening_float: string;
    expected_cash: number;
    cash_sales: number;
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
    open_shift: OpenShift | null;
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

export default function Shifts({ open_shift, tills, closed_shifts }: ShiftsProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cashier shifts" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Cashier shifts</h1>
                    <p className="text-muted-foreground text-sm">
                        The accountability wrapper around the till: a counted float in, every movement with a reason, a counted close. The
                        shift records custody only; money still moves through receipts and expenses as always.
                    </p>
                </div>

                {open_shift === null ? (
                    <OpenForm tills={tills} />
                ) : (
                    <div className="space-y-4">
                        <div className="flex flex-wrap gap-4">
                            <div className="border-border bg-card rounded-lg border px-4 py-3">
                                <p className="text-muted-foreground text-xs">Open on {open_shift.till}</p>
                                <p className="text-sm">
                                    by {open_shift.opened_by} since {open_shift.opened_at}
                                </p>
                            </div>
                            <div className="border-border bg-card rounded-lg border px-4 py-3">
                                <p className="text-muted-foreground text-xs">Opening float</p>
                                <p className="text-lg font-semibold tabular-nums">{money(open_shift.opening_float)}</p>
                            </div>
                            <div className="border-border bg-card rounded-lg border px-4 py-3">
                                <p className="text-muted-foreground text-xs">Cash sales this shift</p>
                                <p className="text-lg font-semibold tabular-nums">{money(open_shift.cash_sales)}</p>
                            </div>
                            <div className="border-primary/40 bg-primary/5 rounded-lg border px-4 py-3">
                                <p className="text-muted-foreground text-xs">Expected in the drawer now</p>
                                <p className="text-lg font-semibold tabular-nums">{money(open_shift.expected_cash)}</p>
                            </div>
                        </div>

                        <div className="border-border bg-card space-y-3 rounded-lg border p-4">
                            <h3 className="font-semibold">Till movements</h3>
                            <MovementForm shiftId={open_shift.id} />
                            {open_shift.movements.length > 0 && (
                                <table className="w-full text-sm">
                                    <tbody>
                                        {open_shift.movements.map((movement) => (
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

                        <CloseForm shiftId={open_shift.id} expected={open_shift.expected_cash} />
                    </div>
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
