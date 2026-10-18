import { Field } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * The authority's side of the Staff Product Benefit, Phase 2 (17 Oct 2026):
 * approve (which reserves the FIFO batch and freezes its cost as the price),
 * reject, cancel, hand over at the frozen price, and replace a defect at no
 * charge. Three tabs: Requests, Monthly statement (the tax evidence, D8) and
 * Audit (every step, who, when, which price).
 *
 * ⭐ D3: the cost basis behind a price (batch, unit cost) is drawn only when
 * the server says this person may see it; the approver and the store see the
 * approved price.
 */
interface CostBasis {
    batch: string | null;
    unit_cost: string | null;
    basis: string | null;
    frozen_at: string | null;
    batches: { batch_id: number; batch_number: string | null; unit_cost: string; quantity: string }[];
}

interface LineRow {
    id: number;
    product: string | null;
    staff_price: number;
    is_estimate: boolean;
    priced_at: string;
    reference_price: number;
    reference_label: string;
    replaced: boolean;
    cost_basis: CostBasis | null;
}

interface RequestRow {
    id: number;
    number: string;
    employee: string | null;
    employee_code: string | null;
    payment_mode: string;
    status: string;
    decision_note: string | null;
    reapproval_reason: string | null;
    applied_at: string;
    total_staff_price: number;
    sales_invoice_id: number | null;
    lines: LineRow[];
}

interface StatementLine {
    number: string;
    date: string | null;
    product: string | null;
    quantity: number;
    priced_at: string;
    reference_label: string | null;
    reference_price: number;
    staff_price: number;
    difference: number;
    payment_mode: string;
}

interface StatementRow {
    employee: string | null;
    employee_code: string | null;
    lines: StatementLine[];
    reference_total: number;
    staff_total: number;
    difference: number;
    settled_payroll: number;
    settled_cash: number;
    outstanding: number;
}

interface AuditRow {
    id: number;
    at: string | null;
    action: string;
    actor: string | null;
    number: string | null;
    employee: string | null;
    details: Record<string, unknown> | null;
    /** P17 (18 Oct 2026): the details as labelled parts, built on the server. */
    summary?: { key: string; value: string; href?: string }[];
}

