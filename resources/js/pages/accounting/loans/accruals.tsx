import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field } from '@/components/form/field';
import { Amount, DateCell, DocNo } from '@/components/ledger-text';
import { DIRECTION_LABEL, type LoanSummary } from '@/components/loans/loan-labels';
import { EmptyRow, FilterBar, Footnote, ReportHeading, ReportPage, ReportTable } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Row {
    loan: LoanSummary;
    computed: string;
    accrued: string;
    difference: string;
    difference_minor: number;
    date: string | null;
    note: string | null;
}

/**
 * Accounting > Loans > Accrue interest (loans plan v2, phase 2): what each
 * active interest-bearing loan earned or cost in a month, what is already
 * booked, and the difference that posting would book. Nothing is posted until
 * somebody presses the button; a second press books nothing new.
 */
export default function LoanAccruals({
    period,
    monthLabel,
    rows,
    total,
    canManage,
}: {
    period: string;
    monthLabel: string;
    rows: Row[];
    total: string;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const [month, setMonth] = useState(period);
    const toPost = rows.filter((row) => row.difference_minor !== 0).length;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('loans.title'), href: '/accounting/loans' },
        { title: t('loans.accrue.nav'), href: '/accounting/loans/accruals' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.loans.accruals'), { period: month }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('loans.accrue.nav')} />
            <ReportPage>
                <ReportHeading
                    title={t('loans.accrue.title', { month: monthLabel })}
                    description={t('loans.accrue.description')}
                    actions={
                        canManage &&
                        toPost > 0 && (
                            <ConfirmDialog
                                trigger={<Button size="sm">{t('loans.accrue.post_all', { count: toPost })}</Button>}
                                title={t('loans.accrue.confirm_title', { month: monthLabel })}
                                description={t('loans.accrue.confirm_hint', { amount: total })}
                                confirmLabel={t('loans.accrue.post')}
                                onConfirm={() => router.post(route('accounting.loans.accruals.store'), { period }, { preserveScroll: true })}
                            />
                        )
                    }
                />

                <FilterBar onSubmit={submit} label={t('loans.show_button')}>
                    <Field label={t('loans.accrue.month')} htmlFor="month">
                        <Input id="month" type="month" className="w-auto" value={month} onChange={(e) => setMonth(e.target.value)} />
                    </Field>
                </FilterBar>

                <ReportTable
                    minWidth="52rem"
                    head={
                        <>
                            <th className="py-2">{t('loans.col.number')}</th>
                            <th className="py-2">{t('loans.col.counterparty')}</th>
                            <th className="py-2">{t('loans.col.direction')}</th>
                            <th className="py-2 text-right">{t('loans.accrue.computed')}</th>
                            <th className="py-2 text-right">{t('loans.accrue.already')}</th>
                            <th className="py-2 text-right">{t('loans.accrue.to_post')}</th>
                            <th className="py-2">{t('loans.accrue.posts_on')}</th>
                        </>
                    }
                    foot={
                        <tr className="font-semibold">
                            <td className="py-2" colSpan={5}>
                                {t('loans.total')}
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={total} tone="total" />
                            </td>
                            <td />
                        </tr>
                    }
                >
                    {rows.map((row) => (
                        <tr key={row.loan.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                            <td className="py-2">
                                <DocNo href={route('accounting.loans.show', row.loan.id)}>{row.loan.number}</DocNo>
                            </td>
                            <td className="py-2">{row.loan.counterparty.name}</td>
                            <td className="text-muted-foreground py-2">{t(DIRECTION_LABEL[row.loan.direction])}</td>
                            <td className="py-2 text-right">
                                <Amount value={row.computed} />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.accrued} />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.difference} tone="balance" />
                            </td>
                            <td className="text-muted-foreground py-2">
                                {row.date && <DateCell>{row.date}</DateCell>}
                                {row.note && <div className="text-xs">{row.note}</div>}
                            </td>
                        </tr>
                    ))}
                    {rows.length === 0 && <EmptyRow colSpan={7}>{t('loans.accrue.none')}</EmptyRow>}
                </ReportTable>

                <Footnote>{t('loans.accrue.footnote')}</Footnote>
            </ReportPage>
        </AppLayout>
    );
}
