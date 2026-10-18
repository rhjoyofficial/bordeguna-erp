import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Amount, DateCell, DocNo, MUTED_ROW } from '@/components/ledger-text';
import { AdvanceDialog, AmendmentDialog, RepaymentDialog, WriteOffDialog, type ScheduleRow } from '@/components/loans/loan-actions';
import {
    CHANGE_STATUS_LABEL,
    DAY_COUNT_LABEL,
    DIRECTION_LABEL,
    ENTRY_LABEL,
    FREQUENCY_LABEL,
    LoanStatus,
    REPAYMENT_LABEL,
    ROW_STATUS_LABEL,
    RelatedBadge,
    WATERFALL_LABEL,
    type LoanSummary,
} from '@/components/loans/loan-labels';
import RecordTimeline, { type TimelineEntry } from '@/components/record-timeline';
import { EmptyRow, ReportHeading, ReportPage, ReportTable, StatRow, StatTile, Verdict } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

interface LoanDetail extends LoanSummary {
    currency: string;
    day_count: string;
    repayment_type: string;
    frequency: string;
    grace_months: number;
    waterfall: string;
    interest_free_reason: string | null;
    collateral: string | null;
    purpose: string | null;
    heads: { principal: string | null; current_portion: string | null; accrual: string | null; profit_and_loss: string | null };
    owner_loan: boolean;
    tds_certificate_ref: string | null;
    tds_certificate_rate: string | null;
    terms_version: number;
    approved_at: string | null;
    settled_on: string | null;
    cancel_reason: string | null;
    created_by: string | null;
}

interface Entry {
    id: number;
    kind: string;
    date: string;
    journal: { id: number; number: string } | null;
    principal: string | null;
    accrued: string | null;
    interest: string | null;
    interest_pl: string | null;
    tds: string | null;
    cash: string | null;
    money_account: string | null;
    period: string | null;
    note: string | null;
    live: boolean;
    reversal: { number: string | null; reason: string | null } | null;
    reversible: boolean;
}

interface Change {
    id: number;
    status: string;
    reason: string;
    by: string | null;
    can_decide: boolean;
    self_approval: boolean;
}

interface Amendment extends Change {
    effective_from: string;
    terms: Record<string, string | number | null>;
}

interface WriteOff extends Change {
    kind: string;
    amount: string;
    forgo: string;
    effective_date: string;
    evidence: { id: number; name: string; url: string }[];
}

interface Version {
    version: number;
    effective_from: string;
    terms: Record<string, string | number | null>;
    reason: string | null;
    by: string | null;
}

/**
 * One loan (loans plan v2, phase 2): its terms, its schedule, every movement
 * with its journal, the history of its terms, its documents, and the buttons
 * that move its money. Each button is one person's action through PostJournal.
 */
