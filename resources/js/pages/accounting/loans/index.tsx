import { Field } from '@/components/form/field';
import { Amount, DateCell, DocNo } from '@/components/ledger-text';
import { DIRECTION_LABEL, LoanStatus, RelatedBadge, STATUS_LABEL, type LoanSummary } from '@/components/loans/loan-labels';
import { EmptyRow, FilterBar, ReportHeading, ReportPage, ReportTable, StatRow, StatTile } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Filters {
    direction: string;
    status: string;
    counterparty_id: string;
    related: string;
}

const STATUS_FILTERS = ['open', 'all', 'draft', 'pending_approval', 'approved', 'active', 'overdue', 'settled', 'written_off', 'cancelled'];

/**
 * Accounting > Loans (loans plan v2, phase 2): the Loan Register. Every loan
 * given and taken, what is owed on each, and the next date money moves.
 */
export default function LoansIndex({
    filters,
    loans,
    totals,
    counterparties,
    canManage,
}: {
    filters: Filters;
    loans: LoanSummary[];
    totals: { given: string; taken: string };
    counterparties: { id: number; label: string }[];
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const [form, setForm] = useState<Filters>(filters);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('loans.accounting'), href: '/accounting/journals' },
        { title: t('loans.title'), href: '/accounting/loans' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.loans.index'), { ...form }, { preserveState: true });
    };

    const statusLabel = (status: string) =>
        status === 'open'
            ? t('loans.filter.open')
            : status === 'all'
              ? t('loans.filter.all')
              : status === 'overdue'
                ? t('loans.overdue')
                : t(STATUS_LABEL[status]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('loans.title')} />
            <ReportPage>
                <ReportHeading
                    title={t('loans.title')}
                    description={t('loans.description')}
                    actions={
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('accounting.loans.reports')}>{t('loans.reports.nav')}</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('accounting.loans.accruals')}>{t('loans.accrue.nav')}</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('accounting.loans.tds-rules')}>{t('loans.tds.nav')}</Link>
                            </Button>
                            {canManage && (
                                <Button size="sm" asChild>
                                    <Link href={route('accounting.loans.create')}>
                                        <Plus className="size-4" />
                                        {t('loans.new')}
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <StatRow columns={2}>
                    <StatTile label={t('loans.totals.given')} value={totals.given} hint={t('loans.totals.given_hint')} />
                    <StatTile label={t('loans.totals.taken')} value={totals.taken} hint={t('loans.totals.taken_hint')} />
                </StatRow>

                <FilterBar onSubmit={submit} label={t('loans.show_button')}>
                    <Field label={t('loans.col.direction')} htmlFor="direction">
                        <SearchSelect id="direction" value={form.direction} onChange={(e) => setForm({ ...form, direction: e.target.value })}>
                            <option value="">{t('loans.filter.both')}</option>
                            <option value="given">{t('loans.direction.given')}</option>
                            <option value="taken">{t('loans.direction.taken')}</option>
                        </SearchSelect>
                    </Field>
                    <Field label={t('loans.col.status')} htmlFor="status">
                        <SearchSelect id="status" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                            {STATUS_FILTERS.map((status) => (
                                <option key={status} value={status}>
                                    {statusLabel(status)}
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>
                    <Field label={t('loans.col.counterparty')} htmlFor="counterparty_id">
                        <SearchSelect
                            id="counterparty_id"
                            value={form.counterparty_id}
                            onChange={(e) => setForm({ ...form, counterparty_id: e.target.value })}
                        >
                            <option value="">{t('loans.filter.everyone')}</option>
                            {counterparties.map((c) => (
                                <option key={c.id} value={String(c.id)}>
                                    {c.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>
                    <Field label={t('loans.related')} htmlFor="related">
                        <SearchSelect id="related" value={form.related} onChange={(e) => setForm({ ...form, related: e.target.value })}>
                            <option value="">{t('loans.filter.both')}</option>
                            <option value="yes">{t('loans.filter.related_only')}</option>
                            <option value="no">{t('loans.filter.unrelated_only')}</option>
                        </SearchSelect>
                    </Field>
                </FilterBar>

                <ReportTable
                    minWidth="64rem"
                    head={
                        <>
                            <th className="py-2">{t('loans.col.number')}</th>
                            <th className="py-2">{t('loans.col.counterparty')}</th>
                            <th className="py-2">{t('loans.col.direction')}</th>
                            <th className="py-2 text-right">{t('loans.col.principal')}</th>
                            <th className="py-2 text-right">{t('loans.col.outstanding')}</th>
                            <th className="py-2 text-right">{t('loans.col.accrued')}</th>
                            <th className="py-2 text-right">{t('loans.col.rate')}</th>
                            <th className="py-2">{t('loans.col.maturity')}</th>
                            <th className="py-2">{t('loans.col.next_due')}</th>
                            <th className="py-2">{t('loans.col.status')}</th>
                        </>
                    }
                >
                    {loans.map((loan) => (
                        <tr key={loan.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                            <td className="py-2">
                                <DocNo href={route('accounting.loans.show', loan.id)}>{loan.number}</DocNo>
                            </td>
                            <td className="py-2">
                                <div className="flex flex-wrap items-center gap-1">
                                    <span>{loan.counterparty.name}</span>
                                    <RelatedBadge related={loan.counterparty.related} />
                                </div>
                            </td>
                            <td className="text-muted-foreground py-2">{t(DIRECTION_LABEL[loan.direction])}</td>
                            <td className="py-2 text-right">
                                <Amount value={loan.principal} />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={loan.outstanding} tone="balance" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={loan.accrued} tone="balance" />
                            </td>
                            <td className="py-2 text-right tabular-nums">{loan.rate}%</td>
                            <td className="py-2">
                                <DateCell>{showDate(loan.maturity_date)}</DateCell>
                            </td>
                            <td className="py-2">
                                {loan.next_due ? (
                                    <span className="whitespace-nowrap">
                                        <DateCell>{showDate(loan.next_due.date)}</DateCell> <Amount value={loan.next_due.amount} />
                                    </span>
                                ) : null}
                            </td>
                            <td className="py-2">
                                <div className="flex flex-wrap gap-1">
                                    <LoanStatus status={loan.status} />
                                    {loan.overdue && <Badge variant="destructive">{t('loans.overdue')}</Badge>}
                                </div>
                            </td>
                        </tr>
                    ))}
                    {loans.length === 0 && <EmptyRow colSpan={10}>{t('loans.empty')}</EmptyRow>}
                </ReportTable>
            </ReportPage>
        </AppLayout>
    );
}
