import { Panel, Pill } from '@/components/assets/assets-ui';
import { DocumentFiles, type DocumentFile } from '@/components/purchasing/document-files';
import { ReceiptCorrections, type ReceiptCorrection } from '@/components/purchasing/receipt-corrections';
import { BILL_STATE, type BillState } from '@/components/purchasing/receipt-state';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Line {
    id: number;
    product: string | null;
    sku: string | null;
    quantity_received: string;
    unit: string | null;
    stock_quantity: string | null;
    stock_unit: string | null;
    unit_cost: string | null;
    value: string;
    billable: string;
    batch: {
        id: number;
        batch_number: string | null;
        manufactured_date: string | null;
        expiry_date: string | null;
        current_quantity: string;
        unit_cost: string;
        held: boolean;
    } | null;
}

interface Journal {
    id: number;
    number: string;
    date: string | null;
    reversal: boolean;
}

/**
 * ⭐ One Receipt Note (purchase redesign PR2, 18 Oct 2026): what arrived, the
 * batches it made, the extra costs on it, the bills that cover it, anything
 * sent back, and the journal, each a link. Reads only; every action is a link
 * to the screen that owns it.
 */
export default function ReceiptShow({
    receipt,
    lines,
    landed_costs,
    bills,
    returns,
    journals,
    can_view_journals,
    can_view_batches,
    can_view_landed_costs,
    can_manage_landed_costs,
    can_record_bill,
    correction,
    files = [],
    can_attach = false,
}: {
    receipt: {
        id: number;
        received_at: string | null;
        received_on: string | null;
        received_by: string | null;
        supplier_challan_number: string | null;
        backdate_reason: string | null;
        note: string | null;
        value: string;
        bill_state: BillState;
        order: { id: number; po_number: string; status: string | null } | null;
        supplier: { id: number; name: string } | null;
        warehouse: { id: number; name: string } | null;
        quick_purchase_id: number | null;
    };
    /** Initial plan B1 (R58): the files kept with this delivery. */
    files?: DocumentFile[];
    can_attach?: boolean;
    lines: Line[];
    landed_costs: { id: number; number: string; status: string; total: string; incurred_on: string | null }[];
    bills: { id: number; bill_number: string; bill_date: string | null; total: string; reversed: boolean; names_this_delivery: boolean }[];
    /** 24 Oct 2026: a reversed return put its goods back into these batches. */
    returns: { id: number; returned_on: string | null; quantity: string; reversed: boolean; reversed_on: string | null }[];
    journals: Journal[];
    can_view_journals: boolean;
    can_view_batches: boolean;
    can_view_landed_costs: boolean;
    can_manage_landed_costs: boolean;
    can_record_bill: boolean;
    /** ⭐ Corrections wave 1 (19 Oct 2026): Fix batch details, Correct, Reverse and the chain. */
    correction?: ReceiptCorrection;
}) {
    const { t } = useTranslation();
    const title = t('receipts.receipt_no', { id: receipt.id });
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: t('receipts.title'), href: '/purchasing/receipts' },
        { title, href: `/purchasing/receipts/${receipt.id}` },
    ];
    const state = BILL_STATE[receipt.bill_state];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex flex-col gap-4 p-4">
                <section className="bg-card rounded-xl border p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-lg font-semibold">
                                {title}
                                {receipt.supplier_challan_number && (
                                    <span className="text-muted-foreground ml-2 text-sm font-normal">challan {receipt.supplier_challan_number}</span>
                                )}
                            </p>
                            <p className="text-muted-foreground text-sm">
                                {receipt.supplier ? (
                                    <Link href={`/purchasing/suppliers/${receipt.supplier.id}`} className="text-foreground hover:underline">
                                        {receipt.supplier.name}
                                    </Link>
                                ) : (
                                    '-'
                                )}
                                {receipt.order && (
                                    <>
                                        {' · '}
                                        <Link href={`/purchasing/purchase-orders/${receipt.order.id}`} className="text-foreground hover:underline">
                                            {receipt.order.po_number}
                                        </Link>
                                    </>
                                )}
                                {receipt.warehouse && ` · ${receipt.warehouse.name}`}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Pill tone={state.tone}>{t(state.key)}</Pill>
                            {can_record_bill && receipt.supplier && !correction?.reversed && (
                                <Button size="sm" asChild>
                                    <Link href={`/purchasing/bills/new?supplier=${receipt.supplier.id}`}>{t('receipts.record_bill')}</Link>
                                </Button>
                            )}
                        </div>
                    </div>
                    {correction && (
                        <div className="mt-2 flex justify-end">
                            <ReceiptCorrections
                                receipt={{ id: receipt.id, supplier_challan_number: receipt.supplier_challan_number, note: receipt.note }}
                                lines={lines.map((line) => ({ id: line.id, product: line.product, batch: line.batch }))}
                                correction={correction}
                            />
                        </div>
                    )}
                    <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm md:grid-cols-4">
                        <dt className="text-muted-foreground">{t('receipts.received_on')}</dt>
                        <dd>{showDate(receipt.received_at) || '-'}</dd>
                        <dt className="text-muted-foreground">{t('receipts.received_by')}</dt>
                        <dd>{receipt.received_by ?? '-'}</dd>
                        <dt className="text-muted-foreground">{t('receipts.col_value')}</dt>
                        <dd className="font-medium tabular-nums">{receipt.value}</dd>
                        {receipt.backdate_reason && (
                            <>
                                <dt className="text-muted-foreground">{t('receipts.backdate_reason')}</dt>
                                <dd>{receipt.backdate_reason}</dd>
                            </>
                        )}
                    </dl>
                    {receipt.note && <p className="text-muted-foreground mt-2 text-sm">{receipt.note}</p>}
                    {receipt.quick_purchase_id && (
                        <p className="mt-2 text-sm">
                            <Link href={`/purchasing/buy-and-receive/${receipt.quick_purchase_id}`} className="hover:underline">
                                {t('receipts.quick')}
                            </Link>
                        </p>
                    )}
                </section>

                <Panel title={t('receipts.lines')} flush>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[56rem] text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3.5 [&_tbody_td]:py-2.5 [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th>{t('receipts.col_product')}</th>
                                    <th className="text-right!">{t('receipts.col_received')}</th>
                                    <th className="text-right!">{t('receipts.col_stock')}</th>
                                    <th className="text-right!">{t('receipts.col_cost')}</th>
                                    <th className="text-right!">{t('receipts.col_value')}</th>
                                    <th>{t('receipts.col_batch')}</th>
                                    <th>{t('receipts.col_made')}</th>
                                    <th>{t('receipts.col_expires')}</th>
                                    <th className="text-right!">{t('receipts.col_left_to_bill')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {lines.map((line) => (
                                    <tr key={line.id}>
                                        <td>
                                            <span className="font-medium">{line.product ?? '-'}</span>
                                            {line.sku && <div className="text-muted-foreground text-xs">{line.sku}</div>}
                                        </td>
                                        <td className="text-right whitespace-nowrap tabular-nums">
                                            {formatQuantity(line.quantity_received)} {line.unit ?? ''}
                                        </td>
                                        <td className="text-right whitespace-nowrap tabular-nums">
                                            {line.stock_quantity === null ? '-' : `${formatQuantity(line.stock_quantity)} ${line.stock_unit ?? ''}`}
                                        </td>
                                        <td className="text-right tabular-nums">{line.unit_cost ?? '-'}</td>
                                        <td className="text-right font-medium tabular-nums">{line.value}</td>
                                        <td className="whitespace-nowrap">
                                            {line.batch ? (
                                                <>
                                                    {can_view_batches ? (
                                                        <Link href={`/inventory/batches/${line.batch.id}`} className="font-medium hover:underline">
                                                            {line.batch.batch_number ?? `#${line.batch.id}`}
                                                        </Link>
                                                    ) : (
                                                        <span className="font-medium">{line.batch.batch_number ?? `#${line.batch.id}`}</span>
                                                    )}
                                                    {line.batch.held && (
                                                        <Pill tone="danger" className="ml-1.5">
                                                            {t('receipts.held')}
                                                        </Pill>
                                                    )}
                                                    <div className="text-muted-foreground text-xs">
                                                        {t('receipts.batch_now', {
                                                            qty: formatQuantity(line.batch.current_quantity),
                                                            cost: line.batch.unit_cost,
                                                        })}
                                                    </div>
                                                </>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap">
                                            {line.batch?.manufactured_date ? formatDate(line.batch.manufactured_date) : '-'}
                                        </td>
                                        <td className="whitespace-nowrap">{line.batch?.expiry_date ? formatDate(line.batch.expiry_date) : '-'}</td>
                                        <td className="text-right tabular-nums">{formatQuantity(line.billable)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel
                        title={t('receipts.landed_costs')}
                        aside={
                            can_manage_landed_costs && (
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={`/purchasing/landed-costs?receipt=${receipt.id}&new=1`}>{t('receipts.add_landed')}</Link>
                                </Button>
                            )
                        }
                    >
                        {landed_costs.length === 0 ? (
                            <p className="text-muted-foreground mt-2 text-sm">{t('receipts.no_landed')}</p>
                        ) : (
                            <ul className="mt-2 grid gap-1.5 text-[13px]">
                                {landed_costs.map((lc) => (
                                    <li key={lc.id} className="flex flex-wrap justify-between gap-2">
                                        {can_view_landed_costs ? (
                                            <Link href={`/purchasing/landed-costs?receipt=${receipt.id}`} className="font-medium hover:underline">
                                                {lc.number}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">{lc.number}</span>
                                        )}
                                        <span className="text-muted-foreground">
                                            {showDate(lc.incurred_on)} · {lc.status}
                                        </span>
                                        <span className="tabular-nums">{lc.total}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel title={t('receipts.bills')}>
                        {bills.length === 0 ? (
                            <p className="text-muted-foreground mt-2 text-sm">{t('receipts.no_bills')}</p>
                        ) : (
                            <ul className="mt-2 grid gap-1.5 text-[13px]">
                                {bills.map((bill) => (
                                    <li key={bill.id} className="flex flex-wrap justify-between gap-2">
                                        <Link href={`/purchasing/bills/${bill.id}`} className="font-medium hover:underline">
                                            {bill.bill_number}
                                        </Link>
                                        <span className="text-muted-foreground">
                                            {showDate(bill.bill_date)} ·{' '}
                                            {bill.names_this_delivery ? t('receipts.bill_names') : t('receipts.bill_order')}
                                        </span>
                                        <span className="tabular-nums">
                                            {bill.total}
                                            {bill.reversed && (
                                                <Pill tone="muted" className="ml-1.5">
                                                    {t('receipts.reversed')}
                                                </Pill>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel
                        title={t('receipts.returns')}
                        aside={
                            receipt.order && (
                                <Link href={`/purchasing/purchase-orders/${receipt.order.id}`} className="text-xs hover:underline">
                                    {t('receipts.return_goods')}
                                </Link>
                            )
                        }
                    >
                        {returns.length === 0 ? (
                            <p className="text-muted-foreground mt-2 text-sm">{t('receipts.no_returns')}</p>
                        ) : (
                            <ul className="mt-2 grid gap-1.5 text-[13px]">
                                {returns.map((row) => (
                                    <li
                                        key={row.id}
                                        className={`flex flex-wrap items-center justify-between gap-2 ${row.reversed ? 'text-muted-foreground' : ''}`}
                                    >
                                        <Link
                                            href={`/purchasing/returns/${row.id}`}
                                            className={`font-medium hover:underline ${row.reversed ? 'line-through' : ''}`}
                                        >
                                            {t('returns.return_no', { id: row.id })}
                                        </Link>
                                        <span className="text-muted-foreground">{showDate(row.returned_on)}</span>
                                        <span className={`tabular-nums ${row.reversed ? 'line-through' : ''}`}>{formatQuantity(row.quantity)}</span>
                                        {row.reversed && (
                                            <span className="flex w-full items-center gap-2 text-xs">
                                                <Pill tone="info">{t('corr.w1.return_reversed')}</Pill>
                                                {t('corr.w1.return_reversed_on', { date: showDate(row.reversed_on) })}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    {can_view_journals && (
                        <Panel title={t('receipts.journal')}>
                            {journals.length === 0 ? (
                                <p className="text-muted-foreground mt-2 text-sm">-</p>
                            ) : (
                                <ul className="mt-2 grid gap-1.5 text-[13px]">
                                    {journals.map((journal) => (
                                        <li key={journal.id} className="flex flex-wrap justify-between gap-2">
                                            <Link href={`/accounting/journals/${journal.id}`} className="font-medium hover:underline">
                                                {journal.number}
                                            </Link>
                                            <span className="text-muted-foreground">
                                                {showDate(journal.date)}
                                                {journal.reversal && ` · ${t('receipts.reversal')}`}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    )}
                </div>
                <DocumentFiles files={files} baseUrl={`/purchasing/receipts/${receipt.id}/files`} canAttach={can_attach} />
            </div>
        </AppLayout>
    );
}
