import { DuplicateWarning } from '@/components/accounting/duplicate-warning';
import InputError from '@/components/input-error';
import { type AdvanceRow } from '@/components/sales/customer-advance-card';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface OpenInvoice {
    id: number;
    number: string;
    date: string | null;
    due: string | null;
    is_overdue: boolean;
    total: string;
    outstanding: string;
}

// Whole paisa, so the split adds up exactly to what was typed.
const toPaisa = (value: string) => Math.round((parseFloat(value) || 0) * 100);
const fromPaisa = (paisa: number) => (paisa / 100).toFixed(2);
const money = (paisa: number) => formatMoney(paisa / 100);

/**
 * ⭐ Receive payment (21 Sep 2026): pick the customer, type what they paid, and
 * it is spread over their oldest invoices first. Every figure stays editable
 * before anything is posted, and nothing is posted that does not add up.
 *
 * ⭐ Daily screen 4 of 6 in plain words and Bangla (26 Sep 2026). Digits,
 * dates and money never translate.
 *
 * ⭐ B11 (24 Oct 2026, Joy's R90): their advance with us is used first on the
 * oldest invoices (a box the person fills, never silent), the money then pays
 * what is left, and anything received beyond the invoices can be kept as
 * their advance with a tick. A payment with no invoice at all is an advance.
 */
