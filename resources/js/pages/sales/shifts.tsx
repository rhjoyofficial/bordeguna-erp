import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Cashier shifts (Module 3, Slice 1): custody of the till. Open with a
 * counted float, record cash in/out with reasons, close with a count.
 * The over/short is computed by the server, never typed here.
 *
 * ⭐ Daily screen 2 of 6 in plain words and Bangla (26 Sep 2026): every word a
 * cashier reads goes through the dictionary; digits and money never do.
 *
 * ⭐ B12 (25 Oct 2026): a cash move POSTS (a money transfer, or an expense
 * voucher on a head) and is recorded in a popup; only the shift's own cashier,
 * an Owner or a Manager sees the move and close controls; every shift links
 * to its Z summary. No <form>: Enter never submits (Joy, 19 Oct 2026).
 */
interface Movement {
    id: number;
    type: 'cash_in' | 'cash_out';
    kind: 'transfer' | 'expense' | null;
    amount: string;
    reason: string;
    created_at: string;
    document: string | null;
}

interface OpenShift {
    id: number;
    money_account_id: number;
    till: string;
    opened_at: string;
    opened_by: string;
    is_mine: boolean;
    may_handle: boolean;
    opening_float: string;
    float_from_id: number | null;
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
    other_accounts: { id: number; name: string; type: string }[];
    expense_accounts: { id: number; label: string }[];
}

type MoveChoices = Pick<ShiftsProps, 'other_accounts' | 'expense_accounts'>;

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

function OpenForm({ tills, accounts }: { tills: ShiftsProps['tills']; accounts: ShiftsProps['other_accounts'] }) {
    const { t } = useTranslation();
    const form = useForm({ money_account_id: '', opening_float: '', float_from: '' });
    const sources = accounts.filter((account) => String(account.id) !== form.data.money_account_id);
    const select = 'border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm';

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
            {/* Joy's answer 5 (25 Oct 2026): the float is posted into the till from this account. */}
            {Number(form.data.opening_float) > 0 && (
                <div className="space-y-1">
                    <Label htmlFor="float-from" required>
                        {t('ipa.float.from')}
                    </Label>
                    <select
                        id="float-from"
                        className={select}
                        value={form.data.float_from}
                        onChange={(event) => form.setData('float_from', event.target.value)}
                    >
                        <option value="">{t('ipa.float.choose')}</option>
                        {sources.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.name}
                            </option>
                        ))}
                        <option value="in_till">{t('ipa.float.in_till')}</option>
                    </select>
                    <p className="text-muted-foreground text-xs">{t('ipa.float.from_hint')}</p>
                    {form.errors.float_from && <p className="text-destructive text-xs">{form.errors.float_from}</p>}
                </div>
            )}
            <Button type="submit" disabled={form.processing}>
                {t('shift.open_button')}
            </Button>
        </form>
    );
}

const EMPTY_MOVE = { type: 'cash_out', kind: 'transfer', other_money_account_id: '', expense_account_id: '', amount: '', reason: '' };

/**
 * ⭐ B12: a cash move in a popup. It posts a money transfer (with the account
 * the cash came from or went to) or, for cash spent, an expense voucher on the
 * chosen head; the till and the books then agree. Dated today.
 */
