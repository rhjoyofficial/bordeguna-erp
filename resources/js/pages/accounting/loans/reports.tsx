import { Field } from '@/components/form/field';
import { Amount, DateCell, DocNo } from '@/components/ledger-text';
import { DIRECTION_LABEL, RelatedBadge, type LoanDirection } from '@/components/loans/loan-labels';
import { EmptyRow, FilterBar, Footnote, ReportHeading, ReportPage, ReportTable, Verdict } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState, type ReactNode } from 'react';

interface LoanCell {
    id: number;
    number: string;
    direction: LoanDirection;
    counterparty: string | null;
    counterparty_id: number;
    related: boolean;
}

type Row = Record<string, string | number | boolean | null> & { loan: LoanCell };

type Data = {
    rows?: Row[];
    totals?: Record<string, string>;
    reclass_counterparties?: number[];
    agrees?: boolean;
    loans?: Row[];
    heads?: Record<string, string | boolean | null>[];
};

const REPORTS: { key: string; label: TranslationKey; range: boolean }[] = [
    { key: 'receivable', label: 'loans.reports.receivable', range: false },
    { key: 'payable', label: 'loans.reports.payable', range: false },
    { key: 'maturity', label: 'loans.reports.maturity', range: false },
    { key: 'overdue', label: 'loans.reports.overdue', range: false },
    { key: 'related', label: 'loans.reports.related', range: false },
    { key: 'interest', label: 'loans.reports.interest', range: true },
    { key: 'tds', label: 'loans.reports.tds', range: true },
    { key: 'reconciliation', label: 'loans.reports.reconciliation', range: false },
];

/**
 * Accounting > Loans > Reports (loans plan v2, phase 2): the registers with
 * their current and non-current parts and the reclassification helper,
 * maturity, overdue, related parties, interest, TDS, and the register held
 * against the ledger. Every figure arrives formatted from the server.
 */
