import { ActionDialog } from '@/components/action-dialog';
import { CorrectAllocations, type AllocationRow } from '@/components/purchasing/correct-allocations';
import { CorrectionAvailability } from '@/components/purchasing/correction-actions';
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

interface Props {
    payment: {
        id: number;
        paid_on: string;
        money_account_id: string;
        reference: string;
        note: string;
        tds_rate_id: number | null;
        vds_rate_id: number | null;
        amount: string;
        supplier: string | null;
    };
    bills: AllocationRow[];
    money_accounts: { id: number; name: string }[];
    availability: CorrectionAvailability | null;
    correctionReasons: { id: number; name: string }[];
    type: string;
    today: string;
}

/**
 * Correct a supplier payment (corrections wave 1, 20 Oct 2026, FINAL-PLAN 3.3:
 * date, amount, account, which bills). The date comes first (Joy, 19 Oct).
 * Nothing is saved here: "Preview and correct" opens the action dialog, which
 * reverses the payment on its own date and records the right one in one go.
 * ⚠ No keyboard shortcuts and no Enter-to-submit.
 */
export default function CorrectPayment({ payment, bills, money_accounts, availability, correctionReasons, type, today }: Props) {
    const { t } = useTranslation();
    const number = `Payment #${payment.id}`;
    const [form, setForm] = useState({
        paid_on: payment.paid_on,
        money_account_id: payment.money_account_id,
        reference: payment.reference,
        note: payment.note,
        rows: bills,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Bills & payments', href: '/purchasing/payments' },
        { title: number, href: `/purchasing/payments/${payment.id}` },
        { title: t('corr.sup.correct'), href: '#' },
    ];

    const set = (field: 'paid_on' | 'money_account_id' | 'reference' | 'note', value: string) => setForm((p) => ({ ...p, [field]: value }));
    const usable = !!availability && (availability.allowed || availability.requestable);
    const base = `/records/${type}/${payment.id}`;

    const replacement = useMemo(
        () => ({
            paid_on: form.paid_on,
            money_account_id: form.money_account_id,
            reference: form.reference,
            note: form.note,
            tds_rate_id: payment.tds_rate_id,
            vds_rate_id: payment.vds_rate_id,
            allocations: form.rows
                .filter((row) => (Number.parseFloat(row.amount) || 0) > 0)
                .map((row) => ({ supplier_bill_id: row.id, amount: row.amount })),
        }),
        [form, payment.tds_rate_id, payment.vds_rate_id],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('corr.sup.correct')} ${number}`} />

            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <ReportHeading title={`${t('corr.sup.correct')} ${number}`} />

                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                    {t('corr.sup.banner', { number, amount: `BDT ${formatMoney(payment.amount)}` })}
                    {availability?.date?.sentence ? ` ${availability.date.sentence}` : ''}
                </div>

                {!usable &&
                    availability?.blockers.map((blocker) => (
                        <p key={blocker.code} className="text-destructive text-sm">
                            {blocker.message}
                        </p>
                    ))}

                <div className="flex flex-wrap gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="paid_on" required>
                            {t('corr.sup.paid_on')}
                        </Label>
                        <Input
                            id="paid_on"
                            type="date"
                            className="w-auto"
                            max={today}
                            value={form.paid_on}
                            onChange={(e) => set('paid_on', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="money_account_id" required>
                            {t('corr.sup.paid_from')}
                        </Label>
                        <SearchSelect id="money_account_id" value={form.money_account_id} onChange={(e) => set('money_account_id', e.target.value)}>
                            <option value="">{t('corr.sup.choose_account')}</option>
                            {money_accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reference">{t('corr.sup.reference')}</Label>
                        <Input id="reference" className="w-60" value={form.reference} onChange={(e) => set('reference', e.target.value)} />
                    </div>
                </div>

                <CorrectAllocations
                    title={t('corr.sup.bills_paid')}
                    rows={form.rows}
                    onChange={(index, amount) => setForm((p) => ({ ...p, rows: p.rows.map((row, i) => (i === index ? { ...row, amount } : row)) }))}
                />

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
                        <Link href={`/purchasing/payments/${payment.id}`}>{t('corr.sup.go_back')}</Link>
                    </Button>
                    <ActionDialog
                        action="correct"
                        recordLabel={number}
                        previewUrl={`${base}/correct/preview`}
                        submitUrl={`${base}/correct`}
                        reasons={correctionReasons}
                        replacement={replacement}
                        redirectTo={`/purchasing/payments/${payment.id}`}
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
