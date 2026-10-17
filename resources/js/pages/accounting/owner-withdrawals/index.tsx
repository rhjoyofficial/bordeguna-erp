import {
    OwnerWithdrawalDialog,
    type DialogWithdrawal,
    type ProductOption,
    type WarehouseOption,
} from '@/components/accounting/owner-withdrawal-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { cn, formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface LineRow {
    id: number;
    product_id: number;
    product: string | null;
    sku: string | null;
    unit: string | null;
    quantity: string;
    batch_id: number | null;
    batch_number: string | null;
    unit_cost: string | null;
    cost: string;
    reference_source: string | null;
    reference_price: string | null;
    reference: string | null;
    difference: string | null;
    note: string | null;
    estimate: {
        unit_cost: string | null;
        cost: string;
        short: string;
        batches: number;
        reference_source: string;
        reference_price: string | null;
    } | null;
}

interface WithdrawalRow {
    id: number;
    number: string;
    status: 'draft' | 'approved' | 'posted';
    editable: boolean;
    withdrawn_on: string | null;
    note: string | null;
    proprietor: string | null;
    warehouse_id: number;
    warehouse: string | null;
    authorised_by: string | null;
    authorised_at: string | null;
    authorisation_note: string | null;
    self_authorised: boolean;
    reapproval_reason: string | null;
    cost_total: string;
    reference_total: string | null;
    difference_total: string | null;
    journal_number: string | null;
    posted_at: string | null;
    posted_by: string | null;
    created_by: string | null;
    lines: LineRow[];
}

interface MonthRow {
    month: string;
    label: string;
    count: number;
    lines: number;
    cost: string;
    reference: string | null;
    difference: string | null;
    unreferenced: number;
}

interface MonthDetail {
    month: string;
    label: string;
    rows: {
        withdrawal_id: number;
        number: string;
        withdrawn_on: string;
        proprietor: string | null;
        authorised_by: string | null;
        self_authorised: boolean;
        journal_number: string | null;
        product: string | null;
        sku: string | null;
        batch_number: string | null;
        quantity: string;
        unit_cost: string | null;
        cost: string;
        reference_source: string | null;
        reference_price: string | null;
        reference: string | null;
        difference: string | null;
    }[];
    totals: { count: number; lines: number; cost: string; reference: string | null; difference: string | null; unreferenced: number };
}

interface Props {
    withdrawals: WithdrawalRow[];
    filters: { new: boolean; month: string | null };
    months: MonthRow[];
    monthDetail: MonthDetail | null;
    warehouses: WarehouseOption[];
    products: ProductOption[];
    proprietor: { id: number; name: string } | null;
    isProprietor: boolean;
    drawingsMapped: boolean;
    today: string;
    canManage: boolean;
    canAuthorise: boolean;
    canPrepareVoucher: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounts', href: '/accounting/overview' },
    { title: 'Owner withdrawals', href: '/accounting/owner-withdrawals' },
];

function statusTone(row: WithdrawalRow): string {
    if (row.status === 'posted') {
        return 'text-emerald-700 dark:text-emerald-400';
    }

    if (row.status === 'approved') {
        return 'text-sky-700 dark:text-sky-400';
    }

    if (row.reapproval_reason) {
        return 'text-amber-700 dark:text-amber-400';
    }

    return 'text-muted-foreground';
}

/**
 * Accounts > Vouchers > Owner withdrawals (Phase 2, 17 Oct 2026). The list
 * with each document's lines (estimated batch cost on a draft, the frozen
 * batch and cost once authorised), the decisions as buttons (authorise,
 * send back, post, record money paid in), and the monthly report. Add and
 * edit happen in a popup (house rule).
 */
