import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

/**
 * Cashier shifts (Module 3, Slice 1): custody of the till. Open with a
 * counted float, record cash in/out with reasons, close with a count.
 * The over/short is computed by the server, never typed here.
 *
 * ⭐ Daily screen 2 of 6 in plain words and Bangla (26 Sep 2026): every word a
 * cashier reads goes through the dictionary; digits and money never do.
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

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

function OpenForm({ tills }: { tills: ShiftsProps['tills'] }) {
    const { t } = useTranslation();
    const form = useForm({ money_account_id: '', opening_float: '' });

    return (
        <form
            className="border-border bg-card max-w-md space-y-3 rounded-lg border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/sales/shifts/open', { preserveScroll: true });
            }}
        >
            <h2 className="font-semibold">{t('shift.open_heading')}</h2>
            <div className="space-y-1">
                <Label required>{t('shift.till')}</Label>
                <Select value={form.data.money_account_id} onValueChange={(value) => form.setData('money_account_id', value)}>
                    <SelectTrigger aria-required>
                        <SelectValue placeholder={t('shift.which_till')} />
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
                <Label required>{t('shift.opening_float_counted')}</Label>
                <Input
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="e.g. 2000.00"
                    value={form.data.opening_float}
                    onChange={(event) => form.setData('opening_float', event.target.value)}
                    aria-required
                />
                {form.errors.opening_float && <p className="text-destructive text-xs">{form.errors.opening_float}</p>}
            </div>
            <Button type="submit" disabled={form.processing}>
                {t('shift.open_button')}
            </Button>
        </form>
    );
}

function MovementForm({ shiftId }: { shiftId: number }) {
    const { t } = useTranslation();
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
                <Label className="text-xs" required>
                    {t('shift.direction')}
                </Label>
                <Select value={form.data.type} onValueChange={(value) => form.setData('type', value)}>
                    <SelectTrigger className="w-32" aria-required>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="cash_out">{t('shift.cash_out')}</SelectItem>
                        <SelectItem value="cash_in">{t('shift.cash_in')}</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1">
                <Label className="text-xs" required>
                    {t('shift.amount')}
                </Label>
                <Input
                    className="w-28"
                    type="number"
                    step="0.01"
                    min="0.01"
                    placeholder="0.00"
                    value={form.data.amount}
                    onChange={(event) => form.setData('amount', event.target.value)}
                    aria-required
                />
            </div>
            <div className="max-w-md min-w-56 flex-1 space-y-1">
                <Label className="text-xs" required>
                    {t('shift.reason_required')}
                </Label>
                <Input
                    placeholder={t('shift.reason_placeholder')}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    aria-required
                />
                {form.errors.reason && <p className="text-destructive text-xs">{form.errors.reason}</p>}
            </div>
            <Button type="submit" variant="outline" disabled={form.processing}>
                {t('shift.record')}
            </Button>
            {form.errors.amount && <p className="text-destructive w-full text-xs">{form.errors.amount}</p>}
        </form>
    );
}

function CloseForm({ shiftId, expected }: { shiftId: number; expected: number }) {
    const { t } = useTranslation();
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
            <h3 className="font-semibold">{t('shift.close_heading')}</h3>
            <div className="flex flex-wrap items-end gap-3">
                <div className="space-y-1">
                    <Label required>{t('shift.counted_cash')}</Label>
                    <Input
                        className="w-40"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder={t('shift.counted_placeholder')}
                        value={form.data.closing_count}
                        onChange={(event) => form.setData('closing_count', event.target.value)}
                        aria-required
                    />
                    {form.errors.closing_count && <p className="text-destructive text-xs">{form.errors.closing_count}</p>}
                </div>
                <div className="max-w-md min-w-56 flex-1 space-y-1">
                    <Label>{t('shift.note_optional')}</Label>
                    <Input
                        placeholder={t('shift.note_placeholder')}
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                    />
                </div>
                <Button type="submit" variant="destructive" disabled={form.processing}>
                    {t('shift.close_button')}
                </Button>
            </div>
            {diff !== null && !Number.isNaN(diff) && (
                <p className={'text-sm font-medium ' + (diff === 0 ? 'text-emerald-600' : 'text-destructive')}>
                    {diff === 0
                        ? t('shift.balanced')
                        : diff > 0
                          ? t('shift.over_by', { amount: money(diff) })
                          : t('shift.short_by', { amount: money(-diff) })}
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
    const { t } = useTranslation();

    return (
        <section className="border-border space-y-4 rounded-xl border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-base font-semibold">
                    {shift.till}
                    {shift.is_mine && <span className="text-primary ml-2 text-xs font-medium">{t('shift.your_till')}</span>}
                </h2>
                <p className="text-muted-foreground text-sm">{t('shift.opened_by', { name: shift.opened_by, when: shift.opened_at })}</p>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div className="border-border bg-card rounded-lg border px-3 py-2">
                    <p className="text-muted-foreground text-xs">{t('shift.opening_float')}</p>
                    <p className="font-semibold tabular-nums">{money(shift.opening_float)}</p>
                </div>
                <div className="border-border bg-card rounded-lg border px-3 py-2">
                    <p className="text-muted-foreground text-xs">{t('shift.money_in')}</p>
                    <p className="font-semibold tabular-nums">{money(shift.money_in)}</p>
                </div>
                <div className="border-border bg-card rounded-lg border px-3 py-2">
                    <p className="text-muted-foreground text-xs">{t('shift.money_out')}</p>
                    <p className="font-semibold tabular-nums">{money(shift.money_out)}</p>
                </div>
                <div className="border-border bg-card rounded-lg border px-3 py-2">
                    <p className="text-muted-foreground text-xs">{t('shift.movements')}</p>
                    <p className="font-semibold tabular-nums">{money(shift.movements_net)}</p>
                </div>
                <div className="border-primary/40 bg-primary/5 col-span-2 rounded-lg border px-3 py-2 sm:col-span-1">
                    <p className="text-muted-foreground text-xs">{t('shift.expected')}</p>
                    <p className="text-lg font-semibold tabular-nums">{money(shift.expected_cash)}</p>
                </div>
            </div>

            <div className="space-y-3">
                <h3 className="text-sm font-semibold">{t('shift.movements')}</h3>
                <MovementForm shiftId={shift.id} />
                {shift.movements.length > 0 && (
                    <table className="w-full text-sm">
                        <tbody>
                            {shift.movements.map((movement) => (
                                <tr key={movement.id} className="border-t">
                                    <td className="py-1.5 pr-3">{movement.type === 'cash_in' ? t('shift.in') : t('shift.out')}</td>
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
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('shift.title'), href: '/sales/shifts' }];
    // Your own till first: it is the one you are here to count.
    const ordered = [...open_shifts].sort((a, b) => Number(b.is_mine) - Number(a.is_mine));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('shift.title')} />
            <div className="space-y-6 p-4">
                <h1 className="text-xl font-semibold">{t('shift.title')}</h1>

                {ordered.map((shift) => (
                    <ShiftCard key={shift.id} shift={shift} />
                ))}

                {tills.length > 0 ? (
                    <OpenForm tills={tills} />
                ) : (
                    open_shifts.length === 0 && (
                        <p className="text-muted-foreground text-sm">
                            {t('shift.no_till')}{' '}
                            <a href="/accounting/money-accounts" className="underline">
                                Accounting &gt; Bank &amp; cash
                            </a>
                        </p>
                    )
                )}

                <div className="space-y-2">
                    <h2 className="font-semibold">{t('shift.closed_heading')}</h2>
                    {closed_shifts.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('shift.none_closed')}</p>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-left">
                                        <th className="px-3 py-2 font-medium">{t('shift.till')}</th>
                                        <th className="px-3 py-2 font-medium">{t('shift.col_opened')}</th>
                                        <th className="px-3 py-2 font-medium">{t('shift.col_closed')}</th>
                                        <th className="px-3 py-2 text-right font-medium">{t('shift.col_float')}</th>
                                        <th className="px-3 py-2 text-right font-medium">{t('shift.col_expected')}</th>
                                        <th className="px-3 py-2 text-right font-medium">{t('shift.col_counted')}</th>
                                        <th className="px-3 py-2 text-right font-medium">{t('shift.col_over_short')}</th>
                                        <th className="px-3 py-2 font-medium">{t('shift.col_by')}</th>
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