export default function ReceiptCreate({
    customers,
    customer,
    invoices,
    moneyAccounts,
    today,
    advances = [],
}: {
    customers: { id: number; name: string; phone: string | null }[];
    customer: { id: number; name: string } | null;
    invoices: OpenInvoice[];
    /** F20: balance only for people who may read the books, else null. */
    moneyAccounts: { id: number; name: string; type: string; balance?: string | null }[];
    today: string;
    /** B11: the customer's open advances, oldest first. */
    advances?: AdvanceRow[];
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('receipt.sales'), href: '/sales/overview' },
        { title: t('receipt.receipts'), href: '/sales/receipts' },
        { title: t('receipt.title'), href: '/sales/receipts/new' },
    ];
    const [received, setReceived] = useState('');
    const [split, setSplit] = useState<Record<number, string>>({});
    const [useAdvance, setUseAdvance] = useState('');
    const [keepRest, setKeepRest] = useState(false);
    const form = useForm({
        customer_id: customer ? String(customer.id) : '',
        money_account_id: moneyAccounts.length === 1 ? String(moneyAccounts[0].id) : '',
        received_on: today,
        reference: '',
        note: '',
        allocations: [] as { invoice_id: number; amount: string }[],
    });

    const totalOwed = useMemo(() => invoices.reduce((sum, invoice) => sum + toPaisa(invoice.outstanding), 0), [invoices]);
    const advanceOpen = advances.reduce((sum, row) => sum + toPaisa(row.open), 0);
    const advancePaisa = Math.min(toPaisa(useAdvance), advanceOpen, totalOwed);

    // B11: the advance pays the oldest invoices first.
    const fromAdvance = useMemo(() => {
        let left = advancePaisa;
        const next: Record<number, number> = {};
        for (const invoice of invoices) {
            const take = Math.max(0, Math.min(left, toPaisa(invoice.outstanding)));
            next[invoice.id] = take;
            left -= take;
        }
        return next;
    }, [advancePaisa, invoices]);
    const owed = totalOwed - advancePaisa;
    const allocated = invoices.reduce((sum, invoice) => sum + toPaisa(split[invoice.id] ?? '0'), 0);
    const receivedPaisa = toPaisa(received);
    const rest = receivedPaisa - allocated;
    const keep = keepRest && rest > 0 ? rest : 0;

    // Each invoice's share of the advance, drawn from the advances oldest first.
    const advanceUses = useMemo(() => {
        const open = advances.map((row) => ({ key: row.key, left: toPaisa(row.open) }));
        const uses: { invoice_id: number; key: string; amount: string }[] = [];
        for (const invoice of invoices) {
            let need = fromAdvance[invoice.id] ?? 0;
            for (const row of open) {
                if (need <= 0) break;
                const take = Math.min(need, row.left);
                if (take <= 0) continue;
                uses.push({ invoice_id: invoice.id, key: row.key, amount: fromPaisa(take) });
                row.left -= take;
                need -= take;
            }
        }
        return uses;
    }, [advances, invoices, fromAdvance]);

    const chooseCustomer = (id: string) => {
        router.get(route('sales.receipts.create'), id ? { customer_id: id } : {}, { preserveScroll: true });
    };

    // Oldest first, each invoice up to what it still owes after the advance.
    const spread = (value: string, advanceValue: string = useAdvance) => {
        setReceived(value);
        let left = toPaisa(value);
        let advanceLeft = Math.min(toPaisa(advanceValue), advanceOpen, totalOwed);
        const next: Record<number, string> = {};
        for (const invoice of invoices) {
            const byAdvance = Math.max(0, Math.min(advanceLeft, toPaisa(invoice.outstanding)));
            advanceLeft -= byAdvance;
            const take = Math.min(left, toPaisa(invoice.outstanding) - byAdvance);
            next[invoice.id] = take > 0 ? fromPaisa(take) : '';
            left -= Math.max(take, 0);
        }
        setSplit(next);
    };

    const rows = invoices.map((invoice) => ({ invoice_id: invoice.id, amount: split[invoice.id] ?? '' })).filter((row) => toPaisa(row.amount) > 0);

    // The server reports errors by position among the rows it was sent.
    const errorFor = (invoiceId: number) => {
        const index = rows.findIndex((row) => row.invoice_id === invoiceId);
        return index < 0 ? undefined : form.errors[`allocations.${index}.amount` as keyof typeof form.errors];
    };

    const payload = (ack: string | null) => ({
        allocations: rows,
        keep_as_advance: keep > 0 ? fromPaisa(keep) : null,
        advance_uses: advanceUses,
        duplicate_ack: ack,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, ...payload(null) }));
        form.post(route('sales.receipts.store'));
    };

    // ⭐ Corrections wave 2 (24 Oct 2026): "Save anyway" on a possible duplicate.
    const saveAnyway = (ack: string) => {
        form.transform((data) => ({ ...data, ...payload(ack) }));
        form.post(route('sales.receipts.store'));
    };

    const mismatch = receivedPaisa > 0 && allocated + keep !== receivedPaisa;
    const somethingToSave = rows.length > 0 || keep > 0 || advanceUses.length > 0;
    const needsAccount = allocated + keep > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('receipt.title')} />
            {/* ⚠ Enter never submits (Joy, 19 Oct 2026): only the button saves. */}
            <form
                onSubmit={submit}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' && (e.target as HTMLElement).tagName === 'INPUT') {
                        e.preventDefault();
                    }
                }}
                className="flex max-w-5xl flex-col gap-6 p-4"
            >
                <h1 className="text-lg font-semibold">{t('receipt.title')}</h1>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-2">
                        <Label htmlFor="received_on" required>
                            {t('receipt.date_received')}
                        </Label>
                        <Input
                            id="received_on"
                            type="date"
                            aria-required
                            max={today}
                            value={form.data.received_on}
                            onChange={(e) => form.setData('received_on', e.target.value)}
                        />
                        <InputError message={form.errors.received_on} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="customer" required>
                            {t('receipt.customer')}
                        </Label>
                        <SearchSelect id="customer" value={form.data.customer_id} onChange={(e) => chooseCustomer(e.target.value)}>
                            <option value="">{t('receipt.choose_customer')}</option>
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.phone ? `${c.name} (${c.phone})` : c.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={form.errors.customer_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="received" required>
                            {t('receipt.amount_received')}
                        </Label>
                        <Input
                            id="received"
                            type="number"
                            aria-required
                            inputMode="decimal"
                            min={0}
                            step="0.01"
                            value={received}
                            onChange={(e) => spread(e.target.value)}
                            disabled={!customer}
                        />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="account" required>
                            {t('receipt.received_into')}
                        </Label>
                        <SearchSelect
                            id="account"
                            value={form.data.money_account_id}
                            onChange={(e) => form.setData('money_account_id', e.target.value)}
                        >
                            <option value="">{t('receipt.cash_bank_wallet')}</option>
                            {moneyAccounts.map((account) => (
                                <option
                                    key={account.id}
                                    value={account.id}
                                    data-hint={account.balance != null ? `Balance now ${formatMoney(account.balance)}` : undefined}
                                >
                                    {account.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {moneyAccounts.length === 0 && (
                            <p className="text-xs text-amber-600 dark:text-amber-500">
                                {t('receipt.no_money_account')}{' '}
                                <Link href="/accounting/money-accounts" className="underline">
                                    Accounting &gt; Bank &amp; cash
                                </Link>
                            </p>
                        )}
                        <InputError message={form.errors.money_account_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reference">{t('receipt.reference')}</Label>
                        <Input
                            id="reference"
                            value={form.data.reference}
                            onChange={(e) => form.setData('reference', e.target.value)}
                            placeholder={t('receipt.reference_placeholder')}
                        />
                        <InputError message={form.errors.reference} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="note">{t('receipt.note')}</Label>
                        <Input
                            id="note"
                            value={form.data.note}
                            onChange={(e) => form.setData('note', e.target.value)}
                            placeholder={t('receipt.optional')}
                        />
                    </div>
                </div>

                {customer && advanceOpen > 0 && invoices.length > 0 && (
                    <section className="grid gap-2 rounded-lg border p-3">
                        <h2 className="text-sm font-semibold">{t('adv.receipt_use')}</h2>
                        <p className="text-muted-foreground text-sm">
                            {t('adv.use_available', { amount: money(advanceOpen) })} · {t('adv.receipt_use_hint')}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                aria-label={t('adv.receipt_use')}
                                inputMode="decimal"
                                className="h-9 w-36 text-right"
                                value={useAdvance}
                                onChange={(e) => {
                                    setUseAdvance(e.target.value);
                                    spread(received, e.target.value);
                                }}
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    const all = fromPaisa(Math.min(advanceOpen, totalOwed));
                                    setUseAdvance(all);
                                    spread(received, all);
                                }}
                            >
                                {money(Math.min(advanceOpen, totalOwed))}
                            </Button>
                        </div>
                    </section>
                )}

                {customer && (
                    <section className="grid gap-2">
                        <h2 className="text-sm font-semibold">{t('receipt.unpaid_invoices_of', { name: customer.name })}</h2>
                        {invoices.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{t('receipt.nothing_owed')}</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[40rem] text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b text-left">
                                            <th className="py-2">{t('receipt.col_invoice')}</th>
                                            <th className="py-2">{t('receipt.col_date')}</th>
                                            <th className="py-2">{t('receipt.col_due')}</th>
                                            <th className="py-2 text-right">{t('receipt.col_total')}</th>
                                            <th className="py-2 text-right">{t('receipt.col_still_owed')}</th>
                                            {advancePaisa > 0 && <th className="py-2 text-right">{t('adv.receipt_from_advance')}</th>}
                                            <th className="py-2 text-right">{t('receipt.col_pay_now')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invoices.map((invoice) => (
                                            <tr key={invoice.id} className="border-b">
                                                <td className="py-2">
                                                    <Link href={`/sales/invoices/${invoice.id}`} className="underline-offset-2 hover:underline">
                                                        {invoice.number}
                                                    </Link>
                                                </td>
                                                <td className="text-muted-foreground py-2">{showDate(invoice.date)}</td>
                                                <td className="py-2">
                                                    {invoice.due ?? '-'}
                                                    {invoice.is_overdue && (
                                                        <Badge variant="destructive" className="ml-2">
                                                            {t('receipt.overdue')}
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="text-muted-foreground py-2 text-right tabular-nums">{formatMoney(invoice.total)}</td>
                                                <td className="py-2 text-right tabular-nums">{money(toPaisa(invoice.outstanding))}</td>
                                                {advancePaisa > 0 && (
                                                    <td className="py-2 text-right tabular-nums">{money(fromAdvance[invoice.id] ?? 0)}</td>
                                                )}
                                                <td className="py-2 text-right">
                                                    <Input
                                                        aria-label={t('receipt.pay_now_on', { number: invoice.number })}
                                                        type="number"
                                                        inputMode="decimal"
                                                        min={0}
                                                        step="0.01"
                                                        className="ml-auto h-9 w-32 text-right"
                                                        value={split[invoice.id] ?? ''}
                                                        onChange={(e) => setSplit((current) => ({ ...current, [invoice.id]: e.target.value }))}
                                                    />
                                                    <InputError message={errorFor(invoice.id)} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="font-medium">
                                            <td className="py-2" colSpan={4}>
                                                {t('receipt.owed_in_all')}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">{money(totalOwed)}</td>
                                            {advancePaisa > 0 && <td className="py-2 text-right tabular-nums">{money(advancePaisa)}</td>}
                                            <td className="py-2 text-right tabular-nums">{money(allocated)}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}
                    </section>
                )}

                {customer && rest > 0 && (
                    <label className="flex w-fit items-center gap-2 text-sm">
                        <input type="checkbox" checked={keepRest} onChange={(e) => setKeepRest(e.target.checked)} />
                        {allocated > 0 ? t('adv.keep_rest', { amount: money(rest) }) : t('adv.keep_all', { amount: money(rest) })}
                    </label>
                )}
                {receivedPaisa > owed && owed > 0 && !keepRest && (
                    <p className="text-sm text-amber-600 dark:text-amber-500">{t('receipt.owe_only', { owed: money(owed) })}</p>
                )}
                {mismatch && receivedPaisa <= owed && (
                    <p className="text-sm text-amber-600 dark:text-amber-500">
                        {t('receipt.split_mismatch', { allocated: money(allocated), received: money(receivedPaisa) })}
                    </p>
                )}
                <InputError message={form.errors.allocations} />
                {Object.entries(form.errors as Record<string, string>)
                    .filter(
                        ([key]) =>
                            key === 'keep_as_advance' ||
                            key.startsWith('advance_uses') ||
                            key.startsWith('advances.') ||
                            key === 'advances' ||
                            key === 'applied_on',
                    )
                    .map(([key, message]) => (
                        <InputError key={key} message={message} />
                    ))}

                <DuplicateWarning errors={form.errors as Record<string, string | undefined>} busy={form.processing} onSaveAnyway={saveAnyway} />

                <div className="flex items-center gap-3">
                    <Button disabled={form.processing || !somethingToSave || mismatch || (needsAccount && !form.data.money_account_id)}>
                        {allocated > 0 ? t('receipt.receive_amount', { amount: money(allocated) }) : t('receipt.receive_button')}
                    </Button>
                    <Link href="/sales/receipts" className="text-muted-foreground text-sm hover:underline">
                        {t('receipt.cancel')}
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