export default function OwnerWithdrawals({
    withdrawals,
    filters,
    months,
    monthDetail,
    warehouses,
    products,
    proprietor,
    isProprietor,
    drawingsMapped,
    today,
    canManage,
    canAuthorise,
    canPrepareVoucher,
}: Props) {
    const { t } = useTranslation();
    // `undefined` = closed, `null` = a new one.
    const [dialog, setDialog] = useState<DialogWithdrawal | null | undefined>(undefined);
    const [open, setOpen] = useState<number | null>(withdrawals[0]?.id ?? null);
    const [view, setView] = useState<'list' | 'monthly'>(filters.month ? 'monthly' : 'list');
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };

    useEffect(() => {
        if (filters.new && canManage) {
            setDialog(null);
        }
    }, [filters.new, canManage]);

    const act = (name: string, row: WithdrawalRow, data: Record<string, string> = {}) =>
        router.post(route(name, row.id), data, { preserveScroll: true });

    const toDialog = (row: WithdrawalRow): DialogWithdrawal => ({
        id: row.id,
        number: row.number,
        warehouse_id: row.warehouse_id,
        withdrawn_on: row.withdrawn_on,
        note: row.note,
        lines: row.lines.map((line) => ({ product_id: line.product_id, quantity: line.quantity, note: line.note })),
    });

    const statusText = (row: WithdrawalRow) =>
        row.status === 'posted'
            ? t('owner_withdrawal.status.posted')
            : row.status === 'approved'
              ? t('owner_withdrawal.status.approved')
              : row.reapproval_reason
                ? t('owner_withdrawal.sent_back')
                : t('owner_withdrawal.status.draft');

    const referenceLabel = (source: string | null) =>
        source === 'mrp'
            ? t('owner_withdrawal.reference_mrp')
            : source === 'selling_price'
              ? t('owner_withdrawal.reference_selling_price')
              : t('owner_withdrawal.reference_none');

    const tabClass = (active: boolean) =>
        cn('rounded-md px-3 py-1.5 text-sm', active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Owner withdrawals" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="max-w-2xl">
                        <p className="text-muted-foreground text-sm">{t('owner_withdrawal.intro')}</p>
                        <p className="mt-1 text-xs">
                            <span className="text-muted-foreground">{t('owner_withdrawal.proprietor')}: </span>
                            {proprietor ? (
                                <span className="font-medium">{proprietor.name}</span>
                            ) : (
                                <span className="font-medium text-amber-700 dark:text-amber-400">{t('owner_withdrawal.no_proprietor')}</span>
                            )}
                        </p>
                        {!drawingsMapped && (
                            <p className="mt-1 text-xs font-medium text-amber-700 dark:text-amber-400">{t('owner_withdrawal.drawings_unmapped')}</p>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="bg-muted/40 flex gap-1 rounded-lg border p-1">
                            <button type="button" className={tabClass(view === 'list')} onClick={() => setView('list')}>
                                {t('owner_withdrawal.view_list')}
                            </button>
                            <button type="button" className={tabClass(view === 'monthly')} onClick={() => setView('monthly')}>
                                {t('owner_withdrawal.view_monthly')}
                            </button>
                        </div>
                        {canManage && proprietor && (
                            <Button size="sm" onClick={() => setDialog(null)}>
                                {t('owner_withdrawal.add')}
                            </Button>
                        )}
                    </div>
                </div>

                {errors.lines && <p className="text-destructive text-sm">{errors.lines}</p>}
                {errors.authorisation_note && <p className="text-destructive text-sm">{errors.authorisation_note}</p>}

                {view === 'monthly' ? (
                    <MonthlyReport months={months} detail={monthDetail} referenceLabel={referenceLabel} />
                ) : withdrawals.length === 0 ? (
                    <div className="bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-sm">{t('owner_withdrawal.none')}</p>
                    </div>
                ) : (
                    <ul className="grid gap-3">
                        {withdrawals.map((row) => {
                            const expanded = open === row.id;
                            const draft = row.status === 'draft';

                            return (
                                <li key={row.id} className="bg-card rounded-xl border">
                                    <button
                                        type="button"
                                        className="flex w-full flex-wrap items-center justify-between gap-x-4 gap-y-1 p-4 text-left"
                                        onClick={() => setOpen(expanded ? null : row.id)}
                                    >
                                        <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                            <span className="font-medium">{row.number}</span>
                                            <span className={cn('text-xs font-medium', statusTone(row))}>{statusText(row)}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {row.proprietor}
                                                {row.warehouse && ` · ${row.warehouse}`}
                                                {row.self_authorised && ` · ${t('owner_withdrawal.self_authorised')}`}
                                            </span>
                                        </span>
                                        <span className="flex items-center gap-3 text-sm">
                                            <span className="text-muted-foreground text-xs">{row.withdrawn_on && formatDate(row.withdrawn_on)}</span>
                                            <span className="font-medium tabular-nums">{draft ? '' : formatMoney(row.cost_total)}</span>
                                        </span>
                                    </button>

                                    {expanded && (
                                        <div className="grid gap-4 border-t p-4 text-sm">
                                            {row.reapproval_reason && (
                                                <div className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                                                    <span className="font-medium">{t('owner_withdrawal.sent_back')}: </span>
                                                    {row.reapproval_reason}
                                                </div>
                                            )}

                                            <div className="overflow-x-auto">
                                                <table className="w-full text-[13px]">
                                                    <thead>
                                                        <tr className="text-muted-foreground border-b text-left text-xs">
                                                            <th className="py-1 pr-3">{t('owner_withdrawal.product')}</th>
                                                            <th className="py-1 pr-3 text-right">{t('owner_withdrawal.quantity')}</th>
                                                            <th className="py-1 pr-3">{t('owner_withdrawal.batch')}</th>
                                                            <th className="py-1 pr-3 text-right">
                                                                {draft ? t('owner_withdrawal.estimated_cost') : t('owner_withdrawal.cost')}
                                                            </th>
                                                            <th className="py-1 pr-3 text-right">{t('owner_withdrawal.reference')}</th>
                                                            <th className="py-1 text-right">{t('owner_withdrawal.difference')}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {row.lines.map((line) => {
                                                            const estimate = line.estimate;

                                                            return (
                                                                <tr key={line.id} className="border-b align-top">
                                                                    <td className="py-1.5 pr-3">
                                                                        {line.product}
                                                                        {line.sku && <span className="text-muted-foreground"> · {line.sku}</span>}
                                                                        {line.note && (
                                                                            <div className="text-muted-foreground text-xs">{line.note}</div>
                                                                        )}
                                                                    </td>
                                                                    <td className="py-1.5 pr-3 text-right tabular-nums">
                                                                        {formatQuantity(line.quantity)}
                                                                        {line.unit && <span className="text-muted-foreground"> {line.unit}</span>}
                                                                    </td>
                                                                    <td className="py-1.5 pr-3">
                                                                        {line.batch_id ? (
                                                                            <Link
                                                                                href={`/inventory/batches/${line.batch_id}`}
                                                                                className="text-primary hover:underline"
                                                                            >
                                                                                {line.batch_number ?? `#${line.batch_id}`}
                                                                            </Link>
                                                                        ) : estimate && estimate.batches > 1 ? (
                                                                            <span className="text-muted-foreground text-xs">
                                                                                {t('owner_withdrawal.estimate_split', { count: estimate.batches })}
                                                                            </span>
                                                                        ) : (
                                                                            ''
                                                                        )}
                                                                    </td>
                                                                    <td className="py-1.5 pr-3 text-right tabular-nums">
                                                                        {draft
                                                                            ? estimate
                                                                                ? formatMoney(estimate.cost)
                                                                                : ''
                                                                            : formatMoney(line.cost)}
                                                                        {(draft ? estimate?.unit_cost : line.unit_cost) && (
                                                                            <div className="text-muted-foreground text-xs">
                                                                                @ {formatQuantity(draft ? estimate?.unit_cost : line.unit_cost)}
                                                                            </div>
                                                                        )}
                                                                        {draft && estimate && Number(estimate.short) > 0 && (
                                                                            <div className="text-xs font-medium text-amber-700 dark:text-amber-400">
                                                                                {t('owner_withdrawal.estimate_short', {
                                                                                    quantity: formatQuantity(estimate.short),
                                                                                })}
                                                                            </div>
                                                                        )}
                                                                    </td>
                                                                    <td className="py-1.5 pr-3 text-right tabular-nums">
                                                                        {draft ? (
                                                                            estimate?.reference_price ? (
                                                                                <>
                                                                                    {formatQuantity(estimate.reference_price)}
                                                                                    <div className="text-muted-foreground text-xs">
                                                                                        {referenceLabel(estimate.reference_source)}
                                                                                    </div>
                                                                                </>
                                                                            ) : (
                                                                                <span className="text-muted-foreground text-xs">
                                                                                    {referenceLabel(estimate?.reference_source ?? null)}
                                                                                </span>
                                                                            )
                                                                        ) : line.reference ? (
                                                                            <>
                                                                                {formatMoney(line.reference)}
                                                                                <div className="text-muted-foreground text-xs">
                                                                                    {referenceLabel(line.reference_source)} @{' '}
                                                                                    {formatQuantity(line.reference_price)}
                                                                                </div>
                                                                            </>
                                                                        ) : (
                                                                            <span className="text-muted-foreground text-xs">
                                                                                {referenceLabel(line.reference_source)}
                                                                            </span>
                                                                        )}
                                                                    </td>
                                                                    <td className="py-1.5 text-right tabular-nums">
                                                                        {!draft && line.difference ? formatMoney(line.difference) : ''}
                                                                    </td>
                                                                </tr>
                                                            );
                                                        })}
                                                    </tbody>
                                                    {!draft && (
                                                        <tfoot>
                                                            <tr className="text-xs font-medium">
                                                                <td className="py-1.5 pr-3" colSpan={3}>
                                                                    {t('owner_withdrawal.total_cost')}
                                                                </td>
                                                                <td className="py-1.5 pr-3 text-right tabular-nums">{formatMoney(row.cost_total)}</td>
                                                                <td className="py-1.5 pr-3 text-right tabular-nums">
                                                                    {row.reference_total ? formatMoney(row.reference_total) : ''}
                                                                </td>
                                                                <td className="py-1.5 text-right tabular-nums">
                                                                    {row.difference_total ? formatMoney(row.difference_total) : ''}
                                                                </td>
                                                            </tr>
                                                        </tfoot>
                                                    )}
                                                </table>
                                            </div>

                                            <p className="text-muted-foreground text-xs">
                                                {draft ? t('owner_withdrawal.estimate_hint') : t('owner_withdrawal.difference_hint')}
                                            </p>

                                            <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                                {row.created_by && <span>{row.created_by}</span>}
                                                {row.authorised_by && (
                                                    <span>
                                                        {t('owner_withdrawal.authorised_by')}: {row.authorised_by}
                                                        {row.self_authorised && ` (${t('owner_withdrawal.self_authorised')})`}
                                                    </span>
                                                )}
                                                {row.authorisation_note && (
                                                    <span>
                                                        {t('owner_withdrawal.authorisation_note')}: {row.authorisation_note}
                                                    </span>
                                                )}
                                                {row.journal_number && (
                                                    <span>
                                                        {t('owner_withdrawal.journal')} {row.journal_number}
                                                    </span>
                                                )}
                                                {row.note && <span>{row.note}</span>}
                                            </div>

                                            <div className="flex flex-wrap justify-end gap-2">
                                                {canManage && row.editable && (
                                                    <>
                                                        <Button size="sm" variant="outline" onClick={() => setDialog(toDialog(row))}>
                                                            {t('owner_withdrawal.edit')}
                                                        </Button>
                                                        <ConfirmDialog
                                                            trigger={
                                                                <Button size="sm" variant="ghost">
                                                                    {t('owner_withdrawal.delete')}
                                                                </Button>
                                                            }
                                                            title={`${t('owner_withdrawal.delete')} ${row.number}?`}
                                                            description={t('owner_withdrawal.delete_text')}
                                                            confirmLabel={t('owner_withdrawal.delete')}
                                                            destructive
                                                            onConfirm={() =>
                                                                router.delete(route('accounting.owner-withdrawals.destroy', row.id), {
                                                                    preserveScroll: true,
                                                                })
                                                            }
                                                        />
                                                    </>
                                                )}
                                                {draft && canAuthorise && !isProprietor && (
                                                    <ConfirmDialog
                                                        trigger={<Button size="sm">{t('owner_withdrawal.authorise')}</Button>}
                                                        title={t('owner_withdrawal.authorise_title')}
                                                        description={t('owner_withdrawal.authorise_text')}
                                                        confirmLabel={t('owner_withdrawal.authorise')}
                                                        withNote
                                                        noteLabel={t('owner_withdrawal.authorisation_note')}
                                                        onConfirm={(note) =>
                                                            act('accounting.owner-withdrawals.authorise', row, { authorisation_note: note })
                                                        }
                                                    />
                                                )}
                                                {draft && isProprietor && (
                                                    <ConfirmDialog
                                                        trigger={
                                                            <Button size="sm" variant="outline">
                                                                {t('owner_withdrawal.authorise_self')}
                                                            </Button>
                                                        }
                                                        title={t('owner_withdrawal.authorise_title')}
                                                        description={t('owner_withdrawal.authorise_self_text')}
                                                        confirmLabel={t('owner_withdrawal.authorise')}
                                                        withNote
                                                        noteRequired
                                                        noteLabel={t('owner_withdrawal.authorisation_note')}
                                                        onConfirm={(note) =>
                                                            act('accounting.owner-withdrawals.authorise', row, { authorisation_note: note })
                                                        }
                                                    />
                                                )}
                                                {canManage && row.status === 'approved' && (
                                                    <>
                                                        <ConfirmDialog
                                                            trigger={
                                                                <Button size="sm" variant="outline">
                                                                    {t('owner_withdrawal.reopen')}
                                                                </Button>
                                                            }
                                                            title={`${t('owner_withdrawal.reopen')} ${row.number}?`}
                                                            description={t('owner_withdrawal.reopen_text')}
                                                            confirmLabel={t('owner_withdrawal.reopen')}
                                                            onConfirm={() => act('accounting.owner-withdrawals.reopen', row)}
                                                        />
                                                        <ConfirmDialog
                                                            trigger={<Button size="sm">{t('owner_withdrawal.post')}</Button>}
                                                            title={t('owner_withdrawal.post_title')}
                                                            description={t('owner_withdrawal.post_text')}
                                                            confirmLabel={t('owner_withdrawal.post')}
                                                            onConfirm={() => act('accounting.owner-withdrawals.post', row)}
                                                        />
                                                    </>
                                                )}
                                                {row.status === 'posted' && (
                                                    <>
                                                        <Badge variant="secondary">{t('owner_withdrawal.status.posted')}</Badge>
                                                        {canPrepareVoucher && (
                                                            <Button size="sm" variant="outline" asChild title={t('owner_withdrawal.reimburse_hint')}>
                                                                <Link
                                                                    href={`/accounting/vouchers/receipt/create?purpose=owner_reimbursement&withdrawal=${row.id}`}
                                                                >
                                                                    {t('owner_withdrawal.reimburse')}
                                                                </Link>
                                                            </Button>
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                            {draft && (
                                                <p className="text-muted-foreground text-right text-xs">{t('owner_withdrawal.second_person_rule')}</p>
                                            )}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}

                {canManage && (
                    <OwnerWithdrawalDialog
                        open={dialog !== undefined}
                        onOpenChange={(next) => !next && setDialog(undefined)}
                        withdrawal={dialog ?? null}
                        warehouses={warehouses}
                        products={products}
                        today={today}
                    />
                )}
            </div>
        </AppLayout>
    );
}

/** Posted withdrawals by month, and one month in detail when chosen (D2, D8). */
function MonthlyReport({
    months,
    detail,
    referenceLabel,
}: {
    months: MonthRow[];
    detail: MonthDetail | null;
    referenceLabel: (source: string | null) => string;
}) {
    const { t } = useTranslation();

    if (months.length === 0) {
        return (
            <div className="bg-card rounded-xl border p-4">
                <p className="text-muted-foreground text-sm">{t('owner_withdrawal.monthly_none')}</p>
            </div>
        );
    }

    return (
        <div className="grid gap-4">
            <p className="text-muted-foreground max-w-2xl text-sm">{t('owner_withdrawal.monthly_intro')}</p>

            <div className="bg-card overflow-x-auto rounded-xl border">
                <table className="w-full text-[13px]">
                    <thead>
                        <tr className="text-muted-foreground border-b text-left text-xs">
                            <th className="px-4 py-2">{t('owner_withdrawal.month')}</th>
                            <th className="px-4 py-2 text-right">{t('owner_withdrawal.withdrawals')}</th>
                            <th className="px-4 py-2 text-right">{t('owner_withdrawal.lines')}</th>
                            <th className="px-4 py-2 text-right">{t('owner_withdrawal.total_cost')}</th>
                            <th className="px-4 py-2 text-right">{t('owner_withdrawal.total_reference')}</th>
                            <th className="px-4 py-2 text-right">{t('owner_withdrawal.difference')}</th>
                            <th className="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {months.map((row) => (
                            <tr key={row.month} className={cn('border-b', detail?.month === row.month && 'bg-muted/40')}>
                                <td className="px-4 py-2 font-medium">{row.label}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{row.count}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{row.lines}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{formatMoney(row.cost)}</td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {row.reference ? formatMoney(row.reference) : ''}
                                    {row.unreferenced > 0 && (
                                        <div className="text-muted-foreground text-xs">
                                            {t('owner_withdrawal.unreferenced', { count: row.unreferenced })}
                                        </div>
                                    )}
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">{row.difference ? formatMoney(row.difference) : ''}</td>
                                <td className="px-4 py-2 text-right">
                                    <Link
                                        href={`/accounting/owner-withdrawals?month=${row.month}`}
                                        preserveScroll
                                        className="text-primary text-xs hover:underline"
                                    >
                                        {t('owner_withdrawal.detail')}
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {detail && (
                <section className="bg-card rounded-xl border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="font-medium">{detail.label}</p>
                        <Link href="/accounting/owner-withdrawals" preserveScroll className="text-primary text-xs hover:underline">
                            {t('owner_withdrawal.back_to_months')}
                        </Link>
                    </div>
                    <div className="mt-3 overflow-x-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-1 pr-3">{t('common.date')}</th>
                                    <th className="py-1 pr-3">#</th>
                                    <th className="py-1 pr-3">{t('owner_withdrawal.product')}</th>
                                    <th className="py-1 pr-3">{t('owner_withdrawal.batch')}</th>
                                    <th className="py-1 pr-3 text-right">{t('owner_withdrawal.quantity')}</th>
                                    <th className="py-1 pr-3 text-right">{t('owner_withdrawal.unit_cost')}</th>
                                    <th className="py-1 pr-3 text-right">{t('owner_withdrawal.cost')}</th>
                                    <th className="py-1 pr-3 text-right">{t('owner_withdrawal.reference')}</th>
                                    <th className="py-1 pr-3 text-right">{t('owner_withdrawal.difference')}</th>
                                    <th className="py-1">{t('owner_withdrawal.authorised_by')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {detail.rows.map((row, index) => (
                                    <tr key={`${row.withdrawal_id}-${index}`} className="border-b">
                                        <td className="py-1 pr-3">{formatDate(row.withdrawn_on)}</td>
                                        <td className="py-1 pr-3">
                                            {row.number}
                                            {row.journal_number && <div className="text-muted-foreground">{row.journal_number}</div>}
                                        </td>
                                        <td className="py-1 pr-3">
                                            {row.product}
                                            {row.sku && <span className="text-muted-foreground"> · {row.sku}</span>}
                                        </td>
                                        <td className="py-1 pr-3">{row.batch_number ?? ''}</td>
                                        <td className="py-1 pr-3 text-right tabular-nums">{formatQuantity(row.quantity)}</td>
                                        <td className="py-1 pr-3 text-right tabular-nums">{row.unit_cost ? formatQuantity(row.unit_cost) : ''}</td>
                                        <td className="py-1 pr-3 text-right tabular-nums">{formatMoney(row.cost)}</td>
                                        <td className="py-1 pr-3 text-right tabular-nums">
                                            {row.reference ? formatMoney(row.reference) : referenceLabel(row.reference_source)}
                                            {row.reference && (
                                                <div className="text-muted-foreground">
                                                    {referenceLabel(row.reference_source)} @ {formatQuantity(row.reference_price)}
                                                </div>
                                            )}
                                        </td>
                                        <td className="py-1 pr-3 text-right tabular-nums">{row.difference ? formatMoney(row.difference) : ''}</td>
                                        <td className="py-1">
                                            {row.authorised_by}
                                            {row.self_authorised && (
                                                <span className="text-muted-foreground"> ({t('owner_withdrawal.self_authorised')})</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="font-medium">
                                    <td className="py-1.5 pr-3" colSpan={6}>
                                        {t('owner_withdrawal.total_cost')} ({detail.totals.count} / {detail.totals.lines})
                                    </td>
                                    <td className="py-1.5 pr-3 text-right tabular-nums">{formatMoney(detail.totals.cost)}</td>
                                    <td className="py-1.5 pr-3 text-right tabular-nums">
                                        {detail.totals.reference ? formatMoney(detail.totals.reference) : ''}
                                    </td>
                                    <td className="py-1.5 pr-3 text-right tabular-nums">
                                        {detail.totals.difference ? formatMoney(detail.totals.difference) : ''}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            )}
        </div>
    );
}