interface StaffPurchasesProps {
    tab: 'requests' | 'statement' | 'audit';
    month: string;
    requests: RequestRow[];
    moneyAccounts: { id: number; name: string; type: string }[];
    canSeeCostBasis: boolean;
    statement?: StatementRow[];
    audit?: AuditRow[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Staff purchases', href: '/hrm/staff-purchases' }];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });

const statusKey: Record<string, TranslationKey> = {
    pending: 'staff_purchase.status_pending',
    approved: 'staff_purchase.status_approved',
    needs_reapproval: 'staff_purchase.status_needs_reapproval',
    fulfilled: 'staff_purchase.status_fulfilled',
    rejected: 'staff_purchase.status_rejected',
    cancelled: 'staff_purchase.status_cancelled',
};

// P17 (18 Oct 2026, practice run): the words in front of each audit detail.
const detailKey: Record<string, TranslationKey> = {
    product: 'staff_purchases.detail_product',
    price: 'staff_purchases.detail_price',
    was: 'staff_purchases.detail_was',
    reference: 'staff_purchases.detail_reference',
    quantity: 'staff_purchases.detail_quantity',
    total: 'staff_purchases.detail_total',
    amount: 'staff_purchases.detail_amount',
    cost: 'staff_purchases.detail_cost',
    batch: 'staff_purchases.detail_batch',
    invoice: 'staff_purchases.detail_invoice',
    warehouse: 'staff_purchases.detail_warehouse',
    payment_mode: 'staff_purchases.detail_payment',
    payroll: 'staff_purchases.detail_payroll',
    reason: 'staff_purchases.detail_reason',
    evidence: 'staff_purchases.detail_evidence',
};

const actionKey: Record<string, TranslationKey> = {
    applied: 'staff_purchases.action_applied',
    approved: 'staff_purchases.action_approved',
    rejected: 'staff_purchases.action_rejected',
    cancelled: 'staff_purchases.action_cancelled',
    needs_reapproval: 'staff_purchases.action_needs_reapproval',
    handed_over: 'staff_purchases.action_handed_over',
    payroll_offset: 'staff_purchases.action_payroll_offset',
    payroll_offset_withdrawn: 'staff_purchases.action_payroll_offset_withdrawn',
    replaced: 'staff_purchases.action_replaced',
};

function ReplaceDialog({ row, open, onOpenChange }: { row: RequestRow; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({ line_id: '', reason: '', evidence_note: '' });

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/hrm/staff-purchases/${row.id}/replace`, { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{t('staff_purchases.replace_title')}</DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">{t('staff_purchases.replace_hint')}</p>

                    <Field label={t('staff_purchases.replace_line')} htmlFor={`replace-line-${row.id}`} error={errors.line_id} required>
                        <Select value={data.line_id} onValueChange={(value) => setData('line_id', value)}>
                            <SelectTrigger id={`replace-line-${row.id}`} aria-required>
                                <SelectValue placeholder="..." />
                            </SelectTrigger>
                            <SelectContent>
                                {row.lines
                                    .filter((line) => !line.replaced)
                                    .map((line) => (
                                        <SelectItem key={line.id} value={String(line.id)}>
                                            {line.product}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label={t('staff_purchases.replace_reason')} htmlFor={`replace-reason-${row.id}`} error={errors.reason} required>
                        <Input
                            id={`replace-reason-${row.id}`}
                            aria-required
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                        />
                    </Field>
                    <Field label={t('staff_purchases.replace_evidence')} htmlFor={`replace-evidence-${row.id}`} error={errors.evidence_note} required>
                        <Input
                            id={`replace-evidence-${row.id}`}
                            aria-required
                            value={data.evidence_note}
                            onChange={(e) => setData('evidence_note', e.target.value)}
                        />
                    </Field>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            {t('popups.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || data.line_id === '' || data.reason.trim() === '' || data.evidence_note.trim() === ''}
                        >
                            {t('staff_purchases.replace_confirm')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RequestCard({
    row,
    moneyAccounts,
    canSeeCostBasis,
}: {
    row: RequestRow;
    moneyAccounts: StaffPurchasesProps['moneyAccounts'];
    canSeeCostBasis: boolean;
}) {
    const { t } = useTranslation();
    const [rejectNote, setRejectNote] = useState('');
    const [cancelNote, setCancelNote] = useState('');
    const [account, setAccount] = useState('');
    const [replacing, setReplacing] = useState(false);
    // HRM-003 (14 Oct 2026): one hand-over per press. The server refuses a
    // second one under a row lock; this keeps a double click from asking.
    const [handingOver, setHandingOver] = useState(false);
    const needsAccount = row.payment_mode !== 'salary_deduction';
    const decidable = row.status === 'pending' || row.status === 'needs_reapproval';
    const cancellable = row.status === 'approved' || row.status === 'needs_reapproval';

    return (
        <div className="border-border bg-card space-y-2 rounded-lg border p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">
                    {row.number} · {row.employee} ({row.employee_code})
                    <span className="text-muted-foreground font-normal"> · {formatDate(row.applied_at)}</span>
                </p>
                <span className={cn('text-xs', row.status === 'needs_reapproval' ? 'font-medium text-amber-700' : 'text-muted-foreground')}>
                    {statusKey[row.status] ? t(statusKey[row.status]) : row.status} · {row.payment_mode.replace('_', ' ')}
                </span>
            </div>

            <ul className="text-muted-foreground space-y-0.5">
                {row.lines.map((line) => (
                    <li key={line.id}>
                        {line.product}: <strong className="text-foreground tabular-nums">{money(line.staff_price)}</strong>
                        {line.is_estimate && <span className="ml-1 text-xs italic">({t('staff_purchase.estimate')})</span>}
                        {line.priced_at === 'reference' && <span className="ml-1 text-xs">({t('staff_purchase.priced_at_reference')})</span>}
                        <span className="ml-1 text-xs">
                            · {line.reference_label === 'mrp' ? t('staff_purchase.reference_mrp') : t('staff_purchase.reference_market')}{' '}
                            {money(line.reference_price)}
                        </span>
                        {line.replaced && <span className="ml-1 text-xs text-emerald-700">· {t('staff_purchases.replaced')}</span>}
                        {canSeeCostBasis && line.cost_basis && (
                            <span className="ml-1 text-xs" title={t('staff_purchases.cost_basis_hint')}>
                                · {t('staff_purchases.cost_basis')}: {t('staff_purchases.batch')} {line.cost_basis.batch ?? '?'},{' '}
                                {t('staff_purchases.unit_cost')} {line.cost_basis.unit_cost ?? '?'}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            <p>
                = <strong className="tabular-nums">{money(row.total_staff_price)}</strong>
            </p>

            {row.status === 'needs_reapproval' && row.reapproval_reason && (
                <p className="rounded-md border border-amber-500/40 bg-amber-500/5 px-2 py-1 text-xs text-amber-800">
                    {t('staff_purchases.reapproval_reason')}: {row.reapproval_reason}
                </p>
            )}
            {row.decision_note && <p className="text-destructive text-xs">{row.decision_note}</p>}

            {decidable && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        className="bg-emerald-600 text-white hover:bg-emerald-700"
                        title={t('staff_purchases.approve_hint')}
                        onClick={() => router.post(`/hrm/staff-purchases/${row.id}/approve`, {}, { preserveScroll: true })}
                    >
                        {t('staff_purchases.approve')}
                    </Button>
                    <Input
                        className="h-8 w-56"
                        placeholder={t('staff_purchases.reason_if_rejecting')}
                        aria-label={t('staff_purchases.reason_if_rejecting')}
                        aria-required
                        value={rejectNote}
                        onChange={(e) => setRejectNote(e.target.value)}
                    />
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive"
                        disabled={rejectNote.trim() === ''}
                        onClick={() => router.post(`/hrm/staff-purchases/${row.id}/reject`, { decision_note: rejectNote }, { preserveScroll: true })}
                    >
                        {t('staff_purchases.reject')}
                    </Button>
                </div>
            )}

            {row.status === 'approved' && (
                <div className="flex flex-wrap items-center gap-2">
                    {needsAccount && (
                        <Select value={account} onValueChange={setAccount}>
                            <SelectTrigger className="h-8 w-48" aria-label={t('staff_purchases.paid_into')} aria-required>
                                <SelectValue placeholder={t('staff_purchases.paid_into')} />
                            </SelectTrigger>
                            <SelectContent>
                                {moneyAccounts.map((item) => (
                                    <SelectItem key={item.id} value={String(item.id)}>
                                        {item.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <Button
                        size="sm"
                        disabled={handingOver || (needsAccount && account === '')}
                        onClick={() =>
                            router.post(`/hrm/staff-purchases/${row.id}/fulfill`, needsAccount ? { money_account_id: account } : {}, {
                                preserveScroll: true,
                                onStart: () => setHandingOver(true),
                                onFinish: () => setHandingOver(false),
                            })
                        }
                    >
                        {t('staff_purchases.hand_over')}
                    </Button>
                    {!needsAccount && <span className="text-muted-foreground text-xs">{t('staff_purchases.salary_note')}</span>}
                </div>
            )}

            {cancellable && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        className="h-8 w-56"
                        placeholder={t('staff_purchases.cancel_reason')}
                        aria-label={t('staff_purchases.cancel_reason')}
                        value={cancelNote}
                        onChange={(e) => setCancelNote(e.target.value)}
                    />
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={cancelNote.trim() === ''}
                        onClick={() => router.post(`/hrm/staff-purchases/${row.id}/cancel`, { decision_note: cancelNote }, { preserveScroll: true })}
                    >
                        {t('staff_purchases.cancel')}
                    </Button>
                </div>
            )}

            {row.status === 'fulfilled' && (
                <div className="flex flex-wrap items-center gap-2">
                    {row.sales_invoice_id && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/sales/invoices/${row.sales_invoice_id}`}>{t('staff_purchases.open_sale')}</Link>
                        </Button>
                    )}
                    {row.lines.some((line) => !line.replaced) && (
                        <Button variant="ghost" size="sm" onClick={() => setReplacing(true)}>
                            {t('staff_purchases.replace')}
                        </Button>
                    )}
                    <ReplaceDialog row={row} open={replacing} onOpenChange={setReplacing} />
                </div>
            )}
        </div>
    );
}

