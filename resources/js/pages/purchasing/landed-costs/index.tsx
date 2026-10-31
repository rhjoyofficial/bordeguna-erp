import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    LandedCostDialog,
    type CostTypeOption,
    type DialogLandedCost,
    type Option,
    type ReceiptOption,
} from '@/components/purchasing/landed-cost-dialog';
import { EngineActions, type EngineAction } from '@/components/sales/correction-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { cn, formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Allocation {
    receipt_line_id: number;
    batch_id: number;
    batch_number: string | null;
    product?: string | null;
    quantity_received: string;
    quantity_on_hand: string;
    quantity_consumed: string;
    /** 18 Oct 2026: the units, grams or taka the share was computed from. */
    basis_quantity?: string | null;
    basis_unit?: string | null;
    share: string;
    on_hand_share: string;
    consumed_share: string;
    old_unit_cost?: string;
    new_unit_cost: string | null;
}

interface LineRow {
    id: number;
    cost_type: string;
    cost_type_label: string;
    treatment: string;
    treatment_label: string;
    default_treatment: string;
    default_treatment_label: string;
    overrides_default: boolean;
    override_reason: string | null;
    allocation_method: string;
    allocation_method_label: string;
    goods_receipt_line_id: number | null;
    amount: string;
    supplier_id: number | null;
    supplier: string | null;
    money_account_id: number | null;
    money_account: string | null;
    payee_name?: string | null;
    expense_account_id: number | null;
    expense_account: string | null;
    description: string | null;
    double_count: string[];
    allocations: Allocation[];
}

interface Plan {
    capitalised: string;
    on_hand: string;
    consumed: string;
    expensed: string;
    refusals: string[];
    lines: { line_id: number; basis: string | null; basis_label: string | null; expense_account: string | null; allocations: Allocation[] }[];
}

interface LandedCostRow {
    id: number;
    number: string;
    status: 'draft' | 'approved' | 'posted';
    status_label: string;
    awaiting_approval: boolean;
    editable: boolean;
    incurred_on: string | null;
    note: string | null;
    total: string;
    goods_receipt_id: number;
    po_number: string | null;
    purchase_order_id: number | null;
    supplier: string | null;
    received_at: string | null;
    created_by: string | null;
    requested_by: string | null;
    reviewed_by: string | null;
    review_note: string | null;
    rejected: boolean;
    journal_number: string | null;
    posted_at: string | null;
    bills: { id: number; supplier: string | null; total: string; outstanding: string }[];
    lines: LineRow[];
    plan: Plan | null;
    // ⭐ B24 (25 Oct 2026): Reverse a posted landed cost (the engine's dialog).
    reversal: { on: string | null; by: string | null; reason: string | null; journal: string | null } | null;
    actions: EngineAction[];
}

interface Props {
    landedCosts: LandedCostRow[];
    filters: { receipt: number | null; new: boolean };
    receipts: ReceiptOption[];
    costTypes: CostTypeOption[];
    treatments: Option[];
    methods: Option[];
    suppliers: { id: number; name: string }[];
    moneyAccounts: { id: number; name: string; type: string }[];
    expenseAccounts: { id: number; label: string }[];
    today: string;
    canManage: boolean;
    canPost: boolean;
    reversalReasons: { id: number; name: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Purchase orders', href: '/purchasing/purchase-orders' },
    { title: 'Landed costs', href: '/purchasing/landed-costs' },
];

function statusTone(row: LandedCostRow): string {
    if (row.status === 'posted') {
        return 'text-emerald-700 dark:text-emerald-400';
    }

    if (row.status === 'approved') {
        return 'text-sky-700 dark:text-sky-400';
    }

    if (row.awaiting_approval) {
        return 'text-amber-700 dark:text-amber-400';
    }

    return 'text-muted-foreground';
}

/**
 * Purchases > Purchase orders > Landed costs (Phase 1, 17 Oct 2026). The list,
 * with each document's lines and what posting will do (or did) per batch, and
 * the three decisions as buttons. Add and edit happen in a popup (house rule).
 */
export default function LandedCosts({
    landedCosts,
    filters,
    receipts,
    costTypes,
    treatments,
    methods,
    suppliers,
    moneyAccounts,
    expenseAccounts,
    today,
    canManage,
    canPost,
    reversalReasons,
}: Props) {
    const { t, tr } = useTranslation();
    // `undefined` = closed, `null` = a new one.
    const [dialog, setDialog] = useState<DialogLandedCost | null | undefined>(undefined);
    const [open, setOpen] = useState<number | null>(landedCosts[0]?.id ?? null);
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };

