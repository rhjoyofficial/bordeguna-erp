import { ActionDialog } from '@/components/action-dialog';
import { type EngineAction, type ReasonOption } from '@/components/sales/correction-actions';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface Props {
    receipt: {
        id: number;
        label: string;
        customer_id: number;
        money_account_id: number;
        received_on: string;
        amount: string;
        reference: string | null;
        note: string | null;
        allocations: { sales_invoice_id: number; amount: string }[];
    };
    customers: { id: number; name: string }[];
    invoices: { id: number; customer_id: number; invoice_number: string; invoice_date: string; outstanding: string }[];
    moneyAccounts: { id: number; name: string }[];
    actions: EngineAction[];
    reasons: ReasonOption[];
}

/**
 * ⭐ Correct a customer receipt (corrections wave 1, 20 Oct 2026): the
 * receipt's form, pre-filled, the date first. "Preview and correct" opens the
 * action dialog, which reverses this receipt on its own date and records the
 * right one in one step. The old receipt stays, marked Corrected.
 *
 * ⚠ No keyboard shortcuts: there is no <form>, so Enter in a box saves nothing.
 */
export default function CorrectReceipt({ receipt, customers, invoices, moneyAccounts, actions, reasons }: Props) {
    const { t } = useTranslation();
    const today = dhakaToday();
    const availability = actions[0];
    const usable = !!availability && (availability.allowed || availability.requestable);
    const [form, setForm] = useState({
        received_on: receipt.received_on,
        customer_id: String(receipt.customer_id),
        money_account_id: String(receipt.money_account_id),
        amount: receipt.amount,
        reference: receipt.reference ?? '',
        note: receipt.note ?? '',
    });
    const [paying, setPaying] = useState<Record<number, string>>(() =>
        Object.fromEntries(receipt.allocations.map((allocation) => [allocation.sales_invoice_id, allocation.amount])),
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Receipts', href: '/sales/receipts' },
        { title: t('corr.sales.receipt.correct_title', { label: receipt.label }), href: `/sales/receipts/${receipt.id}/correct` },
    ];

    // Memoised: the dialog previews again only when the replacement changes.
    const theirs = useMemo(() => invoices.filter((invoice) => String(invoice.customer_id) === form.customer_id), [invoices, form.customer_id]);
    const allocated = theirs.reduce((sum, invoice) => sum + (Number(paying[invoice.id]) || 0), 0);

    const replacement = useMemo(
        () => ({
            ...form,
            allocations: theirs
                .filter((invoice) => Number(paying[invoice.id]) > 0)
                .map((invoice) => ({ sales_invoice_id: invoice.id, amount: paying[invoice.id] })),
        }),
        [form, paying, theirs],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('corr.sales.receipt.correct_title', { label: receipt.label })} />

            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">{t('corr.sales.receipt.correct_title', { label: receipt.label })}</h1>

                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                    {t('corr.sales.receipt.correct_banner', { label: receipt.label })}
                    {availability?.date?.sentence ? ` ${availability.date.sentence}` : ''}
                </div>

                {!usable &&
                    availability?.blockers.map((blocker) => (
                        <p key={blocker.code} className="text-destructive text-sm">
                            {blocker.message}
                        </p>
                    ))}

                <div className="flex flex-wrap items-start gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="received_on" required>
                            {t('corr.sales.receipt.received_on')}
                        </Label>
                        <Input
                            id="received_on"
                            type="date"
                            className="w-40"
                            max={today}
                            value={form.received_on}
                            onChange={(e) => setForm((p) => ({ ...p, received_on: e.target.value }))}
                        />
                    </div>
                    <div className="grid w-64 gap-2">
                        <Label htmlFor="customer_id" required>
                            {t('corr.sales.receipt.customer')}
                        </Label>
                        <SearchSelect
                            id="customer_id"
                            value={form.customer_id}
                            onChange={(e) => setForm((p) => ({ ...p, customer_id: e.target.value }))}
                        >
                            {customers.map((customer) => (
                                <option key={customer.id} value={customer.id}>
                                    {customer.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid w-56 gap-2">
                        <Label htmlFor="money_account_id" required>
                            {t('corr.sales.receipt.into')}
                        </Label>
                        <SearchSelect
                            id="money_account_id"
                            value={form.money_account_id}
                            onChange={(e) => setForm((p) => ({ ...p, money_account_id: e.target.value }))}
                        >
                            {moneyAccounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="amount" required>
                            {t('corr.sales.amount')}
                        </Label>
                        <Input
                            id="amount"
                            inputMode="decimal"
                            className="w-36 text-right"
                            value={form.amount}
                            onChange={(e) => setForm((p) => ({ ...p, amount: e.target.value }))}
                        />
                    </div>
                    <div className="grid w-56 gap-2">
                        <Label htmlFor="reference">{t('corr.sales.receipt.reference')}</Label>
                        <Input id="reference" value={form.reference} onChange={(e) => setForm((p) => ({ ...p, reference: e.target.value }))} />
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-2 rounded-md border p-3">
                    <Label>{t('corr.sales.receipt.pays')}</Label>
                    {theirs.map((invoice) => (
                        <div key={invoice.id} className="flex flex-wrap items-center gap-3 text-sm">
                            <span className="w-44 font-medium">{invoice.invoice_number}</span>
                            <span className="text-muted-foreground w-28">{formatDate(invoice.invoice_date)}</span>
                            <span className="text-muted-foreground w-36">
                                {t('corr.sales.receipt.owes', { amount: formatMoney(Number(invoice.outstanding)) })}
                            </span>
                            <Input
                                aria-label={`Amount on ${invoice.invoice_number}`}
                                inputMode="decimal"
                                className="w-32 text-right"
                                value={paying[invoice.id] ?? ''}
                                onChange={(e) => setPaying((p) => ({ ...p, [invoice.id]: e.target.value }))}
                            />
                        </div>
                    ))}
                    <p className="text-muted-foreground text-sm">
                        {t('corr.sales.receipt.unallocated', { amount: formatMoney((Number(form.amount) || 0) - allocated) })}
                    </p>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="note">{t('corr.sales.invoice.note')}</Label>
                    <textarea
                        id="note"
                        className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                        value={form.note}
                        onChange={(e) => setForm((p) => ({ ...p, note: e.target.value }))}
                    />
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href="/sales/receipts">{t('corr.sales.go_back')}</Link>
                    </Button>
                    <ActionDialog
                        action="correct"
                        recordLabel={receipt.label}
                        previewUrl={`/records/sales.customer_receipt/${receipt.id}/correct/preview`}
                        submitUrl={`/records/sales.customer_receipt/${receipt.id}/correct`}
                        reasons={reasons}
                        replacement={replacement}
                        redirectTo="/sales/receipts"
                        trigger={<Button disabled={!usable}>{t('corr.sales.preview_correct')}</Button>}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