function MoveDialog({
    shift,
    choices,
    open,
    onOpenChange,
}: {
    shift: OpenShift;
    choices: MoveChoices;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const form = useForm(EMPTY_MOVE);
    const today = new Date().toISOString().slice(0, 10);
    const others = choices.other_accounts.filter((account) => account.id !== shift.money_account_id);

    const close = () => {
        form.clearErrors();
        form.reset();
        onOpenChange(false);
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-xl" aria-describedby={undefined}>
                <div className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{t('till.move_title', { till: shift.till })}</DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">{t('till.date_today', { date: showDate(today) })}</p>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="space-y-1">
                            <Label className="text-xs" required>
                                {t('shift.direction')}
                            </Label>
                            <Select
                                value={form.data.type}
                                onValueChange={(value) =>
                                    form.setData({ ...form.data, type: value, kind: value === 'cash_in' ? 'transfer' : form.data.kind })
                                }
                            >
                                <SelectTrigger className="w-fit" aria-required>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="cash_out">{t('shift.cash_out')}</SelectItem>
                                    <SelectItem value="cash_in">{t('shift.cash_in')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        {form.data.type === 'cash_out' && (
                            <div className="space-y-1">
                                <Label className="text-xs" required>
                                    {t('till.kind')}
                                </Label>
                                <Select value={form.data.kind} onValueChange={(value) => form.setData('kind', value)}>
                                    <SelectTrigger className="w-fit" aria-required>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="transfer">{t('till.kind_transfer')}</SelectItem>
                                        <SelectItem value="expense">{t('till.kind_expense')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.kind && <p className="text-destructive text-xs">{errors.kind}</p>}
                            </div>
                        )}
                    </div>
                    {form.data.kind === 'transfer' ? (
                        <div className="space-y-1">
                            <Label className="text-xs" required>
                                {form.data.type === 'cash_in' ? t('till.from_account') : t('till.to_account')}
                            </Label>
                            <SearchableSelect
                                id="move-other-account"
                                required
                                value={form.data.other_money_account_id}
                                onChange={(value) => form.setData('other_money_account_id', value)}
                                options={others.map((account) => ({ value: String(account.id), label: account.name }))}
                                placeholder={t('till.choose_account')}
                            />
                            {errors.other_money_account_id && <p className="text-destructive text-xs">{errors.other_money_account_id}</p>}
                        </div>
                    ) : (
                        <div className="space-y-1">
                            <Label className="text-xs" required>
                                {t('till.expense_head')}
                            </Label>
                            <SearchableSelect
                                id="move-expense-head"
                                required
                                value={form.data.expense_account_id}
                                onChange={(value) => form.setData('expense_account_id', value)}
                                options={choices.expense_accounts.map((account) => ({ value: String(account.id), label: account.label }))}
                                placeholder={t('till.choose_head')}
                            />
                            {errors.expense_account_id && <p className="text-destructive text-xs">{errors.expense_account_id}</p>}
                        </div>
                    )}
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="space-y-1">
                            <Label className="text-xs" required>
                                {t('shift.amount')}
                            </Label>
                            <Input
                                className="w-32"
                                inputMode="decimal"
                                placeholder="0.00"
                                value={form.data.amount}
                                onChange={(event) => form.setData('amount', event.target.value)}
                                aria-required
                            />
                            {errors.amount && <p className="text-destructive text-xs">{errors.amount}</p>}
                        </div>
                        <div className="min-w-56 flex-1 space-y-1">
                            <Label className="text-xs" required>
                                {t('shift.reason_required')}
                            </Label>
                            <Input
                                placeholder={t('shift.reason_placeholder')}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                                aria-required
                            />
                            {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                        </div>
                    </div>
                    <p className="text-muted-foreground text-xs">{t('till.posts_note')}</p>
                    {(errors.movement || errors.shift) && <p className="text-destructive text-sm">{errors.movement ?? errors.shift}</p>}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            {t('till.cancel')}
                        </Button>
                        <Button
                            type="button"
                            disabled={form.processing}
                            onClick={() => form.post('/sales/shifts/' + shift.id + '/movement', { preserveScroll: true, onSuccess: close })}
                        >
                            {t('shift.record')}
                        </Button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function CloseForm({ shift, accounts }: { shift: OpenShift; accounts: ShiftsProps['other_accounts'] }) {
    const { t } = useTranslation();
    const shiftId = shift.id;
    const expected = shift.expected_cash;
    // Joy's answer 5 (25 Oct 2026): the hand-over defaults to where the float came from.
    const form = useForm({ closing_count: '', note: '', hand_over_to: shift.float_from_id ? String(shift.float_from_id) : 'in_till' });
    const targets = accounts.filter((account) => account.id !== shift.money_account_id);
    const counted = Number(form.data.closing_count);
    const diff = form.data.closing_count === '' ? null : Math.round((counted - expected) * 100) / 100;

    return (
        <div className="border-border bg-card space-y-3 rounded-lg border p-4">
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
                <div className="space-y-1">
                    <Label htmlFor={'hand-over-' + shiftId}>{t('ipa.float.hand_over_to')}</Label>
                    <select
                        id={'hand-over-' + shiftId}
                        className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                        value={form.data.hand_over_to}
                        onChange={(event) => form.setData('hand_over_to', event.target.value)}
                    >
                        <option value="in_till">{t('ipa.float.keep_in_till')}</option>
                        {targets.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.name}
                            </option>
                        ))}
                    </select>
                    {form.errors.hand_over_to && <p className="text-destructive text-xs">{form.errors.hand_over_to}</p>}
                </div>
                <Button
                    type="button"
                    variant="destructive"
                    disabled={form.processing}
                    onClick={() => form.post('/sales/shifts/' + shiftId + '/close', { preserveScroll: true })}
                >
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
        </div>
    );
}

/**
 * One open till. ⚠ Money in and out come from the LEDGER lines stamped with
 * this till since it opened (receipts, refunds, payment and contra vouchers),
 * so a cash refund or an expense paid from the drawer lowers "expected" as it
 * lowers the drawer (21 Sep 2026).
 */
function ShiftCard({ shift, choices }: { shift: OpenShift; choices: MoveChoices }) {
    const { t } = useTranslation();
    const [moving, setMoving] = useState(false);

    return (
        <section className="border-border space-y-4 rounded-xl border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-base font-semibold">
                    {shift.till}
                    {shift.is_mine && <span className="text-primary ml-2 text-xs font-medium">{t('shift.your_till')}</span>}
                </h2>
                <p className="text-muted-foreground text-sm">
                    {t('shift.opened_by', { name: shift.opened_by, when: showDate(shift.opened_at) })}
                    {' · '}
                    <Link href={'/sales/shifts/' + shift.id + '/summary'} className="text-primary underline">
                        {t('till.z_summary')}
                    </Link>
                </p>
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
                <div className="flex flex-wrap items-center gap-3">
                    <h3 className="text-sm font-semibold">{t('shift.movements')}</h3>
                    {shift.may_handle && (
                        <Button type="button" variant="outline" size="sm" onClick={() => setMoving(true)}>
                            {t('till.record_move')}
                        </Button>
                    )}
                </div>
                {shift.may_handle && <MoveDialog shift={shift} choices={choices} open={moving} onOpenChange={setMoving} />}
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
                                    <td className="text-muted-foreground py-1.5 pr-3">{movement.reason}</td>
                                    <td className="text-muted-foreground py-1.5 text-xs">
                                        {movement.document ? t('till.posted_as', { number: movement.document }) : t('till.note_only')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {shift.may_handle ? (
                <CloseForm shift={shift} accounts={choices.other_accounts} />
            ) : (
                <p className="text-muted-foreground text-sm">{t('till.not_yours', { name: shift.opened_by })}</p>
            )}
        </section>
    );
}

export default function Shifts({ open_shifts, tills, closed_shifts, other_accounts = [], expense_accounts = [] }: ShiftsProps) {
    const { t } = useTranslation();
    // A refusal that names no field of an open form (another person's shift).
    const shiftError = (usePage().props.errors as Record<string, string | undefined>).shift;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.sales'), href: '/sales/overview' },
        { title: t('shift.title'), href: '/sales/shifts' },
    ];
    // Your own till first: it is the one you are here to count.
    const ordered = [...open_shifts].sort((a, b) => Number(b.is_mine) - Number(a.is_mine));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('shift.title')} />
            <div className="space-y-4 p-4">
                {shiftError && <p className="text-destructive text-sm">{shiftError}</p>}
                {ordered.map((shift) => (
                    <ShiftCard key={shift.id} shift={shift} choices={{ other_accounts, expense_accounts }} />
                ))}

                {tills.length > 0 ? (
                    <OpenForm tills={tills} accounts={other_accounts} />
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
                                        <th className="px-3 py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {closed_shifts.map((shift) => (
                                        <tr key={shift.id} className="border-t">
                                            <td className="px-3 py-2">{shift.till}</td>
                                            <td className="px-3 py-2">{showDate(shift.opened_at)}</td>
                                            <td className="px-3 py-2">{showDate(shift.closed_at)}</td>
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
                                            <td className="px-3 py-2">
                                                <Link href={'/sales/shifts/' + shift.id + '/summary'} className="text-primary underline">
                                                    {t('till.z_summary')}
                                                </Link>
                                            </td>
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