    // Arriving from a delivery's "Add landed cost" link opens the popup on that delivery.
    useEffect(() => {
        if (filters.new && canManage) {
            setDialog(null);
        }
    }, [filters.new, canManage]);

    const act = (name: string, row: LandedCostRow) => router.post(route(name, row.id), {}, { preserveScroll: true });

    const toDialog = (row: LandedCostRow): DialogLandedCost => ({
        id: row.id,
        number: row.number,
        goods_receipt_id: row.goods_receipt_id,
        incurred_on: row.incurred_on,
        note: row.note,
        lines: row.lines,
    });

    const statusText = (row: LandedCostRow) =>
        row.reversal
            ? t('landed.reversed')
            : row.awaiting_approval
              ? t('landed.status.waiting')
              : row.status === 'posted'
                ? t('landed.status.posted')
                : row.status === 'approved'
                  ? t('landed.status.approved')
                  : row.rejected
                    ? t('landed.status.rejected')
                    : t('landed.status.draft');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Landed costs" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-end gap-3">
                    {canManage && (
                        <Button size="sm" onClick={() => setDialog(null)}>
                            {t('landed.add')}
                        </Button>
                    )}
                </div>

                {filters.receipt !== null && (
                    <p className="text-muted-foreground text-xs">
                        {t('landed.filtered_to')}{' '}
                        <Link href="/purchasing/landed-costs" className="text-primary hover:underline">
                            {t('landed.filter_all')}
                        </Link>
                    </p>
                )}

                {errors.lines && <p className="text-destructive text-sm">{errors.lines}</p>}

