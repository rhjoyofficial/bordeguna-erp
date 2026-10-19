import { ActionDialog } from '@/components/action-dialog';
import { CorrectionAvailability } from '@/components/purchasing/supplier-money-corrections';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

// Types, not interfaces: they travel in an Inertia payload (index signature).
type GoodsLine = {
    purchase_order_line_id: number;
    goods_receipt_line_id: number | null;
    product: string | null;
    po_number: string | null;
    challan: string | null;
    quantity: string;
    unit_cost: string;
    line_total: string;
    entry_mode: 'rate' | 'total';
    max_quantity: string;
};

type ServiceLine = { expense_account_id: string; description: string; amount: string };

interface Props {
    bill: {
        id: number;
        bill_number: string;
        bill_date: string;
        due_date: string;
        note: string;
        supplier: string | null;
        total: string;
        settled: string;
        lines: GoodsLine[];
        services: ServiceLine[];
    };
    expense_accounts: { id: number; label: string }[];
    availability: CorrectionAvailability | null;
    correctionReasons: { id: number; name: string }[];
    type: string;
    today: string;
}

/**
 * Correct a posted supplier bill (corrections wave 1, 20 Oct 2026, FINAL-PLAN
 * 3.3). The bill's own form, pre-filled; the date comes first (Joy, 19 Oct).
 * Nothing is saved from this page: "Preview and correct" opens the action
 * dialog, which shows the date, the effect and what stays, then reverses the
 * bill on its own date and records the right one in one go. A paid bill's
 * payments move to the corrected bill.
 *
 * ⚠ No keyboard shortcuts and no Enter-to-submit: only the buttons act.
 */