export default function LoanReports({
    report,
    filters,
    data,
    canPostJournal,
}: {
    report: string;
    filters: { as_at: string; from: string; to: string };
    data: Data;
    canPostJournal: boolean;
}) {
    const { t } = useTranslation();
    const [form, setForm] = useState(filters);
    const current = REPORTS.find((r) => r.key === report) ?? REPORTS[0];

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('loans.title'), href: '/accounting/loans' },
        { title: t('loans.reports.nav'), href: '/accounting/loans/reports' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.loans.reports'), { report, ...form }, { preserveState: true });
    };

    const loanCell = (loan: LoanCell) => (
        <div className="flex flex-wrap items-center gap-1">
            <DocNo href={route('accounting.loans.show', loan.id)}>{loan.number}</DocNo>
            <span>{loan.counterparty}</span>
            <RelatedBadge related={loan.related} />
        </div>
    );

    const money = (value: unknown, tone: 'amount' | 'balance' | 'total' = 'amount') => <Amount value={value as string | null} tone={tone} />;
    const th = (label: TranslationKey, right = false) => <th className={cn('py-2', right && 'text-right')}>{t(label)}</th>;
    const rows = data.rows ?? [];

    let body: ReactNode = null;

    if (report === 'receivable' || report === 'payable') {
        const reclass = data.reclass_counterparties ?? [];

        body = (
            <>
                {canPostJournal && reclass.length > 0 && (
                    <Verdict>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span>{t('loans.reports.reclass_needed')}</span>
                            <Button size="sm" asChild>
                                <Link href={`/accounting/journals/new/manual?reclass_as_at=${filters.as_at}`}>
                                    {t('loans.reports.prepare_reclass')}
                                </Link>
                            </Button>
                        </div>
                    </Verdict>
                )}
                <ReportTable
                    minWidth="64rem"
                    head={
                        <>
                            {th('loans.col.number')}
                            {th('loans.form.principal_head')}
                            {th('loans.col.outstanding', true)}
                            {th('loans.reports.current', true)}
                            {th('loans.reports.non_current', true)}
                            {th('loans.reports.held_current', true)}
                            {th('loans.reports.move', true)}
                            {th('loans.col.accrued', true)}
                        </>
                    }
                    foot={
                        <tr className="font-semibold">
                            <td className="py-2" colSpan={2}>
                                {t('loans.total')}
                            </td>
                            <td className="py-2 text-right">{money(data.totals?.outstanding, 'total')}</td>
                            <td className="py-2 text-right">{money(data.totals?.current, 'total')}</td>
                            <td className="py-2 text-right">{money(data.totals?.non_current, 'total')}</td>
                            <td />
                            <td />
                            <td className="py-2 text-right">{money(data.totals?.accrued, 'total')}</td>
                        </tr>
                    }
                >
                    {rows.map((row) => (
                        <tr key={row.loan.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="py-2">{loanCell(row.loan)}</td>
                            <td className="text-muted-foreground py-2">{row.principal_head}</td>
                            <td className="py-2 text-right">{money(row.outstanding)}</td>
                            <td className="py-2 text-right">{money(row.current)}</td>
                            <td className="py-2 text-right">{money(row.non_current)}</td>
                            <td className="py-2 text-right">{money(row.held_current)}</td>
                            <td className="py-2 text-right">
                                {row.move && canPostJournal ? (
                                    <Link
                                        href={`/accounting/journals/new/manual?reclass_as_at=${filters.as_at}&counterparty_id=${row.loan.counterparty_id}`}
                                        className="underline"
                                    >
                                        {money(row.move, 'balance')}
                                    </Link>
                                ) : (
                                    money(row.move, 'balance')
                                )}
                            </td>
                            <td className="py-2 text-right">{money(row.accrued)}</td>
                        </tr>
                    ))}
                    {rows.length === 0 && <EmptyRow colSpan={8}>{t('loans.reports.none')}</EmptyRow>}
                </ReportTable>
                <Footnote>{t('loans.reports.reclass_footnote')}</Footnote>
            </>
        );
    } else if (report === 'maturity') {
        body = (
            <ReportTable
                minWidth="56rem"
                head={
                    <>
                        {th('loans.col.number')}
                        {th('loans.col.direction')}
                        {th('loans.col.outstanding', true)}
                        {th('loans.reports.overdue_col', true)}
                        {th('loans.reports.within_12', true)}
                        {th('loans.reports.later', true)}
                        {th('loans.reports.unscheduled', true)}
                    </>
                }
                foot={
                    <tr className="font-semibold">
                        <td className="py-2" colSpan={2}>
                            {t('loans.total')}
                        </td>
                        <td className="py-2 text-right">{money(data.totals?.outstanding, 'total')}</td>
                        <td className="py-2 text-right">{money(data.totals?.overdue, 'total')}</td>
                        <td className="py-2 text-right">{money(data.totals?.within_12_months, 'total')}</td>
                        <td className="py-2 text-right">{money(data.totals?.later, 'total')}</td>
                        <td />
                    </tr>
                }
            >
                {rows.map((row) => (
                    <tr key={row.loan.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                        <td className="py-2">{loanCell(row.loan)}</td>
                        <td className="text-muted-foreground py-2">{t(DIRECTION_LABEL[row.loan.direction])}</td>
                        <td className="py-2 text-right">{money(row.outstanding)}</td>
                        <td className="py-2 text-right">{money(row.overdue)}</td>
                        <td className="py-2 text-right">{money(row.within_12_months)}</td>
                        <td className="py-2 text-right">{money(row.later)}</td>
                        <td className="py-2 text-right">{money(row.unscheduled, 'balance')}</td>
                    </tr>
                ))}
                {rows.length === 0 && <EmptyRow colSpan={7}>{t('loans.reports.none')}</EmptyRow>}
            </ReportTable>
        );
    } else if (report === 'overdue') {
        body = (
            <ReportTable
                minWidth="48rem"
                head={
                    <>
                        {th('loans.col.number')}
                        {th('loans.col.direction')}
                        {th('loans.col.due_date')}
                        {th('loans.reports.days_late', true)}
                        {th('loans.col.principal', true)}
                        {th('loans.col.interest', true)}
                    </>
                }
            >
                {rows.map((row, index) => (
                    <tr key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                        <td className="py-2">{loanCell(row.loan)}</td>
                        <td className="text-muted-foreground py-2">{t(DIRECTION_LABEL[row.loan.direction])}</td>
                        <td className="py-2">
                            <DateCell>{String(row.due_date)}</DateCell>
                        </td>
                        <td className="py-2 text-right tabular-nums">{String(row.days_late)}</td>
                        <td className="py-2 text-right">{money(row.principal)}</td>
                        <td className="py-2 text-right">{money(row.interest)}</td>
                    </tr>
                ))}
                {rows.length === 0 && <EmptyRow colSpan={6}>{t('loans.reports.nothing_overdue')}</EmptyRow>}
            </ReportTable>
        );
    } else if (report === 'related') {
        body = (
            <ReportTable
                minWidth="60rem"
                head={
                    <>
                        {th('loans.col.number')}
                        {th('loans.col.direction')}
                        {th('loans.reports.relationship')}
                        {th('loans.col.rate', true)}
                        {th('loans.col.maturity')}
                        {th('loans.col.outstanding', true)}
                        {th('loans.col.accrued', true)}
                    </>
                }
            >
                {rows.map((row) => (
                    <tr key={row.loan.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                        <td className="py-2">{loanCell(row.loan)}</td>
                        <td className="text-muted-foreground py-2">{t(DIRECTION_LABEL[row.loan.direction])}</td>
                        <td className="py-2">{row.relationship}</td>
                        <td className="py-2 text-right tabular-nums">
                            {row.rate}%{row.interest_free_reason && <div className="text-muted-foreground text-xs">{row.interest_free_reason}</div>}
                        </td>
                        <td className="py-2">
                            <DateCell>{String(row.maturity_date)}</DateCell>
                        </td>
                        <td className="py-2 text-right">{money(row.outstanding)}</td>
                        <td className="py-2 text-right">{money(row.accrued)}</td>
                    </tr>
                ))}
                {rows.length === 0 && <EmptyRow colSpan={7}>{t('loans.reports.none')}</EmptyRow>}
            </ReportTable>
        );
    } else if (report === 'interest') {
        body = (
            <>
                <div className="flex flex-wrap gap-6 text-sm">
                    <span>
                        {t('loans.reports.earned')}: <Amount value={data.totals?.earned} tone="total" />
                    </span>
                    <span>
                        {t('loans.reports.paid')}: <Amount value={data.totals?.paid} tone="total" />
                    </span>
                </div>
                <ReportTable
                    minWidth="60rem"
                    head={
                        <>
                            {th('loans.col.number')}
                            {th('loans.col.direction')}
                            {th('loans.reports.accrued_col', true)}
                            {th('loans.reports.direct', true)}
                            {th('loans.reports.waived', true)}
                            {th('loans.reports.in_pl', true)}
                            {th('loans.reports.settled', true)}
                            {th('loans.show.tds_col', true)}
                        </>
                    }
                >
                    {rows.map((row) => (
                        <tr key={row.loan.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="py-2">{loanCell(row.loan)}</td>
                            <td className="text-muted-foreground py-2">{t(DIRECTION_LABEL[row.loan.direction])}</td>
                            <td className="py-2 text-right">{money(row.accrued)}</td>
                            <td className="py-2 text-right">{money(row.direct)}</td>
                            <td className="py-2 text-right">{money(row.waived, 'balance')}</td>
                            <td className="py-2 text-right">{money(row.total, 'total')}</td>
                            <td className="py-2 text-right">{money(row.settled)}</td>
                            <td className="py-2 text-right">{money(row.tds)}</td>
                        </tr>
                    ))}
                    {rows.length === 0 && <EmptyRow colSpan={8}>{t('loans.reports.none')}</EmptyRow>}
                </ReportTable>
            </>
        );
    } else if (report === 'tds') {
        body = (
            <>
                <div className="flex flex-wrap gap-6 text-sm">
                    <span>
                        {t('loans.reports.withheld_by_us')}: <Amount value={data.totals?.withheld_by_us} tone="total" />
                    </span>
                    <span>
                        {t('loans.reports.withheld_from_us')}: <Amount value={data.totals?.withheld_from_us} tone="total" />
                    </span>
                </div>
                <ReportTable
                    minWidth="56rem"
                    head={
                        <>
                            {th('loans.money.date')}
                            {th('loans.col.number')}
                            {th('loans.show.journal')}
                            {th('loans.repay.interest_gross', true)}
                            {th('loans.reports.rate', true)}
                            {th('loans.show.tds_col', true)}
                            {th('loans.reports.certificate')}
                        </>
                    }
                >
                    {rows.map((row, index) => (
                        <tr key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="py-2">
                                <DateCell>{String(row.date)}</DateCell>
                            </td>
                            <td className="py-2">{loanCell(row.loan)}</td>
                            <td className="py-2">
                                {row.journal && <DocNo href={`/accounting/journals/${row.journal_id}`}>{String(row.journal)}</DocNo>}
                            </td>
                            <td className="py-2 text-right">{money(row.gross)}</td>
                            <td className="py-2 text-right tabular-nums">{row.rate ? `${row.rate}%` : ''}</td>
                            <td className="py-2 text-right">{money(row.tds)}</td>
                            <td className="text-muted-foreground py-2">{row.certificate}</td>
                        </tr>
                    ))}
                    {rows.length === 0 && <EmptyRow colSpan={7}>{t('loans.reports.none')}</EmptyRow>}
                </ReportTable>
            </>
        );
    } else if (report === 'reconciliation') {
        const loans = data.loans ?? [];
        const heads = data.heads ?? [];

        body = (
            <>
                <Verdict tone={data.agrees ? 'good' : 'bad'}>
                    {data.agrees ? t('loans.reports.reconciled') : t('loans.reports.not_reconciled')}
                </Verdict>
                <h2 className="text-base font-semibold">{t('loans.reports.by_head')}</h2>
                <ReportTable
                    minWidth="52rem"
                    head={
                        <>
                            {th('loans.reports.head')}
                            {th('loans.reports.register', true)}
                            {th('loans.reports.ledger', true)}
                            {th('loans.reports.untagged', true)}
                            {th('loans.reports.difference', true)}
                            <th className="py-2" />
                        </>
                    }
                >
                    {heads.map((head, index) => (
                        <tr key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="py-2">{head.account}</td>
                            <td className="py-2 text-right">{money(head.register)}</td>
                            <td className="py-2 text-right">{money(head.ledger)}</td>
                            <td className="py-2 text-right">{money(head.untagged)}</td>
                            <td className="py-2 text-right">{money(head.difference, 'balance')}</td>
                            <td className="py-2">
                                {head.agrees ? (
                                    <span className="text-emerald-700 dark:text-emerald-400">{t('loans.reports.agrees')}</span>
                                ) : (
                                    <span className="text-destructive font-medium">{t('loans.reports.differs')}</span>
                                )}
                            </td>
                        </tr>
                    ))}
                    {heads.length === 0 && <EmptyRow colSpan={6}>{t('loans.reports.none')}</EmptyRow>}
                </ReportTable>
                <h2 className="text-base font-semibold">{t('loans.reports.by_loan')}</h2>
                <ReportTable
                    minWidth="56rem"
                    head={
                        <>
                            {th('loans.col.number')}
                            {th('loans.reports.register_principal', true)}
                            {th('loans.reports.ledger_principal', true)}
                            {th('loans.reports.register_accrued', true)}
                            {th('loans.reports.ledger_accrued', true)}
                            <th className="py-2" />
                        </>
                    }
                >
                    {loans.map((row) => (
                        <tr key={row.loan.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="py-2">{loanCell(row.loan)}</td>
                            <td className="py-2 text-right">{money(row.register_principal)}</td>
                            <td className="py-2 text-right">{money(row.ledger_principal)}</td>
                            <td className="py-2 text-right">{money(row.register_accrued)}</td>
                            <td className="py-2 text-right">{money(row.ledger_accrued)}</td>
                            <td className="py-2">
                                {row.agrees ? (
                                    <span className="text-emerald-700 dark:text-emerald-400">{t('loans.reports.agrees')}</span>
                                ) : (
                                    <span className="text-destructive font-medium">{t('loans.reports.differs')}</span>
                                )}
                            </td>
                        </tr>
                    ))}
                </ReportTable>
                <Footnote>{t('loans.reports.reconciliation_footnote')}</Footnote>
            </>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('loans.reports.nav')}: ${t(current.label)}`} />
            <ReportPage>
                <ReportHeading title={t(current.label)} description={t('loans.reports.description')} />

                <nav className="flex flex-wrap gap-1 print:hidden" aria-label={t('loans.reports.nav')}>
                    {REPORTS.map((item) => (
                        <Button key={item.key} size="sm" variant={item.key === report ? 'default' : 'outline'} asChild>
                            <Link href={route('accounting.loans.reports', { report: item.key, ...filters })}>{t(item.label)}</Link>
                        </Button>
                    ))}
                </nav>

                <FilterBar onSubmit={submit} label={t('loans.show_button')}>
                    {current.range ? (
                        <>
                            <Field label={t('loans.reports.from')} htmlFor="from">
                                <Input id="from" type="date" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
                            </Field>
                            <Field label={t('loans.reports.to')} htmlFor="to">
                                <Input id="to" type="date" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} />
                            </Field>
                        </>
                    ) : (
                        <Field label={t('loans.reports.as_at')} htmlFor="as_at">
                            <Input id="as_at" type="date" value={form.as_at} onChange={(e) => setForm({ ...form, as_at: e.target.value })} />
                        </Field>
                    )}
                </FilterBar>

                {body}
            </ReportPage>
        </AppLayout>
    );
}