                {landedCosts.length === 0 ? (
                    <div className="bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-sm">{t('landed.none')}</p>
                    </div>
                ) : (
                    <ul className="grid gap-3">
                        {landedCosts.map((row) => {
                            const expanded = open === row.id;
                            const plan = row.plan;

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
                                                {t('landed.receipt')} #{row.goods_receipt_id}
                                                {row.po_number && ` · ${row.po_number}`}
                                                {row.supplier && ` · ${row.supplier}`}
                                                {row.received_at && ` · ${formatDate(row.received_at)}`}
                                            </span>
                                        </span>
                                        <span className="flex items-center gap-3 text-sm">
                                            <span className="text-muted-foreground text-xs">{row.incurred_on && formatDate(row.incurred_on)}</span>
                                            <span className="font-medium tabular-nums">{formatMoney(row.total)}</span>
                                        </span>
                                    </button>

                                    {expanded && (
                                        <div className="grid gap-4 border-t p-4 text-sm">
                                            <table className="w-full text-[13px]">
                                                <thead>
                                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                                        <th className="py-1 pr-3">{t('landed.cost_type')}</th>
                                                        <th className="py-1 pr-3">{t('landed.treatment')}</th>
                                                        <th className="py-1 pr-3">{t('landed.method')}</th>
                                                        <th className="py-1 pr-3">{t('landed.payee')}</th>
                                                        <th className="py-1 text-right">{t('landed.amount')}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {row.lines.map((line) => (
                                                        <tr key={line.id} className="border-b align-top">
                                                            <td className="py-1.5 pr-3">
                                                                {tr(`landed.type.${line.cost_type}`, line.cost_type_label)}
                                                                {line.description && (
                                                                    <div className="text-muted-foreground text-xs">{line.description}</div>
                                                                )}
                                                            </td>
                                                            <td className="py-1.5 pr-3">
                                                                {tr(`landed.treatment.${line.treatment}`, line.treatment_label)}
                                                                {/* ⭐ 18 Oct 2026: the suggested treatment stays visible, before and after posting. */}
                                                                {line.overrides_default ? (
                                                                    <div className="text-xs text-amber-700 dark:text-amber-400">
                                                                        {t('landed.default_is', {
                                                                            treatment: tr(
                                                                                `landed.treatment.${line.default_treatment}`,
                                                                                line.default_treatment_label,
                                                                            ),
                                                                        })}
                                                                        <br />
                                                                        {t('landed.treatment_override')}: {line.override_reason}
                                                                    </div>
                                                                ) : (
                                                                    <div className="text-muted-foreground text-xs">
                                                                        {t('landed.treatment_default')}
                                                                    </div>
                                                                )}
                                                                {line.treatment === 'expense' && (
                                                                    <div className="text-muted-foreground text-xs">
                                                                        {line.expense_account ??
                                                                            plan?.lines.find((p) => p.line_id === line.id)?.expense_account ??
                                                                            ''}
                                                                    </div>
                                                                )}
                                                            </td>
                                                            <td className="py-1.5 pr-3">
                                                                {line.treatment === 'capitalize'
                                                                    ? tr(`landed.method.${line.allocation_method}`, line.allocation_method_label)
                                                                    : ''}
                                                                {line.double_count.length > 0 && (
                                                                    <div className="mt-1 rounded border border-amber-300 bg-amber-50 p-1.5 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                                                                        <p className="font-medium">{t('landed.double_count_title')}</p>
                                                                        {line.double_count.map((warning) => (
                                                                            <p key={warning}>{warning}</p>
                                                                        ))}
                                                                    </div>
                                                                )}
                                                            </td>
                                                            <td className="py-1.5 pr-3">
                                                                {line.supplier ?? line.money_account ?? ''}
                                                                {line.payee_name ? ` (${line.payee_name})` : ''}
                                                            </td>
                                                            <td className="py-1.5 text-right tabular-nums">{formatMoney(line.amount)}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>

                                            {/* ⭐ The allocation per batch: planned for an open document, frozen for a posted one. */}
                                            <section className="rounded-lg border p-3">
                                                <p className="text-[13px] font-semibold">
                                                    {row.status === 'posted' ? t('landed.preview_posted') : t('landed.preview')}
                                                </p>
                                                {plan && plan.refusals.length > 0 && (
                                                    <div className="mt-2 rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                                                        <p className="font-medium">{t('landed.preview_refused')}</p>
                                                        <ul className="mt-1 list-disc pl-4">
                                                            {plan.refusals.map((refusal) => (
                                                                <li key={refusal}>{refusal}</li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
                                                {plan && (
                                                    <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                                                        <dt className="text-muted-foreground">{t('landed.capitalised')}</dt>
                                                        <dd className="tabular-nums">{formatMoney(plan.capitalised)}</dd>
                                                        <dt className="text-muted-foreground">{t('landed.on_hand')}</dt>
                                                        <dd className="tabular-nums">{formatMoney(plan.on_hand)}</dd>
                                                        <dt className="text-muted-foreground">{t('landed.consumed')}</dt>
                                                        <dd className="tabular-nums">{formatMoney(plan.consumed)}</dd>
                                                        <dt className="text-muted-foreground">{t('landed.expensed')}</dt>
                                                        <dd className="tabular-nums">{formatMoney(plan.expensed)}</dd>
                                                    </dl>
                                                )}
                                                {(() => {
                                                    const rows: { line: LineRow; allocations: Allocation[] }[] = row.lines
                                                        .filter((line) => line.treatment === 'capitalize')
                                                        .map((line) => ({
                                                            line,
                                                            allocations:
                                                                row.status === 'posted'
                                                                    ? line.allocations
                                                                    : (plan?.lines.find((p) => p.line_id === line.id)?.allocations ?? []),
                                                        }))
                                                        .filter((entry) => entry.allocations.length > 0);

                                                    if (rows.length === 0) {
                                                        return null;
                                                    }

                                                    return (
                                                        <div className="mt-2 overflow-x-auto">
                                                            <table className="w-full text-xs">
                                                                <thead>
                                                                    <tr className="text-muted-foreground border-b text-left">
                                                                        <th className="py-1 pr-3">{t('landed.cost_type')}</th>
                                                                        <th className="py-1 pr-3">{t('landed.batch')}</th>
                                                                        <th className="py-1 pr-3 text-right">{t('landed.basis')}</th>
                                                                        <th className="py-1 pr-3 text-right">{t('landed.qty_received')}</th>
                                                                        <th className="py-1 pr-3 text-right">{t('landed.qty_on_hand')}</th>
                                                                        <th className="py-1 pr-3 text-right">{t('landed.qty_consumed')}</th>
                                                                        <th className="py-1 pr-3 text-right">{t('landed.share')}</th>
                                                                        <th className="py-1 pr-3 text-right">{t('landed.on_hand')}</th>
                                                                        <th className="py-1 pr-3 text-right">{t('landed.consumed')}</th>
                                                                        <th className="py-1 text-right">{t('landed.new_cost')}</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    {rows.map(({ line, allocations }) =>
                                                                        allocations.map((a) => (
                                                                            <tr key={`${line.id}-${a.batch_id}`} className="border-b">
                                                                                <td className="py-1 pr-3">
                                                                                    {tr(`landed.type.${line.cost_type}`, line.cost_type_label)}
                                                                                    <div className="text-muted-foreground">
                                                                                        {tr(
                                                                                            `landed.method.${line.allocation_method}`,
                                                                                            line.allocation_method_label,
                                                                                        )}
                                                                                    </div>
                                                                                </td>
                                                                                <td className="py-1 pr-3">
                                                                                    {a.product && <span>{a.product} · </span>}
                                                                                    <Link
                                                                                        href={`/inventory/batches/${a.batch_id}`}
                                                                                        className="text-primary hover:underline"
                                                                                    >
                                                                                        {a.batch_number ?? `#${a.batch_id}`}
                                                                                    </Link>
                                                                                </td>
                                                                                <td className="py-1 pr-3 text-right tabular-nums">
                                                                                    {a.basis_quantity == null
                                                                                        ? ''
                                                                                        : a.basis_unit === 'Tk'
                                                                                          ? formatMoney(a.basis_quantity)
                                                                                          : `${formatQuantity(a.basis_quantity)} ${a.basis_unit ?? ''}`}
                                                                                </td>
                                                                                <td className="py-1 pr-3 text-right tabular-nums">
                                                                                    {formatQuantity(a.quantity_received)}
                                                                                </td>
                                                                                <td className="py-1 pr-3 text-right tabular-nums">
                                                                                    {formatQuantity(a.quantity_on_hand)}
                                                                                </td>
                                                                                <td className="py-1 pr-3 text-right tabular-nums">
                                                                                    {formatQuantity(a.quantity_consumed)}
                                                                                </td>
                                                                                <td className="py-1 pr-3 text-right tabular-nums">
                                                                                    {formatMoney(a.share)}
                                                                                </td>
                                                                                <td className="py-1 pr-3 text-right tabular-nums">
                                                                                    {formatMoney(a.on_hand_share)}
                                                                                </td>
                                                                                <td className="py-1 pr-3 text-right tabular-nums">
                                                                                    {formatMoney(a.consumed_share)}
                                                                                </td>
                                                                                <td className="py-1 text-right tabular-nums">
                                                                                    {a.old_unit_cost && `${a.old_unit_cost} → `}
                                                                                    {a.new_unit_cost ?? a.old_unit_cost ?? ''}
                                                                                </td>
                                                                            </tr>
                                                                        )),
                                                                    )}
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    );
                                                })()}
                                            </section>

                                            {/* ⭐ 18 Oct 2026 (finding P3): what is owed to a supplier is a bill, paid like any other. */}
                                            {row.bills.length > 0 && (
                                                <section className="rounded-lg border p-3 text-xs">
                                                    <p className="text-[13px] font-semibold">{t('landed.bills')}</p>
                                                    <p className="text-muted-foreground">{t('landed.bills_hint')}</p>
                                                    <ul className="mt-1 grid gap-1">
                                                        {row.bills.map((bill) => (
                                                            <li key={bill.id}>
                                                                <Link
                                                                    href={route('purchasing.bills.show', bill.id)}
                                                                    className="text-primary hover:underline"
                                                                >
                                                                    {row.number} · {bill.supplier}
                                                                </Link>{' '}
                                                                <span className="tabular-nums">{formatMoney(bill.total)}</span>
                                                                {' · '}
                                                                <span className="tabular-nums">
                                                                    {formatMoney(bill.outstanding)} {t('landed.bill_outstanding')}
                                                                </span>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </section>
                                            )}

                                            <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                                {row.created_by && <span>{row.created_by}</span>}
                                                {row.reviewed_by && (
                                                    <span>
                                                        {statusText(row)}: {row.reviewed_by}
                                                    </span>
                                                )}
                                                {row.review_note && (
                                                    <span>
                                                        {t('landed.review_note')}: {row.review_note}
                                                    </span>
                                                )}
                                                {row.journal_number && (
                                                    <span>
                                                        {t('landed.journal')} {row.journal_number}
                                                    </span>
                                                )}
                                                {row.purchase_order_id && (
                                                    <Link
                                                        href={`/purchasing/purchase-orders/${row.purchase_order_id}`}
                                                        className="text-primary hover:underline"
                                                    >
                                                        {t('landed.view_on_receipt')}
                                                    </Link>
                                                )}
                                                {row.note && <span>{row.note}</span>}
                                            </div>

                                            <div className="flex flex-wrap justify-end gap-2">
                                                {canManage && row.editable && (
                                                    <>
                                                        <Button size="sm" variant="outline" onClick={() => setDialog(toDialog(row))}>
                                                            {t('landed.edit')}
                                                        </Button>
                                                        <ConfirmDialog
                                                            trigger={
                                                                <Button size="sm" variant="ghost">
                                                                    {t('landed.delete')}
                                                                </Button>
                                                            }
                                                            title={`${t('landed.delete')} ${row.number}?`}
                                                            description={t('landed.delete_confirm')}
                                                            confirmLabel={t('landed.delete')}
                                                            destructive
                                                            onConfirm={() =>
                                                                router.delete(route('purchasing.landed-costs.destroy', row.id), {
                                                                    preserveScroll: true,
                                                                })
                                                            }
                                                        />
                                                        <Button
                                                            size="sm"
                                                            disabled={plan === null || plan.refusals.length > 0}
                                                            onClick={() => act('purchasing.landed-costs.submit', row)}
                                                        >
                                                            {t('landed.submit')}
                                                        </Button>
                                                    </>
                                                )}
                                                {canPost && row.awaiting_approval && (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => act('purchasing.landed-costs.reject', row)}
                                                        >
                                                            {t('landed.reject')}
                                                        </Button>
                                                        <Button size="sm" onClick={() => act('purchasing.landed-costs.approve', row)}>
                                                            {t('landed.approve')}
                                                        </Button>
                                                    </>
                                                )}
                                                {canPost && row.status === 'approved' && (
                                                    <ConfirmDialog
                                                        trigger={<Button size="sm">{t('landed.post')}</Button>}
                                                        title={t('landed.post_confirm_title', { number: row.number })}
                                                        description={t('landed.post_confirm')}
                                                        confirmLabel={t('landed.post')}
                                                        onConfirm={() => act('purchasing.landed-costs.post', row)}
                                                    />
                                                )}
                                                {row.status === 'posted' && row.reversal && (
                                                    <div className="flex flex-col items-end gap-1">
                                                        <Badge variant="outline">{t('landed.reversed')}</Badge>
                                                        <p className="text-muted-foreground max-w-72 text-right text-xs">
                                                            {t('landed.reversed_on', {
                                                                date: row.reversal.on ? formatDate(row.reversal.on) : '',
                                                                by: row.reversal.by ?? '',
                                                            })}{' '}
                                                            {row.reversal.reason}
                                                            {row.reversal.journal && ` (${row.reversal.journal})`}
                                                        </p>
                                                    </div>
                                                )}
                                                {row.status === 'posted' && !row.reversal && (
                                                    <>
                                                        <Badge variant="secondary">{t('landed.status.posted')}</Badge>
                                                        <EngineActions
                                                            type="purchasing.landed_cost"
                                                            id={row.id}
                                                            recordLabel={row.number}
                                                            actions={row.actions}
                                                            reasons={reversalReasons}
                                                            labels={{ reverse: t('landed.reverse') }}
                                                        />
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}

                {canManage && (
                    <LandedCostDialog
                        open={dialog !== undefined}
                        onOpenChange={(next) => !next && setDialog(undefined)}
                        landedCost={dialog ?? null}
                        receipts={receipts}
                        presetReceiptId={filters.receipt}
                        costTypes={costTypes}
                        treatments={treatments}
                        methods={methods}
                        suppliers={suppliers}
                        moneyAccounts={moneyAccounts}
                        expenseAccounts={expenseAccounts}
                        today={today}
                    />
                )}
            </div>
        </AppLayout>
    );
}
