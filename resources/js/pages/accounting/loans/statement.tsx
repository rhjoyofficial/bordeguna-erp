import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Amount, DateCell, DocNo, LedgerHead } from '@/components/ledger-text';
import { type ScheduleRow } from '@/components/loans/loan-actions';
import { DIRECTION_LABEL, FREQUENCY_LABEL, REPAYMENT_LABEL, ROW_STATUS_LABEL, type LoanSummary } from '@/components/loans/loan-labels';
import { EmptyRow, ReportPage, ReportTable } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Line {
    date: string;
    journal_id: number;
    journal_number: string;
    memo: string | null;
    debit: string | null;
    credit: string | null;
    balance: string;
}

interface HeadLedger {
    account: string | null;
    lines: Line[];
    closing: string;
}

/**
 * One loan's statement (loans plan v2, phase 2): its terms, its schedule and
 * every ledger line carrying its id, head by head with a running balance, on
 * the letterhead, printed from the browser.
 */
export default function LoanStatement({
    loan,
    heads,
    schedule,
    issuer,
    today,
}: {
    loan: LoanSummary & { address: string | null; frequency: string; repayment_type: string };
    heads: HeadLedger[];
    schedule: ScheduleRow[];
    issuer: Issuer;
    today: string;
}) {
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('loans.title'), href: '/accounting/loans' },
        { title: loan.number, href: route('accounting.loans.show', loan.id) },
        { title: t('loans.show.statement'), href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('loans.show.statement')}: ${loan.number}`} />
            <ReportPage>
                <div className="flex flex-wrap justify-end gap-2 print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('accounting.loans.show', loan.id)}>{t('loans.statement.back')}</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        {t('loans.statement.print')}
                    </Button>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto grid w-full max-w-5xl gap-6 rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:rounded-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title={t('loans.statement.title')} subtitle={t('loans.statement.as_at', { date: today })} />

                    <div className="grid gap-1 text-sm sm:grid-cols-2">
                        <div>
                            <p className="font-semibold">{loan.counterparty.name}</p>
                            {loan.address && <p className="whitespace-pre-line">{loan.address}</p>}
                        </div>
                        <div className="sm:text-right">
                            <p className="font-semibold">
                                {loan.number} ({t(DIRECTION_LABEL[loan.direction])})
                            </p>
                            <p>
                                {t('loans.col.principal')} {loan.principal}, {loan.rate}%, {t(REPAYMENT_LABEL[loan.repayment_type])},{' '}
                                {t(FREQUENCY_LABEL[loan.frequency])}
                            </p>
                            <p>
                                <DateCell>{loan.start_date}</DateCell> - <DateCell>{loan.maturity_date}</DateCell>
                            </p>
                            <p className="font-semibold">
                                {t('loans.col.outstanding')} {loan.outstanding}; {t('loans.col.accrued')} {loan.accrued}
                            </p>
                        </div>
                    </div>

                    {heads.length === 0 && <p className="py-6 text-center text-sm">{t('loans.statement.nothing')}</p>}

                    {heads.map((head) => (
                        <section key={head.account ?? 'head'} className="grid break-inside-avoid gap-2">
                            <h2 className="text-base">{head.account && <LedgerHead label={head.account} />}</h2>
                            <ReportTable
                                minWidth="44rem"
                                head={
                                    <>
                                        <th className="py-2">{t('loans.money.date')}</th>
                                        <th className="py-2">{t('loans.show.journal')}</th>
                                        <th className="py-2">{t('loans.statement.particulars')}</th>
                                        <th className="py-2 text-right">{t('loans.statement.debit')}</th>
                                        <th className="py-2 text-right">{t('loans.statement.credit')}</th>
                                        <th className="py-2 text-right">{t('loans.statement.balance')}</th>
                                    </>
                                }
                                foot={
                                    <tr className="font-bold">
                                        <td className="py-2" colSpan={5}>
                                            {t('loans.statement.closing')}
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={head.closing} tone="total" />
                                        </td>
                                    </tr>
                                }
                            >
                                {head.lines.map((line, index) => (
                                    <tr key={index} className="border-sidebar-border/70 border-b">
                                        <td className="py-2">
                                            <DateCell>{line.date}</DateCell>
                                        </td>
                                        <td className="py-2">
                                            <DocNo href={`/accounting/journals/${line.journal_id}`} className="text-xs">
                                                {line.journal_number}
                                            </DocNo>
                                        </td>
                                        <td className="py-2">{line.memo}</td>
                                        <td className="py-2 text-right">
                                            <Amount value={line.debit} tone="debit" />
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={line.credit} tone="credit" />
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={line.balance} tone="balance" />
                                        </td>
                                    </tr>
                                ))}
                                {head.lines.length === 0 && <EmptyRow colSpan={6}>{t('loans.statement.nothing')}</EmptyRow>}
                            </ReportTable>
                        </section>
                    ))}

                    <section className="grid break-inside-avoid gap-2">
                        <h2 className="text-base font-semibold">{t('loans.show.schedule')}</h2>
                        <ReportTable
                            minWidth="40rem"
                            head={
                                <>
                                    <th className="py-2">#</th>
                                    <th className="py-2">{t('loans.col.due_date')}</th>
                                    <th className="py-2 text-right">{t('loans.show.principal_due')}</th>
                                    <th className="py-2 text-right">{t('loans.show.interest_due')}</th>
                                    <th className="py-2 text-right">{t('loans.show.principal_paid')}</th>
                                    <th className="py-2 text-right">{t('loans.show.interest_paid')}</th>
                                    <th className="py-2">{t('loans.col.status')}</th>
                                </>
                            }
                        >
                            {schedule.map((row) => (
                                <tr key={row.id} className="border-sidebar-border/70 border-b">
                                    <td className="py-2 tabular-nums">{row.sequence}</td>
                                    <td className="py-2">
                                        <DateCell>{row.due_date}</DateCell>
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={row.principal_due} />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={row.interest_due} />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={row.principal_paid} />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={row.interest_paid} />
                                    </td>
                                    <td className="py-2">{t(ROW_STATUS_LABEL[row.status])}</td>
                                </tr>
                            ))}
                        </ReportTable>
                    </section>
                </div>
            </ReportPage>
        </AppLayout>
    );
}
