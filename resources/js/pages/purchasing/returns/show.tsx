import { Panel, Pill } from '@/components/assets/assets-ui';
import { type Reversal } from '@/components/purchasing/reverse-document';
import { CorrectionButtons, type CorrectionsProps } from '@/components/purchasing/supplier-money-corrections';
import { RecordHistory } from '@/components/record-history';
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
    quantity_returned: string;
    unit: string | null;
    value: string | null;
    batch: { id: number; batch_number: string | null; expiry_date: string | null } | null;
    receipt: { id: number; received_on: string | null; challan: string | null } | null;
}

/**
 * ⭐ One purchase return (purchase redesign PR2, 18 Oct 2026; Tally's
 * Rejection Out): what went back, from which batch and delivery, the credit
 * notes against it and its journal. Reads only.
 */
export default function ReturnShow({
    purchaseReturn,
    lines,
    credit_notes,
    journals,
    can_view_journals,
    can_view_batches,
    can_record_credit_note,
    corrections = null,
    reversal = null,
}: {
    purchaseReturn: {
        id: number;
        returned_on: string | null;
        returned_by: string | null;
        note: string | null;
        value: string;
        credited: string;
        order: { id: number; po_number: string } | null;
        supplier: { id: number; name: string } | null;
        warehouse: string | null;
    };
    lines: Line[];
    credit_notes: { id: number; number: string; date: string | null; total: string; reversed: boolean }[];
    journals: { id: number; number: string; date: string | null; reversal: boolean }[];
    can_view_journals: boolean;
    can_view_batches: boolean;
    can_record_credit_note: boolean;
    /** Corrections wave 1 (20 Oct 2026): Reverse (goods back into the same batches). */
    corrections?: CorrectionsProps | null;
    reversal?: Reversal | null;
}) {
    const { t } = useTranslation();
    const title = t('returns.return_no', { id: purchaseReturn.id });
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: t('returns.title'), href: '/purchasing/returns' },
        { title, href: `/purchasing/returns/${purchaseReturn.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex flex-col gap-4 p-4">
                <section className="bg-card rounded-xl border p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-lg font-semibold">{title}</p>
                            <p className="text-muted-foreground text-sm">
                                {purchaseReturn.supplier ? (
                                    <Link href={`/purchasing/suppliers/${purchaseReturn.supplier.id}`} className="text-foreground hover:underline">
                                        {purchaseReturn.supplier.name}
                                    </Link>
                                ) : (
                                    '-'
                                )}
                                {purchaseReturn.order && (
                                    <>
                                        {' · '}
                                        <Link
                                            href={`/purchasing/purchase-orders/${purchaseReturn.order.id}`}
                                            className="text-foreground hover:underline"
                                        >
                                            {purchaseReturn.order.po_number}
                                        </Link>
                                    </>
                                )}
                                {purchaseReturn.warehouse && ` · ${purchaseReturn.warehouse}`}
                            </p>
                        </div>
                        <div className="flex flex-col items-end gap-2">
                            <CorrectionButtons
                                corrections={corrections}
                                documentId={purchaseReturn.id}
                                number={title}
                                redirectTo={`/purchasing/returns/${purchaseReturn.id}`}
                            />
                            {/* 24 Oct 2026 (wave 1 gaps): who recorded or reversed it. */}
                            <RecordHistory type="purchasing.purchase_return" id={purchaseReturn.id} label={title} />
                            {!reversal && corrections?.actions.some((a) => a.action === 'reverse' && (a.allowed || a.requestable)) && (
                                <p className="text-muted-foreground max-w-72 text-right text-xs">{t('corr.sup.return_reverse_help')}</p>
                            )}
                        </div>
                        {can_record_credit_note && !reversal && purchaseReturn.supplier && (
                            <Button size="sm" asChild>
                                <Link href={`/purchasing/credit-notes/new?supplier_id=${purchaseReturn.supplier.id}`}>
                                    {t('returns.record_credit_note')}
                                </Link>
                            </Button>
                        )}
                    </div>
                    <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm md:grid-cols-4">
                        <dt className="text-muted-foreground">{t('returns.col_date')}</dt>
                        <dd>{showDate(purchaseReturn.returned_on) || '-'}</dd>
                        <dt className="text-muted-foreground">{t('returns.returned_by')}</dt>
                        <dd>{purchaseReturn.returned_by ?? '-'}</dd>
                        <dt className="text-muted-foreground">{t('receipts.col_value')}</dt>
                        <dd className="font-medium tabular-nums">{purchaseReturn.value}</dd>
                        <dt className="text-muted-foreground">{t('returns.col_credited')}</dt>
                        <dd className="tabular-nums">{purchaseReturn.credited}</dd>
                    </dl>
                    {purchaseReturn.note && <p className="text-muted-foreground mt-2 text-sm">{purchaseReturn.note}</p>}
                    {reversal && (
                        <div className="border-destructive/40 bg-destructive/10 mt-3 rounded border px-3 py-2 text-sm">
                            <div className="text-destructive font-semibold">{t('corr.sup.reversed', { date: showDate(reversal.on) })}</div>
                            {reversal.reason && <div className="text-muted-foreground">{reversal.reason}</div>}
                        </div>
                    )}
                </section>

                <Panel title={t('returns.lines')} flush>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[40rem] text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3.5 [&_tbody_td]:py-2.5 [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th>{t('receipts.col_product')}</th>
                                    <th className="text-right!">{t('returns.col_quantity')}</th>
                                    <th className="text-right!">{t('receipts.col_value')}</th>
                                    <th>{t('receipts.col_batch')}</th>
                                    <th>{t('returns.col_from_receipt')}</th>
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
                                            {formatQuantity(line.quantity_returned)} {line.unit ?? ''}
                                        </td>
                                        <td className="text-right tabular-nums">{line.value ?? '-'}</td>
                                        <td className="whitespace-nowrap">
                                            {line.batch ? (
                                                can_view_batches ? (
                                                    <Link href={`/inventory/batches/${line.batch.id}`} className="hover:underline">
                                                        {line.batch.batch_number ?? `#${line.batch.id}`}
                                                    </Link>
                                                ) : (
                                                    (line.batch.batch_number ?? `#${line.batch.id}`)
                                                )
                                            ) : (
                                                '-'
                                            )}
                                            {line.batch?.expiry_date && (
                                                <div className="text-muted-foreground text-xs">{formatDate(line.batch.expiry_date)}</div>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap">
                                            {line.receipt ? (
                                                <Link href={`/purchasing/receipts/${line.receipt.id}`} className="hover:underline">
                                                    {t('receipts.receipt_no', { id: line.receipt.id })}, {showDate(line.receipt.received_on)}
                                                    {line.receipt.challan && `, challan ${line.receipt.challan}`}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title={t('returns.credit_notes')}>
                        {credit_notes.length === 0 ? (
                            <p className="text-muted-foreground mt-2 text-sm">{t('returns.no_credit_notes')}</p>
                        ) : (
                            <ul className="mt-2 grid gap-1.5 text-[13px]">
                                {credit_notes.map((note) => (
                                    <li key={note.id} className="flex flex-wrap justify-between gap-2">
                                        <Link href={`/purchasing/credit-notes/${note.id}`} className="font-medium hover:underline">
                                            {note.number}
                                        </Link>
                                        <span className="text-muted-foreground">{showDate(note.date)}</span>
                                        <span className="tabular-nums">
                                            {note.total}
                                            {note.reversed && (
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
            </div>
        </AppLayout>
    );
}