function Statement({ rows }: { rows: StatementRow[] }) {
    const { t } = useTranslation();

    if (rows.length === 0) {
        return <p className="text-muted-foreground text-sm">{t('staff_purchases.statement_none')}</p>;
    }

    return (
        <div className="space-y-4">
            <p className="text-muted-foreground text-sm">{t('staff_purchases.statement_hint')}</p>
            {rows.map((row) => (
                <div key={row.employee_code ?? row.employee ?? ''} className="border-border bg-card rounded-lg border p-3 text-sm">
                    <p className="font-medium">
                        {row.employee} ({row.employee_code})
                    </p>
                    <table className="mt-2 w-full text-xs">
                        <thead className="text-muted-foreground">
                            <tr>
                                <th className="text-left font-normal">{t('staff_purchases.col_date')}</th>
                                <th className="text-left font-normal">{t('staff_purchases.col_item')}</th>
                                <th className="text-right font-normal">{t('staff_purchases.col_reference')}</th>
                                <th className="text-right font-normal">{t('staff_purchases.col_price')}</th>
                                <th className="text-right font-normal">{t('staff_purchases.col_difference')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {row.lines.map((line, index) => (
                                <tr key={`${line.number}-${index}`}>
                                    <td>{line.date ? formatDate(line.date) : ''}</td>
                                    <td>
                                        {line.product} <span className="text-muted-foreground">({line.number})</span>
                                    </td>
                                    <td className="text-right tabular-nums">{money(line.reference_price)}</td>
                                    <td className="text-right tabular-nums">{money(line.staff_price)}</td>
                                    <td className="text-right tabular-nums">{money(line.difference)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="font-medium">
                            <tr>
                                <td colSpan={2}>{t('reports.total')}</td>
                                <td className="text-right tabular-nums">{money(row.reference_total)}</td>
                                <td className="text-right tabular-nums">{money(row.staff_total)}</td>
                                <td className="text-right tabular-nums">{money(row.difference)}</td>
                            </tr>
                        </tfoot>
                    </table>
                    <p className="text-muted-foreground mt-2 text-xs">
                        {t('staff_purchases.settled_payroll')} {money(row.settled_payroll)} · {t('staff_purchases.settled_cash')}{' '}
                        {money(row.settled_cash)} · {t('staff_purchases.outstanding')}{' '}
                        <strong className="text-foreground">{money(row.outstanding)}</strong>
                    </p>
                </div>
            ))}
        </div>
    );
}

function Audit({ rows }: { rows: AuditRow[] }) {
    const { t } = useTranslation();

    if (rows.length === 0) {
        return <p className="text-muted-foreground text-sm">{t('staff_purchases.audit_none')}</p>;
    }

    return (
        <div className="space-y-2">
            <p className="text-muted-foreground text-sm">{t('staff_purchases.audit_hint')}</p>
            <table className="w-full text-xs">
                <thead className="text-muted-foreground">
                    <tr>
                        <th className="text-left font-normal">{t('staff_purchases.col_when')}</th>
                        <th className="text-left font-normal">{t('staff_purchases.col_action')}</th>
                        <th className="text-left font-normal">{t('staff_purchases.col_who')}</th>
                        <th className="text-left font-normal">{t('staff_purchases.col_details')}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="border-border border-t align-top">
                            <td className="py-1 whitespace-nowrap">{row.at}</td>
                            <td className="py-1">
                                {actionKey[row.action] ? t(actionKey[row.action]) : row.action}
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {row.number} · {row.employee}
                                </span>
                            </td>
                            <td className="py-1">{row.actor}</td>
                            <td className="text-muted-foreground py-1" data-testid="audit-details">
                                {(row.summary ?? []).map((part, index) => (
                                    <span key={index}>
                                        {index > 0 ? ' · ' : ''}
                                        {detailKey[part.key] ? t(detailKey[part.key]) : part.key}{' '}
                                        {part.href ? (
                                            <Link href={part.href} className="text-foreground underline">
                                                {part.value}
                                            </Link>
                                        ) : (
                                            <span className="text-foreground">
                                                {part.key === 'payment_mode' ? part.value.replace(/_/g, ' ') : part.value}
                                            </span>
                                        )}
                                    </span>
                                ))}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function StaffPurchases({ tab, month, requests, moneyAccounts, canSeeCostBasis, statement, audit }: StaffPurchasesProps) {
    const { t } = useTranslation();
    const tabs: { key: StaffPurchasesProps['tab']; label: string }[] = [
        { key: 'requests', label: t('staff_purchases.tab_requests') },
        { key: 'statement', label: t('staff_purchases.tab_statement') },
        { key: 'audit', label: t('staff_purchases.tab_audit') },
    ];
    const go = (next: StaffPurchasesProps['tab'], nextMonth = month) =>
        router.get(
            '/hrm/staff-purchases',
            { tab: next, month: nextMonth },
            { preserveState: true, preserveScroll: true, only: [next, 'tab', 'month'] },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('staff_purchases.title')} />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">{t('staff_purchases.title')}</h1>
                    <div className="flex items-center gap-3">
                        <nav className="flex gap-1 rounded-md border p-0.5" aria-label={t('staff_purchases.title')}>
                            {tabs.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    aria-current={tab === item.key ? 'page' : undefined}
                                    className={cn(
                                        'rounded px-3 py-1 text-sm',
                                        tab === item.key ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                    onClick={() => go(item.key)}
                                >
                                    {item.label}
                                </button>
                            ))}
                        </nav>
                        {tab !== 'requests' && (
                            <label className="text-muted-foreground flex items-center gap-2 text-xs">
                                {t('staff_purchases.month')}
                                <Input type="month" className="h-8 w-40" value={month} onChange={(e) => go(tab, e.target.value)} />
                            </label>
                        )}
                    </div>
                </div>

                {tab === 'requests' &&
                    (requests.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('staff_purchases.none')}</p>
                    ) : (
                        requests.map((row) => <RequestCard key={row.id} row={row} moneyAccounts={moneyAccounts} canSeeCostBasis={canSeeCostBasis} />)
                    ))}
                {tab === 'statement' && <Statement rows={statement ?? []} />}
                {tab === 'audit' && <Audit rows={audit ?? []} />}
            </div>
        </AppLayout>
    );
}
