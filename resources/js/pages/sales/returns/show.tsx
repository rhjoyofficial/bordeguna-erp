import InputError from '@/components/input-error';
import { RecordHistory } from '@/components/record-history';
import { EngineActions, type EngineAction, type ReasonOption } from '@/components/sales/correction-actions';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface JournalLine {
    id: number;
    amount_minor: number;
    memo: string | null;
    account: { id: number; code: string; name: string } | null;
}

interface ReturnLine {
    id: number;
    quantity: string;
    quantity_to_stock: string;
    quantity_written_off: string;
    /** ⭐ B7: damaged, kept in the Not for sale warehouse. */
    quantity_to_quarantine?: string;
    credit_net: string;
    delivery_line: { id: number; product: { id: number; name: string; sku: string } | null } | null;
}

interface SalesReturnDetail {
    id: number;
    return_number: string;
    /** ⭐ B13 (24 Oct 2026): the CN number this return is the customer's credit note under. */
    credit_note_number: string | null;
    returned_at: string;
    credit_total: string;
    note: string | null;
    customer: { id: number; name: string } | null;
    delivery: { id: number; delivery_number: string; delivered_at: string } | null;
    lines: ReturnLine[];
    allocations: { id: number; amount: string; invoice: { id: number; invoice_number: string } | null }[];
    refunds: { id: number; amount: string; refunded_on: string; money_account: { id: number; name: string } | null }[];
    /** ⭐ B20: where it was opened from, where good goods went, the replacement. */
    invoice?: { id: number; invoice_number: string } | null;
    stock_warehouse?: { id: number; name: string } | null;
    exchange_orders?: { id: number; order_number: string }[];
}

interface OpenInvoice {
    id: number;
    invoice_number: string;
    invoice_date: string | null;
    outstanding: string;
}

/**
 * ⭐ B20 (Joy, 24 Oct 2026): the open credit of a return pays another open
 * invoice of the same customer. A popup, never a form above the list.
 */