export default function LoanShow({
    loan,
    position,
    schedule,
    history,
    entries,
    versions,
    amendments,
    writeOffs,
    documents,
    approval,
    tds,
    moneyAccounts,
    timeline,
    today,
    lastMonth,
    can,
}: {
    loan: LoanDetail;
    position: {
        principal_minor: number;
        accrued_minor: number;
        left_to_advance: string;
        left_to_advance_minor: number;
        max_interest_minor: number;
        ledger_principal: string;
        ledger_accrued: string;
        agrees: boolean;
    };
    schedule: ScheduleRow[];
    history: ScheduleRow[];
    entries: Entry[];
    versions: Version[];
    amendments: Amendment[];
    writeOffs: WriteOff[];
    documents: { id: number; name: string; url: string }[];
    approval: { status: string | null; note: string | null; can_decide: boolean; self_approval: boolean };
    tds: { applies: boolean; rate: string | null; rate_e4: number; rule: string | null; legal_basis: string | null; certificate: string | null };
    moneyAccounts: { id: number; label: string }[];
    timeline: TimelineEntry[];
    today: string;
    lastMonth: string;
    can: { manage: boolean; view_ledger: boolean };
}) {
    const { t } = useTranslation();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [dialog, setDialog] = useState<'advance' | 'repay' | 'amend' | 'writeoff' | null>(null);
    const [period, setPeriod] = useState(lastMonth);
    const [showHistory, setShowHistory] = useState(false);
    const [agreement, setAgreement] = useState<File | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('loans.title'), href: '/accounting/loans' },
        { title: loan.number, href: route('accounting.loans.show', loan.id) },
    ];

    const given = loan.direction === 'given';
    const live = loan.status === 'approved' || loan.status === 'active';
    const hasEntries = entries.some((entry) => entry.live);
    const options = { preserveScroll: true };

    const detail = (label: ReactNode, value: ReactNode) => (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm whitespace-pre-line">{value ?? <span className="text-muted-foreground">-</span>}</dd>
        </div>
    );

    const termsLine = (terms: Record<string, string | number | null>) =>
        [
            `${terms.annual_rate}%`,
            t(REPAYMENT_LABEL[String(terms.repayment_type)] ?? 'loans.repayment.custom'),
            t(FREQUENCY_LABEL[String(terms.frequency)] ?? 'loans.frequency.monthly'),
            t('loans.show.matures', { date: String(terms.maturity_date) }),
        ].join(', ');

    const decide = (approveUrl: string, rejectUrl: string, selfApproval: boolean, sendBack = false) => (
        <div className="flex flex-wrap gap-2">
            <ConfirmDialog
                trigger={<Button size="sm">{t('loans.approve')}</Button>}
                title={t('loans.approve_title')}
                confirmLabel={t('loans.approve')}
                withNote={selfApproval}
                noteRequired={selfApproval}
                noteLabel={t('loans.self_approval_reason')}
                onConfirm={(note) => router.post(approveUrl, note ? { note } : {}, options)}
            />
            <ConfirmDialog
                trigger={
                    <Button size="sm" variant="outline">
                        {sendBack ? t('loans.send_back') : t('loans.reject')}
                    </Button>
                }
                title={sendBack ? t('loans.send_back_title') : t('loans.reject_title')}
                confirmLabel={sendBack ? t('loans.send_back') : t('loans.reject')}
                destructive={!sendBack}
                withNote
                noteRequired={sendBack}
                noteLabel={t('loans.decision_note')}
                onConfirm={(note) => router.post(rejectUrl, { review_note: note }, options)}
            />
        </div>
    );

    const uploadAgreement = () => {
        if (agreement) {
            router.post(
                route('accounting.loans.agreement.store', loan.id),
                { file: agreement },
                { ...options, forceFormData: true, onSuccess: () => setAgreement(null) },
            );
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={loan.number} />
            <ReportPage>
                <ReportHeading
                    title={
                        <span className="flex flex-wrap items-center gap-2">
                            {loan.number}
                            <span className="text-muted-foreground font-normal">{t(DIRECTION_LABEL[loan.direction])}</span>
                            <LoanStatus status={loan.status} />
                            {loan.overdue && <Badge variant="destructive">{t('loans.overdue')}</Badge>}
                        </span>
                    }
                    description={
                        <span className="flex flex-wrap items-center gap-1">
                            {loan.counterparty.name}
                            <RelatedBadge related={loan.counterparty.related} />
                            {loan.counterparty.relationship && <span>({loan.counterparty.relationship})</span>}
                        </span>
                    }
                    actions={
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('accounting.loans.statement', loan.id)}>{t('loans.show.statement')}</Link>
                            </Button>
                            {can.manage && loan.status === 'draft' && (
                                <>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={route('accounting.loans.edit', loan.id)}>{t('loans.show.edit')}</Link>
                                    </Button>
                                    <Button size="sm" onClick={() => router.post(route('accounting.loans.submit', loan.id), {}, options)}>
                                        {t('loans.show.submit')}
                                    </Button>
                                </>
                            )}
                            {can.manage && (loan.status === 'draft' || (loan.status === 'approved' && !hasEntries)) && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline" size="sm">
                                            {t('loans.show.cancel_loan')}
                                        </Button>
                                    }
                                    title={t('loans.show.cancel_title')}
                                    confirmLabel={t('loans.show.cancel_loan')}
                                    destructive
                                    withNote
                                    noteRequired
                                    noteLabel={t('loans.show.cancel_reason')}
                                    onConfirm={(reason) => router.post(route('accounting.loans.cancel', loan.id), { reason }, options)}
                                />
                            )}
                        </>
                    }
                />

                <InputError message={errors.loan} />

                {loan.status === 'pending_approval' && (
                    <Verdict>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <span>{t('loans.show.waiting')}</span>
                            {approval.can_decide &&
                                decide(
                                    route('accounting.loans.approve', loan.id),
                                    route('accounting.loans.send-back', loan.id),
                                    approval.self_approval,
                                    true,
                                )}
                        </div>
                    </Verdict>
                )}
                {loan.status === 'draft' && approval.status === 'returned' && approval.note && (
                    <Verdict tone="bad">{t('loans.show.sent_back', { note: approval.note })}</Verdict>
                )}
                {loan.status === 'cancelled' && loan.cancel_reason && <Verdict>{t('loans.show.cancelled', { reason: loan.cancel_reason })}</Verdict>}
                {loan.owner_loan && <Verdict>{t('loans.owner_warning')}</Verdict>}

                <StatRow>
                    <StatTile
                        label={t('loans.col.principal')}
                        value={loan.principal}
                        hint={t('loans.show.left_to_advance', { amount: position.left_to_advance })}
                    />
                    <StatTile
                        label={t('loans.col.outstanding')}
                        value={loan.outstanding}
                        hint={can.view_ledger ? t('loans.show.ledger_says', { amount: position.ledger_principal }) : undefined}
                    />
                    <StatTile
                        label={t('loans.col.accrued')}
                        value={loan.accrued}
                        hint={can.view_ledger ? t('loans.show.ledger_says', { amount: position.ledger_accrued }) : undefined}
                    />
                    <StatTile
                        label={t('loans.col.next_due')}
                        value={loan.next_due ? loan.next_due.amount : '-'}
                        hint={showDate(loan.next_due?.date)}
                        tone={loan.overdue ? 'bad' : undefined}
                    />
                </StatRow>

                {loan.status !== 'draft' && loan.status !== 'pending_approval' && loan.status !== 'cancelled' && (
                    <Verdict tone={position.agrees ? 'good' : 'bad'}>{position.agrees ? t('loans.show.agrees') : t('loans.show.differs')}</Verdict>
                )}

                {can.manage && live && (
                    <div className="flex flex-wrap gap-2 print:hidden">
                        {position.left_to_advance_minor > 0 && (
                            <Button size="sm" onClick={() => setDialog('advance')}>
                                {given ? t('loans.advance.disburse') : t('loans.advance.receive')}
                            </Button>
                        )}
                        {loan.status === 'active' && (
                            <>
                                <Button size="sm" onClick={() => setDialog('repay')}>
                                    {given ? t('loans.repay.received_title') : t('loans.repay.paid_title')}
                                </Button>
                                <span className="flex items-center gap-1">
                                    <Input
                                        type="month"
                                        aria-label={t('loans.accrue.month')}
                                        className="w-auto"
                                        value={period}
                                        onChange={(e) => setPeriod(e.target.value)}
                                    />
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => router.post(route('accounting.loans.accrue', loan.id), { period }, options)}
                                    >
                                        {t('loans.accrue.one')}
                                    </Button>
                                </span>
                                <Button size="sm" variant="outline" onClick={() => setDialog('amend')}>
                                    {t('loans.amend.title')}
                                </Button>
                                <Button size="sm" variant="outline" onClick={() => setDialog('writeoff')}>
                                    {t('loans.writeoff.title')}
                                </Button>
                            </>
                        )}
                        <InputError message={errors.period} />
                    </div>
                )}

                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">{t('loans.show.terms')}</h2>
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {detail(
                            t('loans.form.rate'),
                            loan.rate === '0' ? t('loans.show.interest_free', { reason: loan.interest_free_reason ?? '' }) : `${loan.rate}%`,
                        )}
                        {detail(t('loans.form.day_count'), t(DAY_COUNT_LABEL[loan.day_count]))}
                        {detail(t('loans.form.repayment_type'), t(REPAYMENT_LABEL[loan.repayment_type]))}
                        {detail(t('loans.form.frequency'), t(FREQUENCY_LABEL[loan.frequency]))}
                        {detail(t('loans.form.start_date'), <DateCell>{showDate(loan.start_date)}</DateCell>)}
                        {detail(t('loans.col.maturity'), <DateCell>{showDate(loan.maturity_date)}</DateCell>)}
                        {detail(t('loans.form.grace_months'), String(loan.grace_months))}
                        {detail(t('loans.form.waterfall'), t(WATERFALL_LABEL[loan.waterfall]))}
                        {detail(t('loans.form.principal_head'), loan.heads.principal)}
                        {loan.heads.current_portion && detail(t('loans.show.current_portion_head'), loan.heads.current_portion)}
                        {detail(t('loans.show.accrual_head'), loan.heads.accrual)}
                        {detail(t('loans.show.pl_head'), loan.heads.profit_and_loss)}
                        {loan.direction === 'taken' &&
                            detail(
                                t('loans.show.tds'),
                                tds.applies
                                    ? t('loans.show.tds_applies', { rate: tds.rate ?? '', basis: tds.legal_basis ?? '' }) +
                                          (tds.certificate ? ` (${tds.certificate})` : '')
                                    : t('loans.show.tds_off'),
                            )}
                        {detail(t('loans.show.version'), String(loan.terms_version))}
                        {detail(t('loans.form.collateral'), loan.collateral)}
                        {detail(t('loans.form.purpose'), loan.purpose)}
                        {detail(t('loans.show.drafted_by'), loan.created_by)}
                        {loan.settled_on && detail(t('loans.show.settled_on'), <DateCell>{showDate(loan.settled_on)}</DateCell>)}
                    </dl>
                </section>

                <section className="grid gap-2">
                    <h2 className="text-base font-semibold">{t('loans.show.schedule')}</h2>
                    <ScheduleTable rows={schedule} />
                    {history.length > 0 && (
                        <div className="grid gap-2">
                            <Button variant="ghost" size="sm" className="justify-self-start" onClick={() => setShowHistory(!showHistory)}>
                                {showHistory ? t('loans.show.hide_history') : t('loans.show.show_history', { count: history.length })}
                            </Button>
                            {showHistory && <ScheduleTable rows={history} showVersion />}
                        </div>
                    )}
                </section>

                <section className="grid gap-2">
                    <h2 className="text-base font-semibold">{t('loans.show.movements')}</h2>
                    <ReportTable
                        minWidth="64rem"
                        head={
                            <>
                                <th className="py-2">{t('loans.money.date')}</th>
                                <th className="py-2">{t('loans.show.what')}</th>
                                <th className="py-2">{t('loans.show.journal')}</th>
                                <th className="py-2 text-right">{t('loans.col.principal')}</th>
                                <th className="py-2 text-right">{t('loans.col.interest')}</th>
                                <th className="py-2 text-right">{t('loans.show.tds_col')}</th>
                                <th className="py-2 text-right">{t('loans.show.cash')}</th>
                                <th className="py-2">{t('loans.show.account_note')}</th>
                                <th className="py-2" />
                            </>
                        }
                    >
                        {entries.map((entry) => (
                            <tr
                                key={entry.id}
                                className={`border-sidebar-border/70 dark:border-sidebar-border border-b align-top ${entry.live ? '' : MUTED_ROW}`}
                            >
                                <td className="py-2">
                                    <DateCell>{showDate(entry.date)}</DateCell>
                                </td>
                                <td className="py-2">
                                    {t(ENTRY_LABEL[entry.kind])}
                                    {entry.period && <span className="text-muted-foreground"> ({entry.period})</span>}
                                    {!entry.live && (
                                        <div className="text-xs text-red-700 dark:text-red-400">
                                            {t('loans.show.reversed', { reason: entry.reversal?.reason ?? '' })}
                                        </div>
                                    )}
                                </td>
                                <td className="py-2">
                                    {entry.journal && (
                                        <DocNo href={can.view_ledger ? `/accounting/journals/${entry.journal.id}` : null}>
                                            {entry.journal.number}
                                        </DocNo>
                                    )}
                                </td>
                                <td className="py-2 text-right">
                                    <Amount value={entry.principal} tone="balance" />
                                </td>
                                <td className="py-2 text-right">
                                    <Amount value={entry.interest ?? entry.accrued ?? entry.interest_pl} tone="balance" />
                                </td>
                                <td className="py-2 text-right">
                                    <Amount value={entry.tds} />
                                </td>
                                <td className="py-2 text-right">
                                    <Amount value={entry.cash} />
                                </td>
                                <td className="text-muted-foreground py-2">
                                    {entry.money_account}
                                    {entry.note && <div className="text-xs">{entry.note}</div>}
                                </td>
                                <td className="py-2 text-right">
                                    {can.manage && entry.reversible && (
                                        <ConfirmDialog
                                            trigger={
                                                <Button variant="ghost" size="sm">
                                                    {t('loans.show.reverse')}
                                                </Button>
                                            }
                                            title={t('loans.show.reverse_title')}
                                            description={t('loans.show.reverse_hint')}
                                            confirmLabel={t('loans.show.reverse')}
                                            destructive
                                            withNote
                                            noteRequired
                                            noteLabel={t('loans.show.reverse_reason')}
                                            onConfirm={(reason) =>
                                                router.post(route('accounting.loans.entries.reverse', entry.id), { reason }, options)
                                            }
                                        />
                                    )}
                                </td>
                            </tr>
                        ))}
                        {entries.length === 0 && <EmptyRow colSpan={9}>{t('loans.show.no_movements')}</EmptyRow>}
                    </ReportTable>
                    <InputError message={errors.entry ?? errors.reason} />
                </section>

                {(amendments.length > 0 || writeOffs.length > 0 || versions.length > 1) && (
                    <section className="grid gap-3">
                        <h2 className="text-base font-semibold">{t('loans.show.changes')}</h2>
                        <ul className="grid gap-3">
                            {versions.map((version) => (
                                <li
                                    key={`v${version.version}`}
                                    className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border p-3 text-sm"
                                >
                                    <div className="font-medium">
                                        {t('loans.show.version_line', { version: version.version, date: version.effective_from })}
                                    </div>
                                    <div>{termsLine(version.terms)}</div>
                                    {version.reason && <div className="text-muted-foreground">{version.reason}</div>}
                                </li>
                            ))}
                            {amendments.map((amendment) => (
                                <li
                                    key={`a${amendment.id}`}
                                    className="border-sidebar-border/70 dark:border-sidebar-border grid gap-2 rounded-md border p-3 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2 font-medium">
                                        {t('loans.amend.title')} ({t('loans.amend.effective_on', { date: amendment.effective_from })})
                                        <Badge variant="outline">{t(CHANGE_STATUS_LABEL[amendment.status])}</Badge>
                                    </div>
                                    <div>{termsLine(amendment.terms)}</div>
                                    <div className="text-muted-foreground">
                                        {amendment.reason} {amendment.by && `(${amendment.by})`}
                                    </div>
                                    {amendment.can_decide &&
                                        decide(
                                            route('accounting.loans.amendments.approve', amendment.id),
                                            route('accounting.loans.amendments.reject', amendment.id),
                                            amendment.self_approval,
                                        )}
                                </li>
                            ))}
                            {writeOffs.map((writeOff) => (
                                <li
                                    key={`w${writeOff.id}`}
                                    className="border-sidebar-border/70 dark:border-sidebar-border grid gap-2 rounded-md border p-3 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2 font-medium">
                                        {writeOff.kind === 'principal_write_off' ? t('loans.writeoff.principal') : t('loans.writeoff.interest')}
                                        <Badge variant="outline">{t(CHANGE_STATUS_LABEL[writeOff.status])}</Badge>
                                    </div>
                                    <div className="tabular-nums">
                                        {writeOff.amount}
                                        {writeOff.forgo !== '0.00' && ` + ${t('loans.writeoff.forgone', { amount: writeOff.forgo })}`}{' '}
                                        <DateCell>{showDate(writeOff.effective_date)}</DateCell>
                                    </div>
                                    <div className="text-muted-foreground">
                                        {writeOff.reason} {writeOff.by && `(${writeOff.by})`}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {writeOff.evidence.map((file) => (
                                            <a key={file.id} href={file.url} target="_blank" rel="noreferrer" className="text-primary underline">
                                                {file.name}
                                            </a>
                                        ))}
                                    </div>
                                    {writeOff.can_decide &&
                                        decide(
                                            route('accounting.loans.write-offs.approve', writeOff.id),
                                            route('accounting.loans.write-offs.reject', writeOff.id),
                                            writeOff.self_approval,
                                        )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="grid gap-2">
                    <h2 className="text-base font-semibold">{t('loans.show.documents')}</h2>
                    {documents.length === 0 && <p className="text-muted-foreground text-sm">{t('loans.show.no_documents')}</p>}
                    <ul className="grid gap-1 text-sm">
                        {documents.map((file) => (
                            <li key={file.id} className="flex flex-wrap items-center gap-2">
                                <a href={file.url} target="_blank" rel="noreferrer" className="text-primary underline">
                                    {file.name}
                                </a>
                                {can.manage && loan.status === 'draft' && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.delete(route('accounting.loans.agreement.destroy', [loan.id, file.id]), options)}
                                    >
                                        {t('loans.show.remove')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                    {can.manage && loan.status !== 'cancelled' && (
                        <div className="flex flex-wrap items-center gap-2 print:hidden">
                            <Input
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.webp"
                                className="w-auto max-w-full"
                                aria-label={t('loans.show.agreement')}
                                onChange={(e) => setAgreement(e.target.files?.[0] ?? null)}
                            />
                            <Button size="sm" variant="outline" disabled={!agreement} onClick={uploadAgreement}>
                                {t('loans.show.attach')}
                            </Button>
                            <InputError message={errors.file} />
                        </div>
                    )}
                </section>

                <section className="grid gap-2">
                    <h2 className="text-base font-semibold">{t('loans.show.history')}</h2>
                    <RecordTimeline entries={timeline} />
                </section>
            </ReportPage>

            {can.manage && (
                <>
                    <AdvanceDialog
                        loanId={loan.id}
                        direction={loan.direction}
                        open={dialog === 'advance'}
                        onOpenChange={(open) => setDialog(open ? 'advance' : null)}
                        moneyAccounts={moneyAccounts}
                        leftMinor={position.left_to_advance_minor}
                        today={today}
                    />
                    <RepaymentDialog
                        loanId={loan.id}
                        direction={loan.direction}
                        waterfall={loan.waterfall}
                        open={dialog === 'repay'}
                        onOpenChange={(open) => setDialog(open ? 'repay' : null)}
                        moneyAccounts={moneyAccounts}
                        schedule={schedule}
                        outstandingMinor={position.principal_minor}
                        tdsRateE4={tds.applies ? tds.rate_e4 : 0}
                        today={today}
                    />
                    <AmendmentDialog
                        loanId={loan.id}
                        open={dialog === 'amend'}
                        onOpenChange={(open) => setDialog(open ? 'amend' : null)}
                        current={{
                            rate: loan.rate,
                            repayment_type: loan.repayment_type,
                            frequency: loan.frequency,
                            maturity_date: loan.maturity_date,
                            waterfall: loan.waterfall,
                            day_count: loan.day_count,
                            grace_months: loan.grace_months,
                        }}
                        today={today}
                    />
                    <WriteOffDialog
                        loanId={loan.id}
                        direction={loan.direction}
                        open={dialog === 'writeoff'}
                        onOpenChange={(open) => setDialog(open ? 'writeoff' : null)}
                        today={today}
                    />
                </>
            )}
        </AppLayout>
    );
}

function ScheduleTable({ rows, showVersion = false }: { rows: ScheduleRow[]; showVersion?: boolean }) {
    const { t } = useTranslation();

    return (
        <ReportTable
            minWidth="52rem"
            head={
                <>
                    {showVersion && <th className="py-2">{t('loans.show.version')}</th>}
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
            {rows.map((row) => (
                <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                    {showVersion && <td className="py-2">{row.version}</td>}
                    <td className="py-2 tabular-nums">{row.sequence}</td>
                    <td className="py-2">
                        <DateCell>{showDate(row.due_date)}</DateCell>
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
                    <td className="py-2">
                        <span className={row.status === 'overdue' ? 'font-medium text-red-700 dark:text-red-400' : 'text-muted-foreground'}>
                            {t(ROW_STATUS_LABEL[row.status])}
                            {row.status === 'overdue' && ` (${t('loans.show.days_late', { days: row.days_late })})`}
                        </span>
                    </td>
                </tr>
            ))}
            {rows.length === 0 && <EmptyRow colSpan={showVersion ? 8 : 7}>{t('loans.show.no_schedule')}</EmptyRow>}
        </ReportTable>
    );
}