export default function CorrectBill({ bill, expense_accounts, availability, correctionReasons, type, today }: Props) {
    const { t } = useTranslation();
    const [form, setForm] = useState({
        bill_date: bill.bill_date,
        bill_number: bill.bill_number,
        due_date: bill.due_date,
        note: bill.note,
        lines: bill.lines,
        services: bill.services,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Bills & payments', href: '/purchasing/bills' },
        { title: `Bill ${bill.bill_number}`, href: `/purchasing/bills/${bill.id}` },
        { title: t('corr.sup.correct'), href: '#' },
    ];

    const set = (field: 'bill_date' | 'bill_number' | 'due_date' | 'note', value: string) => setForm((p) => ({ ...p, [field]: value }));
    const setLine = (index: number, patch: Partial<GoodsLine>) =>
        setForm((p) => ({ ...p, lines: p.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)) }));
    const setService = (index: number, patch: Partial<ServiceLine>) =>
        setForm((p) => ({ ...p, services: p.services.map((line, i) => (i === index ? { ...line, ...patch } : line)) }));

    const lineTotal = (line: GoodsLine) =>
        line.entry_mode === 'total'
            ? Number.parseFloat(line.line_total) || 0
            : (Number.parseFloat(line.quantity) || 0) * (Number.parseFloat(line.unit_cost) || 0);
    const total =
        form.lines.reduce((sum, line) => sum + lineTotal(line), 0) +
        form.services.reduce((sum, line) => sum + (Number.parseFloat(line.amount) || 0), 0);

    const usable = !!availability && (availability.allowed || availability.requestable);
    const base = `/records/${type}/${bill.id}`;

    const replacement = useMemo(
        () => ({
            bill_date: form.bill_date,
            bill_number: form.bill_number,
            due_date: form.due_date === '' ? null : form.due_date,
            note: form.note,
            lines: form.lines.map((line) => ({
                purchase_order_line_id: line.purchase_order_line_id,
                goods_receipt_line_id: line.goods_receipt_line_id,
                quantity: line.quantity,
                unit_cost: line.entry_mode === 'rate' ? line.unit_cost : null,
                line_total: line.entry_mode === 'total' ? line.line_total : null,
                entry_mode: line.entry_mode,
            })),
            services: form.services.filter((line) => line.expense_account_id !== '' || line.amount !== ''),
        }),
        [form],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('corr.sup.correct')} ${bill.bill_number}`} />

            <div className="flex max-w-4xl flex-col gap-4 p-4">
                <ReportHeading title={`${t('corr.sup.correct')} Bill ${bill.bill_number}`} />

                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                    {t('corr.sup.banner', { number: `Bill ${bill.bill_number}`, amount: `BDT ${formatMoney(bill.total)}` })}
                    {availability?.date?.sentence ? ` ${availability.date.sentence}` : ''}
                    {Number(bill.settled) > 0 && (
                        <div className="mt-1">{t('corr.sup.paid_moves', { amount: `BDT ${formatMoney(bill.settled)}` })}</div>
                    )}
                </div>

                {!usable &&
                    availability?.blockers.map((blocker) => (
                        <p key={blocker.code} className="text-destructive text-sm">
                            {blocker.message}
                        </p>
                    ))}

                <div className="flex flex-wrap gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="bill_date" required>
                            {t('corr.sup.bill_date')}
                        </Label>
                        <Input
                            id="bill_date"
                            type="date"
                            className="w-auto"
                            max={today}
                            value={form.bill_date}
                            onChange={(e) => set('bill_date', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="due_date">{t('corr.sup.due_date')}</Label>
                        <Input
                            id="due_date"
                            type="date"
                            className="w-auto"
                            min={form.bill_date}
                            value={form.due_date}
                            onChange={(e) => set('due_date', e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">{t('corr.sup.due_date_help')}</p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="bill_number" required>
                            {t('corr.sup.bill_number')}
                        </Label>
                        <Input id="bill_number" className="w-60" value={form.bill_number} onChange={(e) => set('bill_number', e.target.value)} />
                    </div>
                </div>

                {form.lines.length > 0 && (
                    <div className="overflow-x-auto rounded-md border">
                        <div className="bg-muted/50 border-b p-3 text-sm font-semibold">{t('corr.sup.goods')}</div>
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left">
                                <tr>
                                    <th className="p-3 font-medium">{t('corr.sup.product')}</th>
                                    <th className="p-3 text-right font-medium">{t('corr.sup.quantity')}</th>
                                    <th className="p-3 text-right font-medium">{t('corr.sup.unit_price')}</th>
                                    <th className="p-3 text-right font-medium">{t('corr.sup.line_total')}</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {form.lines.map((line, index) => (
                                    <tr key={`${line.purchase_order_line_id}-${index}`} className="border-t align-top">
                                        <td className="p-3">
                                            <div className="font-medium">{line.product}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {[line.po_number, line.challan ? `challan ${line.challan}` : null].filter(Boolean).join(' · ')}
                                            </div>
                                        </td>
                                        <td className="p-3 text-right">
                                            <Input
                                                aria-label={`${t('corr.sup.quantity')} ${index + 1}`}
                                                className="ml-auto w-24 text-right"
                                                inputMode="decimal"
                                                value={line.quantity}
                                                onChange={(e) => setLine(index, { quantity: e.target.value })}
                                            />
                                            <div className="text-muted-foreground text-xs">
                                                {t('corr.sup.up_to', { quantity: line.max_quantity })}
                                            </div>
                                        </td>
                                        <td className="p-3 text-right">
                                            <Input
                                                aria-label={`${t('corr.sup.unit_price')} ${index + 1}`}
                                                className="ml-auto w-28 text-right"
                                                inputMode="decimal"
                                                value={line.entry_mode === 'rate' ? line.unit_cost : ''}
                                                placeholder={line.entry_mode === 'total' ? '-' : '0.00'}
                                                onChange={(e) => setLine(index, { unit_cost: e.target.value, entry_mode: 'rate' })}
                                            />
                                        </td>
                                        <td className="p-3 text-right">
                                            <Input
                                                aria-label={`${t('corr.sup.line_total')} ${index + 1}`}
                                                className="ml-auto w-32 text-right"
                                                inputMode="decimal"
                                                value={line.entry_mode === 'total' ? line.line_total : lineTotal(line).toFixed(2)}
                                                onChange={(e) => setLine(index, { line_total: e.target.value, entry_mode: 'total' })}
                                            />
                                        </td>
                                        <td className="p-3">
                                            {form.lines.length + form.services.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setForm((p) => ({ ...p, lines: p.lines.filter((_, i) => i !== index) }))}
                                                >
                                                    {t('corr.sup.remove')}
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="flex flex-col gap-3 rounded-md border p-3">
                    <div className="text-sm font-semibold">{t('corr.sup.services')}</div>
                    {form.services.map((line, index) => (
                        <div key={index} className="grid gap-2 sm:grid-cols-[1fr_1fr_8rem_auto]">
                            <SearchSelect
                                aria-label={`${t('corr.sup.expense_head')} ${index + 1}`}
                                value={line.expense_account_id}
                                onChange={(e) => setService(index, { expense_account_id: e.target.value })}
                            >
                                <option value="">{t('corr.sup.choose_head')}</option>
                                {expense_accounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <Input
                                aria-label={`${t('corr.sup.description')} ${index + 1}`}
                                placeholder={t('corr.sup.description')}
                                value={line.description}
                                onChange={(e) => setService(index, { description: e.target.value })}
                            />
                            <Input
                                aria-label={`${t('corr.sup.amount')} ${index + 1}`}
                                className="text-right"
                                inputMode="decimal"
                                value={line.amount}
                                onChange={(e) => setService(index, { amount: e.target.value })}
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setForm((p) => ({ ...p, services: p.services.filter((_, i) => i !== index) }))}
                            >
                                {t('corr.sup.remove')}
                            </Button>
                        </div>
                    ))}
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setForm((p) => ({ ...p, services: [...p.services, { expense_account_id: '', description: '', amount: '' }] }))
                            }
                        >
                            {t('corr.sup.add_service')}
                        </Button>
                        <p className="text-sm">
                            <span className="text-muted-foreground">{t('corr.sup.new_total')} </span>
                            <span className="font-semibold">BDT {formatMoney(total.toFixed(2))}</span>
                        </p>
                    </div>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="note">{t('corr.sup.note')}</Label>
                    <textarea
                        id="note"
                        className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                        value={form.note}
                        onChange={(e) => set('note', e.target.value)}
                    />
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href={`/purchasing/bills/${bill.id}`}>{t('corr.sup.go_back')}</Link>
                    </Button>
                    <ActionDialog
                        action="correct"
                        recordLabel={`Bill ${bill.bill_number}`}
                        previewUrl={`${base}/correct/preview`}
                        submitUrl={`${base}/correct`}
                        reasons={correctionReasons}
                        replacement={replacement}
                        redirectTo={`/purchasing/bills/${bill.id}`}
                        trigger={
                            <Button type="button" disabled={!usable}>
                                {t('corr.sup.preview_and_correct')}
                            </Button>
                        }
                    />
                </div>
            </div>
        </AppLayout>
    );
}