function UseCreditDialog({
    salesReturn,
    refundable,
    openInvoices,
}: {
    salesReturn: SalesReturnDetail;
    refundable: string;
    openInvoices: OpenInvoice[];
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const { data, setData, post, errors, processing, reset } = useForm({
        sales_invoice_id: '',
        amount: '',
        open_seen: refundable,
    });
    const pick = (id: string) => {
        const invoice = openInvoices.find((row) => String(row.id) === id);
        const cap = invoice ? Math.min(parseFloat(invoice.outstanding), parseFloat(refundable)) : 0;
        setData((current) => ({ ...current, sales_invoice_id: id, amount: invoice ? cap.toFixed(2) : '' }));
    };
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.returns.apply-credit', salesReturn.id), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Button type="button" variant="outline" size="sm" onClick={() => setOpen(true)}>
                {t('ips.sret.use_credit')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('ips.sret.use_credit_title', { number: salesReturn.return_number })}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <p className="text-muted-foreground text-sm">{t('ips.sret.use_credit_hint', { open: formatMoney(refundable) })}</p>
                        <div className="grid w-fit gap-2">
                            <Label required>{t('ips.sret.invoice')}</Label>
                            <SearchSelect fit value={data.sales_invoice_id} onChange={(e) => pick(e.target.value)}>
                                <option value="">{t('ips.sret.choose_invoice')}</option>
                                {openInvoices.map((invoice) => (
                                    <option key={invoice.id} value={String(invoice.id)}>
                                        {invoice.invoice_number} ({formatDate(invoice.invoice_date)}),{' '}
                                        {t('ips.sret.owes', { amount: formatMoney(invoice.outstanding) })}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.sales_invoice_id} />
                        </div>
                        <div className="grid w-fit gap-2">
                            <Label required>{t('ips.sret.amount')}</Label>
                            <Input className="w-32" inputMode="decimal" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                            <InputError message={errors.amount} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                                {t('ips.sret.cancel')}
                            </Button>
                            <Button disabled={processing || data.sales_invoice_id === '' || data.amount === ''}>{t('ips.sret.apply')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

const taka = (minor: number) => (minor / 100).toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function ReturnShow({
    salesReturn,
    refundedTotal,
    refundable,
    journal,
    can_refund,
    moneyAccounts,
    openInvoices = [],
    can_exchange = false,
    corrections,
}: {
    salesReturn: SalesReturnDetail;
    refundedTotal: string;
    refundable: string;
    journal: { id: number; journal_date: string; memo: string | null; lines: JournalLine[] } | null;
    can_refund: boolean;
    moneyAccounts: { id: number; name: string }[];
    openInvoices?: OpenInvoice[];
    can_exchange?: boolean;
    /** ⭐ B13 (24 Oct 2026): Reverse, and the mirror that undid it. */
    corrections?: {
        actions: EngineAction[];
        reversal: { on: string | null; by: string | null; reason: string | null } | null;
        mirror: { id: number; return_number: string } | null;
        reverses: { id: number; return_number: string } | null;
        reversal_reasons: ReasonOption[];
    };
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Returns', href: '/sales/returns' },
        { title: salesReturn.return_number, href: `/sales/returns/${salesReturn.id}` },
    ];

    const { data, setData, post, errors, processing, reset } = useForm({
        money_account_id: '',
        amount: refundable,
        refunded_on: dhakaToday(),
        reference: '',
    });

    const submitRefund: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/sales/returns/${salesReturn.id}/refunds`, { onSuccess: () => reset() });
    };

    const canStillRefund = parseFloat(refundable) > 0.005;
    const exchangeOrders = salesReturn.exchange_orders ?? [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={salesReturn.return_number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{salesReturn.return_number}</h1>
                        {salesReturn.credit_note_number && (
                            <p className="text-muted-foreground text-sm">{t('ret.credit_note', { number: salesReturn.credit_note_number })}</p>
                        )}
                        {corrections?.reversal && (
                            <p className="text-sm text-amber-600 dark:text-amber-500">
                                {t('ret.reversed_on', { date: formatDate(corrections.reversal.on ?? ''), by: corrections.reversal.by ?? '' })}{' '}
                                {corrections.reversal.reason}
                                {corrections.mirror && (
                                    <>
                                        {' '}
                                        <Link href={`/sales/returns/${corrections.mirror.id}`} className="underline">
                                            {corrections.mirror.return_number}
                                        </Link>
                                    </>
                                )}
                            </p>
                        )}
                        {corrections?.reverses && (
                            <p className="text-muted-foreground text-sm">
                                {t('ret.mirror_of')}{' '}
                                <Link href={`/sales/returns/${corrections.reverses.id}`} className="underline">
                                    {corrections.reverses.return_number}
                                </Link>
                            </p>
                        )}
                    </div>
                    {corrections && (
                        <div className="flex flex-col items-end gap-1">
                            <EngineActions
                                type="sales.return"
                                id={salesReturn.id}
                                recordLabel={salesReturn.return_number}
                                actions={corrections.actions}
                                reasons={corrections.reversal_reasons}
                                labels={{ reverse: t('ret.reverse') }}
                            />
                            <RecordHistory type="sales.return" id={salesReturn.id} label={salesReturn.return_number} />
                        </div>
                    )}
                </div>
                {/* ⭐ B20: where it came from, where the good goods went, and the
                    replacement order of an exchange (or a way to start one). */}
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    {salesReturn.invoice && (
                        <span>
                            <span className="text-muted-foreground">{t('ips.sret.opened_from')}: </span>
                            <Link href={route('sales.invoices.show', salesReturn.invoice.id)} className="underline underline-offset-4">
                                {salesReturn.invoice.invoice_number}
                            </Link>
                        </span>
                    )}
                    {salesReturn.stock_warehouse && (
                        <span>
                            <span className="text-muted-foreground">{t('ips.sret.good_went_to')}: </span>
                            {salesReturn.stock_warehouse.name}
                        </span>
                    )}
                    {exchangeOrders.map((order) => (
                        <span key={order.id}>
                            <span className="text-muted-foreground">{t('ips.sret.replacement')}: </span>
                            <Link href={route('sales.orders.show', order.id)} className="underline underline-offset-4">
                                {order.order_number}
                            </Link>
                        </span>
                    ))}
                    <span className="ml-auto flex gap-2">
                        {can_exchange && exchangeOrders.length === 0 && (
                            <Button variant="outline" size="sm" asChild title={t('ips.sret.start_exchange_title')}>
                                <Link href={route('sales.orders.index', { exchange: salesReturn.id })}>{t('ips.sret.start_exchange')}</Link>
                            </Button>
                        )}
                        {can_refund && canStillRefund && openInvoices.length > 0 && (
                            <UseCreditDialog salesReturn={salesReturn} refundable={refundable} openInvoices={openInvoices} />
                        )}
                    </span>
                </div>
                <div className="grid gap-3 sm:grid-cols-4">
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Customer</p>
                        <p className="font-medium">{salesReturn.customer?.name ?? '-'}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Against challan</p>
                        <p className="font-medium">{salesReturn.delivery?.delivery_number ?? '-'}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Customer credit</p>
                        <p className="font-medium tabular-nums">{formatMoney(salesReturn.credit_total)}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Refunded / still refundable</p>
                        <p className="font-medium tabular-nums">
                            {formatMoney(refundedTotal)} / {formatMoney(refundable)}
                        </p>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3 text-right">Returned</th>
                                <th className="p-3 text-right">Back to stock</th>
                                <th className="p-3 text-right">Written off</th>
                                <th className="p-3 text-right">{t('ips.ret.col_quarantine')}</th>
                                <th className="p-3 text-right">Customer credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {salesReturn.lines.map((line) => (
                                <tr key={line.id} className="border-t">
                                    <td className="p-3">
                                        {line.delivery_line?.product?.name ?? '-'}{' '}
                                        <span className="text-muted-foreground">({line.delivery_line?.product?.sku})</span>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_to_stock)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_written_off)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_to_quarantine ?? '0')}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(line.credit_net)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {salesReturn.allocations.length > 0 && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">Credit applied to invoices</h2>
                        <table className="w-full text-sm">
                            <tbody>
                                {salesReturn.allocations.map((allocation) => (
                                    <tr key={allocation.id} className="border-t first:border-t-0">
                                        <td className="p-3">{allocation.invoice?.invoice_number ?? '-'}</td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(allocation.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {salesReturn.refunds.length > 0 && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">Refunds paid</h2>
                        <table className="w-full text-sm">
                            <tbody>
                                {salesReturn.refunds.map((refund) => (
                                    <tr key={refund.id} className="border-t first:border-t-0">
                                        <td className="p-3">{formatDate(refund.refunded_on)}</td>
                                        <td className="p-3">{refund.money_account?.name ?? '-'}</td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(refund.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {can_refund && canStillRefund && (
                    <form onSubmit={submitRefund} className="flex flex-wrap items-end gap-3 rounded-lg border p-3">
                        <div className="grid gap-2">
                            <Label required>Refund from</Label>
                            <SearchSelect fit value={data.money_account_id} onChange={(e) => setData('money_account_id', e.target.value)}>
                                <option value="">Select account</option>
                                {moneyAccounts.map((account) => (
                                    <option key={account.id} value={String(account.id)}>
                                        {account.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.money_account_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label required>Amount</Label>
                            <Input className="max-w-32" aria-required value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label required>Date</Label>
                            <Input type="date" aria-required value={data.refunded_on} onChange={(e) => setData('refunded_on', e.target.value)} />
                            <InputError message={errors.refunded_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Reference (optional)</Label>
                            <Input value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                        </div>
                        <Button disabled={processing || !data.money_account_id}>Pay refund</Button>
                    </form>
                )}

                {/* The journal on the document, as everywhere else: what this
                    return did to the books, in the books' own words. */}
                {journal && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">What this did to the books</h2>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Account</th>
                                    <th className="p-3">Memo</th>
                                    <th className="p-3 text-right">Debit</th>
                                    <th className="p-3 text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                {journal.lines.map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="p-3">
                                            {line.account?.code} {line.account?.name}
                                        </td>
                                        <td className="text-muted-foreground p-3">{line.memo}</td>
                                        <td className="p-3 text-right tabular-nums">{line.amount_minor > 0 ? taka(line.amount_minor) : ''}</td>
                                        <td className="p-3 text-right tabular-nums">{line.amount_minor < 0 ? taka(-line.amount_minor) : ''}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
