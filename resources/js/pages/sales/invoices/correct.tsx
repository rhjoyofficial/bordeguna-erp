import { ActionDialog } from '@/components/action-dialog';
import { type EngineAction, type ReasonOption } from '@/components/sales/correction-actions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { salesLineTotal } from '@/lib/sales-line';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

// A type, not an interface: it travels in an Inertia payload (index signature).
type FormLine = {
    invoice_line_id: number;
    quantity: string;
    unit_price: string;
    discount: string;
    description: string;
};

interface Props {
    invoice: {
        id: number;
        invoice_number: string;
        customer: string | null;
        invoice_date: string;
        due_date: string | null;
        invoice_discount: string;
        note: string | null;
        total: string;
        lines: {
            id: number;
            product: string | null;
            challan: string | null;
            quantity: string;
            unit_price: string;
            discount: string;
            description: string | null;
        }[];
        delivery_charges: { description: string | null; amount: string }[];
    };
    actions: EngineAction[];
    reasons: ReasonOption[];
    canOverridePrice: boolean;
}

/**
 * ⭐ Correct an issued sales invoice (corrections wave 1, 20 Oct 2026).
 *
 * The invoice's own lines, pre-filled, the date first. Nothing is saved from
 * this page: "Preview and correct" opens the action dialog (date, effect, what
 * stays), which gives the credit note and raises the right invoice in one step.
 * The issued invoice stays exactly as it was, marked Corrected.
 *
 * ⚠ No keyboard shortcuts: there is no <form>, so Enter in a box saves nothing.
 */
