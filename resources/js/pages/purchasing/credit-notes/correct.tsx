import { ActionDialog } from '@/components/action-dialog';
import { CorrectAllocations, type AllocationRow } from '@/components/purchasing/correct-allocations';
import { CorrectionAvailability } from '@/components/purchasing/supplier-money-corrections';
import { ReportHeading } from '@/components/report/report-shell';
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
    creditNote: {
        id: number;
        credit_note_number: string;
        credit_date: string;
        note: string;
        tax_amount: string;
        total: string;
        supplier: string | null;
        purchase_return_id: number;
    };
    bills: AllocationRow[];
    availability: CorrectionAvailability | null;
    correctionReasons: { id: number; name: string }[];
    type: string;
    today: string;
}

/**
 * Correct a supplier credit note (corrections wave 1, 20 Oct 2026): its date
 * (first, Joy 19 Oct), number, amount and which bills it reduces, for the same
 * return. Nothing is saved here: the action dialog reverses the note on its
 * own date and records the right one. ⚠ No shortcuts, no Enter-to-submit.
 */
export default function CorrectCreditNote({ creditNote, bills, availability, correctionReasons, type, today }: Props) {
    const { t } = useTranslation();
    const number = `Credit note ${creditNote.credit_note_number}`;
    const [form, setForm] = useState({
        credit_date: creditNote.credit_date,
        credit_note_number: creditNote.credit_note_number,
        note: creditNote.note,
        rows: bills,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Credit notes', href: '/purchasing/credit-notes' },
        { title: creditNote.credit_note_number, href: `/purchasing/credit-notes/${creditNote.id}` },
        { title: t('corr.sup.correct'), href: '#' },
    ];

    const set = (field: 'credit_date' | 'credit_note_number' | 'note', value: string) => setForm((p) => ({ ...p, [field]: value }));
    const usable = !!availability && (availability.allowed || availability.requestable);
    const base = `/records/${type}/${creditNote.id}`;

    const replacement = useMemo(
        () => ({
            credit_date: form.credit_date,
            credit_note_number: form.credit_note_number,
            note: form.note,
            tax_amount: creditNote.tax_amount,
            allocations: form.rows
                .filter((row) => (Number.parseFloat(row.amount) || 0) > 0)
                .map((row) => ({ supplier_bill_id: row.id, amount: row.amount })),
        }),
        [form, creditNote.tax_amount],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('corr.sup.correct')} ${number}`} />

            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <ReportHeading title={`${t('corr.sup.correct')} ${number}`} />

                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                    {t('corr.sup.banner', { number, amount: `BDT ${formatMoney(creditNote.total)}` })}
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
                        <Label htmlFor="credit_date" required>
                            {t('corr.sup.credit_date')}
                        </Label>
                        <Input
                            id="credit_date"
                            type="date"
                            className="w-auto"
                            max={today}
                            value={form.credit_date}
                            onChange={(e) => set('credit_date', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="credit_note_number" required>
                            {t('corr.sup.credit_number')}
                        </Label>
                        <Input
                            id="credit_note_number"
                            className="w-60"
                            value={form.credit_note_number}
                            onChange={(e) => set('credit_note_number', e.target.value)}
                        />
                    </div>
                </div>

                <CorrectAllocations
                    title={t('corr.sup.bills_credited')}
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
                        <Link href={`/purchasing/credit-notes/${creditNote.id}`}>{t('corr.sup.go_back')}</Link>
                    </Button>
                    <ActionDialog
                        action="correct"
                        recordLabel={number}
                        previewUrl={`${base}/correct/preview`}
                        submitUrl={`${base}/correct`}
                        reasons={correctionReasons}
                        replacement={replacement}
                        redirectTo={`/purchasing/credit-notes/${creditNote.id}`}
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