export default function CorrectSalesInvoice({ invoice, actions, reasons, canOverridePrice }: Props) {
    const { t } = useTranslation();
    const today = dhakaToday();
    const availability = actions[0];
    const usable = !!availability && (availability.allowed || availability.requestable);
    const [form, setForm] = useState({
        invoice_date: invoice.invoice_date,
        due_date: invoice.due_date ?? '',
        invoice_discount: invoice.invoice_discount,
        note: invoice.note ?? '',
        lines: invoice.lines.map(
            (line): FormLine => ({
                invoice_line_id: line.id,
                quantity: line.quantity,
                unit_price: line.unit_price,
                discount: line.discount,
                description: line.description ?? '',
            }),
        ),
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Invoices', href: '/sales/invoices' },
        { title: invoice.invoice_number, href: `/sales/invoices/${invoice.id}` },
        { title: t('corr.sales.invoice.correct_title', { number: invoice.invoice_number }), href: `/sales/invoices/${invoice.id}/correct` },
    ];

    const setLine = (index: number, field: keyof FormLine, value: string) =>
        setForm((previous) => ({ ...previous, lines: previous.lines.map((line, i) => (i === index ? { ...line, [field]: value } : line)) }));

    const goods = form.lines.reduce(
        (sum, line) => sum + (Number(salesLineTotal(line.quantity, line.unit_price)) || 0) - (Number(line.discount) || 0),
        0,
    );

    // Memoised: the dialog previews again whenever this changes, and only then.
    const replacement = useMemo(() => ({ ...form, due_date: form.due_date === '' ? null : form.due_date }), [form]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('corr.sales.invoice.correct_title', { number: invoice.invoice_number })} />

            <div className="flex max-w-5xl flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">{t('corr.sales.invoice.correct_title', { number: invoice.invoice_number })}</h1>

                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                    {t('corr.sales.invoice.correct_banner', { number: invoice.invoice_number })} {invoice.customer} ·{' '}
                    {formatMoney(Number(invoice.total))}.{availability?.date?.sentence ? ` ${availability.date.sentence}` : ''}
                </div>

                {!canOverridePrice && <p className="text-muted-foreground text-sm">{t('corr.sales.invoice.no_price_change')}</p>}

                {!usable &&
                    availability?.blockers.map((blocker) => (
                        <p key={blocker.code} className="text-destructive text-sm">
                            {blocker.message}
                        </p>
                    ))}

                <div className="flex flex-wrap items-start gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="invoice_date" required>
                            {t('corr.sales.invoice.invoice_date')}
                        </Label>
                        <Input
                            id="invoice_date"
                            type="date"
                            className="w-40"
                            max={today}
                            value={form.invoice_date}
                            onChange={(e) => setForm((p) => ({ ...p, invoice_date: e.target.value }))}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="due_date">{t('corr.sales.invoice.due_date')}</Label>
                        <Input
                            id="due_date"
                            type="date"
                            className="w-40"
                            min={form.invoice_date}
                            value={form.due_date}
                            onChange={(e) => setForm((p) => ({ ...p, due_date: e.target.value }))}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="invoice_discount">{t('corr.sales.invoice.discount_whole')}</Label>
                        <Input
                            id="invoice_discount"
                            inputMode="decimal"
                            className="w-36 text-right"
                            value={form.invoice_discount}
                            onChange={(e) => setForm((p) => ({ ...p, invoice_discount: e.target.value }))}
                        />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[48rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-2">{t('corr.sales.invoice.col_item')}</th>
                                <th className="p-2">{t('corr.sales.invoice.col_challan')}</th>
                                <th className="p-2 text-right">{t('corr.sales.invoice.col_qty')}</th>
                                <th className="p-2 text-right">{t('corr.sales.invoice.col_price')}</th>
                                <th className="p-2 text-right">{t('corr.sales.invoice.col_discount')}</th>
                                <th className="p-2 text-right">{t('corr.sales.invoice.col_total')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {form.lines.map((line, index) => {
                                const source = invoice.lines[index];
                                const net = (Number(salesLineTotal(line.quantity, line.unit_price)) || 0) - (Number(line.discount) || 0);

                                return (
                                    <tr key={line.invoice_line_id} className="border-t align-top">
                                        <td className="p-2">
                                            <div>{source.product}</div>
                                            <Input
                                                aria-label={`Description, line ${index + 1}`}
                                                className="mt-1 h-8 w-64"
                                                value={line.description}
                                                onChange={(e) => setLine(index, 'description', e.target.value)}
                                            />
                                        </td>
                                        <td className="text-muted-foreground p-2">{source.challan ?? ''}</td>
                                        <td className="p-2 text-right">
                                            <Input
                                                aria-label={`Quantity, line ${index + 1}`}
                                                inputMode="decimal"
                                                className="ml-auto w-24 text-right"
                                                value={line.quantity}
                                                onChange={(e) => setLine(index, 'quantity', e.target.value)}
                                            />
                                        </td>
                                        <td className="p-2 text-right">
                                            <Input
                                                aria-label={`Unit price, line ${index + 1}`}
                                                inputMode="decimal"
                                                className="ml-auto w-28 text-right"
                                                value={line.unit_price}
                                                onChange={(e) => setLine(index, 'unit_price', e.target.value)}
                                            />
                                        </td>
                                        <td className="p-2 text-right">
                                            <Input
                                                aria-label={`Discount, line ${index + 1}`}
                                                inputMode="decimal"
                                                className="ml-auto w-24 text-right"
                                                value={line.discount}
                                                onChange={(e) => setLine(index, 'discount', e.target.value)}
                                            />
                                        </td>
                                        <td className="p-2 text-right tabular-nums">{formatMoney(net)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <p className="text-sm">
                    <span className="text-muted-foreground">{t('corr.sales.invoice.new_total')} </span>
                    <span className="font-semibold tabular-nums">{formatMoney(goods - (Number(form.invoice_discount) || 0))}</span>
                </p>
                {invoice.delivery_charges.length > 0 && (
                    <p className="text-muted-foreground text-sm">
                        {t('corr.sales.invoice.delivery_again', {
                            list: invoice.delivery_charges
                                .map((charge) => `${charge.description ?? ''} ${formatMoney(Number(charge.amount))}`)
                                .join(', '),
                        })}
                    </p>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="note">{t('corr.sales.invoice.note')}</Label>
                    {/* The one control that IS full width, because a note is prose. */}
                    <textarea
                        id="note"
                        className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                        value={form.note}
                        onChange={(e) => setForm((p) => ({ ...p, note: e.target.value }))}
                    />
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href={`/sales/invoices/${invoice.id}`}>{t('corr.sales.go_back')}</Link>
                    </Button>
                    <ActionDialog
                        action="correct"
                        recordLabel={invoice.invoice_number}
                        previewUrl={`/records/sales.invoice/${invoice.id}/correct/preview`}
                        submitUrl={`/records/sales.invoice/${invoice.id}/correct`}
                        reasons={reasons}
                        replacement={replacement}
                        redirectTo={`/sales/invoices/${invoice.id}`}
                        trigger={<Button disabled={!usable}>{t('corr.sales.preview_correct')}</Button>}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
