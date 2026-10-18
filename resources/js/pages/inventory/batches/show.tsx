import InputError from '@/components/input-error';
import { CompactTable, inventoryCrumbs, Panel, Pill } from '@/components/inventory/inventory-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { Download, PauseCircle, PlayCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface Source {
    kind: 'production' | 'receipt' | 'opening' | 'other';
    run_id?: number;
    run_number?: string;
    run_status?: string;
    recipe?: string | null;
    supplier?: string | null;
    purchase_order_id?: number | null;
    po_number?: string | null;
    received_by?: string | null;
    supplier_challan?: string | null;
    reason?: string | null;
    by?: string | null;
    note?: string | null;
    date?: string | null;
    inputs?: Input[];
}

interface Input {
    batch_id: number | null;
    batch_number: string | null;
    product: string | null;
    sku: string | null;
    unit: string | null;
    quantity: string;
    made_on: string | null;
    expiry_date: string | null;
    from: Source | null;
}

interface Place {
    id: number;
    warehouse: string | null;
    on_hand: string;
    received: string;
    status: string;
    held: boolean;
    held_at: string | null;
    held_by: string | null;
    hold_reason: string | null;
    is_origin: boolean;
}

interface RunRow {
    id: number;
    run_number: string;
    status: string;
    date: string | null;
    used: string;
    output_product: string | null;
    output_quantity: string;
    output_batch_id: number | null;
    output_batch_number: string | null;
    output_expiry_date: string | null;
}

interface Recipient {
    via_run: { id: number; run_number: string } | null;
    batch_number: string | null;
    product: string | null;
    customer_id: number | null;
    customer: string | null;
    phone: string | null;
    recipient: string | null;
    recipient_phone: string | null;
    delivery_id: number | null;
    delivery_number: string | null;
    date: string | null;
    warehouse: string | null;
    quantity: string;
    returned: string;
    invoices: { id: number; number: string }[];
    consignment: string | null;
    courier: string | null;
    parcel_status: string | null;
}

interface ReturnRow {
    return_id: number | null;
    return_number: string | null;
    date: string | null;
    customer: string | null;
    delivery_number: string | null;
    quantity: string;
    back_to_stock: boolean;
}

interface HistoryRow {
    id: number;
    date: string | null;
    warehouse: string | null;
    reason: string;
    direction: string;
    quantity: string;
    note: string | null;
    by: string | null;
    undoes: boolean;
}

interface HoldRow {
    id: number;
    action: 'hold' | 'release';
    reason: string;
    by: string | null;
    at: string | null;
    warehouse: string | null;
}

interface Batch {
    id: number;
    batch_number: string | null;
    product: string | null;
    product_id?: number | null;
    sku: string | null;
    brand: string | null;
    unit: string | null;
    warehouse: string | null;
    manufactured_date: string | null;
    expiry_date: string | null;
    mrp: string | null;
    received_at: string | null;
    held: boolean;
    origin_id: number;
}

interface Can {
    hold: boolean;
    see_customers: boolean;
    see_runs: boolean;
    see_purchases: boolean;
}

/** Plain words for a movement's reason. */
const REASON: Record<string, string> = {
    receipt: 'Received',
    usage: 'Used',
    sale: 'Sold (challan)',
    adjustment: 'Stock correction',
    transfer: 'Transfer',
    purchase_return: 'Returned to supplier',
    sales_return: 'Customer return',
    production: 'Production run',
};

/**
 * ⭐ One batch, backward and forward (traceability M3, 17 Oct 2026).
 *
 * Backward: for a jar, the run that made it and the tins it used, with their
 * supplier, PO and supplier challan; for a tin, its receipt. Forward: the runs
 * that used it, every challan and customer that got it (or what was made from
 * it), returns back onto it, and what is still on the shelf where. Nothing on
 * this page is typed for it; it reads what the ERP already recorded.
 */
export default function BatchShow({
    batch,
    backward,
    places,
    runs,
    recipients,
    returns,
    history,
    holds,
    can,
}: {
    batch: Batch;
    backward: Source;
    places: Place[];
    runs: RunRow[];
    recipients: Recipient[];
    returns: ReturnRow[];
    history: HistoryRow[];
    holds: HoldRow[];
    can: Can;
}) {
    const label = batch.batch_number ?? 'No number';
    const breadcrumbs = inventoryCrumbs('Stock', 'Batches', { title: label, href: `/inventory/batches/${batch.id}` });
    const [dialog, setDialog] = useState<{ action: 'hold' | 'release'; batchId: number; everywhere: boolean; where: string } | null>(null);

    const anyHeld = places.some((p) => p.held);
    const allHeld = places.length > 0 && places.every((p) => p.held);
    const sent = recipients.reduce((sum, r) => sum + Number(r.quantity), 0);
    const back = recipients.reduce((sum, r) => sum + Number(r.returned), 0);
    const onHand = places.reduce((sum, p) => sum + Number(p.on_hand), 0);
    const isRaw = runs.length > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Batch ${label}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold">
                            <span className="font-mono">{label}</span>
                            {anyHeld && <Pill tone="warning">{allHeld ? 'On hold' : 'Partly on hold'}</Pill>}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {batch.product_id ? (
                                <Link href={`/inventory/products/${batch.product_id}`} className="underline-offset-2 hover:underline">
                                    {batch.product}
                                </Link>
                            ) : (
                                batch.product
                            )}{' '}
                            <span className="font-mono text-xs">{batch.sku}</span>
                            {batch.brand && ` · ${batch.brand}`}
                        </p>
                        <p className="mt-1 text-sm">
                            <Fact label="MFG">{formatDate(batch.manufactured_date)}</Fact>
                            <Fact label="EXP">{formatDate(batch.expiry_date)}</Fact>
                            <Fact label="MRP">{batch.mrp ? `Tk ${formatMoney(batch.mrp)}` : '-'}</Fact>
                            <Fact label="On hand now">
                                {formatQuantity(onHand)} {batch.unit}
                            </Fact>
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a href={`/inventory/batches/${batch.id}/recall-list`}>
                                <Download className="size-4" />
                                Download recall list
                            </a>
                        </Button>
                        {can.hold && !allHeld && (
                            <Button onClick={() => setDialog({ action: 'hold', batchId: batch.id, everywhere: true, where: 'every warehouse' })}>
                                <PauseCircle className="size-4" />
                                Put on hold
                            </Button>
                        )}
                        {can.hold && anyHeld && (
                            <Button
                                variant="outline"
                                onClick={() => setDialog({ action: 'release', batchId: batch.id, everywhere: true, where: 'every warehouse' })}
                            >
                                <PlayCircle className="size-4" />
                                Release
                            </Button>
                        )}
                    </div>
                </div>

                {anyHeld && (
                    <div className="rounded-lg border border-amber-500/50 bg-amber-500/10 px-3 py-2 text-sm">
                        {places
                            .filter((p) => p.held)
                            .map((p) => (
                                <p key={p.id}>
                                    <span className="font-medium">On hold in {p.warehouse}</span> since {formatDateTime(p.held_at)}
                                    {p.held_by && ` by ${p.held_by}`}: {p.hold_reason}. Sales, challans, transfers and production runs will not pick
                                    it.
                                </p>
                            ))}
                    </div>
                )}

                {/* Backward */}
                <Panel title="Where it came from">
                    <SourceLine source={backward} can={can} />
                    {backward.kind === 'production' && backward.inputs && backward.inputs.length > 0 && (
                        <div className="-mx-4 mt-3 -mb-4 border-t">
                            <CompactTable minWidth="56rem">
                                <thead>
                                    <tr className="text-muted-foreground">
                                        <th>Input</th>
                                        <th>Batch used</th>
                                        <th className="text-right">Quantity</th>
                                        <th>Made / expiry</th>
                                        <th>From</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {backward.inputs.map((input, i) => (
                                        <tr key={`${input.batch_id}-${i}`}>
                                            <td>
                                                {input.product} <span className="text-muted-foreground font-mono text-xs">{input.sku}</span>
                                            </td>
                                            <td className="font-mono text-xs">
                                                {input.batch_id ? (
                                                    <Link
                                                        href={`/inventory/batches/${input.batch_id}`}
                                                        className="underline-offset-2 hover:underline"
                                                    >
                                                        {input.batch_number ?? 'No number'}
                                                    </Link>
                                                ) : (
                                                    '-'
                                                )}
                                            </td>
                                            <td className="text-right tabular-nums">
                                                {formatQuantity(input.quantity)} {input.unit}
                                            </td>
                                            <td className="text-muted-foreground whitespace-nowrap">
                                                {formatDate(input.made_on)} / {formatDate(input.expiry_date)}
                                            </td>
                                            <td>{input.from ? <SourceLine source={input.from} can={can} compact /> : '-'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </CompactTable>
                        </div>
                    )}
                </Panel>

                {/* Where it is now */}
                <Panel title="Where it is now" flush>
                    <CompactTable minWidth="40rem">
                        <thead>
                            <tr className="text-muted-foreground">
                                <th>Warehouse</th>
                                <th className="text-right">Came in</th>
                                <th className="text-right">On hand</th>
                                <th>State</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {places.map((p) => (
                                <tr key={p.id}>
                                    <td>
                                        {p.id === batch.id ? (
                                            <span className="font-medium">{p.warehouse}</span>
                                        ) : (
                                            <Link href={`/inventory/batches/${p.id}`}>{p.warehouse}</Link>
                                        )}
                                        {p.is_origin ? '' : <span className="text-muted-foreground text-xs"> (by transfer)</span>}
                                    </td>
                                    <td className="text-right tabular-nums">{formatQuantity(p.received)}</td>
                                    <td className="text-right tabular-nums">{formatQuantity(p.on_hand)}</td>
                                    <td>
                                        {p.held ? (
                                            <Pill tone="warning">On hold</Pill>
                                        ) : Number(p.on_hand) > 0 ? (
                                            <Pill tone="ok">In stock</Pill>
                                        ) : (
                                            <Pill>Used up</Pill>
                                        )}
                                    </td>
                                    <td className="text-right">
                                        {can.hold && places.length > 1 && Number(p.on_hand) > 0 && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setDialog({
                                                        action: p.held ? 'release' : 'hold',
                                                        batchId: p.id,
                                                        everywhere: false,
                                                        where: p.warehouse ?? 'this warehouse',
                                                    })
                                                }
                                            >
                                                {p.held ? 'Release here' : 'Hold here'}
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </CompactTable>
                </Panel>

                {/* Forward: runs */}
                {isRaw && (
                    <Panel title="Production runs that used it" flush>
                        <CompactTable minWidth="48rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>Run</th>
                                    <th>Date</th>
                                    <th className="text-right">Used</th>
                                    <th>Made</th>
                                    <th>Output batch</th>
                                </tr>
                            </thead>
                            <tbody>
                                {runs.map((r) => (
                                    <tr key={r.id}>
                                        <td className="font-mono text-xs whitespace-nowrap">
                                            {can.see_runs ? <Link href={`/inventory/production-runs/${r.id}`}>{r.run_number}</Link> : r.run_number}
                                            {r.status !== 'confirmed' && (
                                                <Pill tone="muted" className="ml-2 font-sans">
                                                    {r.status}
                                                </Pill>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap">{formatDate(r.date)}</td>
                                        <td className="text-right tabular-nums">
                                            {formatQuantity(r.used)} {batch.unit}
                                        </td>
                                        <td>
                                            {formatQuantity(r.output_quantity)} x {r.output_product}
                                        </td>
                                        <td className="font-mono text-xs">
                                            {r.output_batch_id ? (
                                                <Link href={`/inventory/batches/${r.output_batch_id}`} className="underline-offset-2 hover:underline">
                                                    {r.output_batch_number}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                            {r.output_expiry_date && (
                                                <span className="text-muted-foreground font-sans"> exp {formatDate(r.output_expiry_date)}</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </CompactTable>
                    </Panel>
                )}

                {/* Forward: customers */}
                <Panel
                    title={isRaw ? 'Who received what was made from it' : 'Who received it'}
                    aside={
                        recipients.length > 0 && (
                            <span className="text-muted-foreground text-xs">
                                {formatQuantity(sent)} sent on {recipients.length} challan line{recipients.length === 1 ? '' : 's'}
                                {back > 0 && `, ${formatQuantity(back)} came back`}
                            </span>
                        )
                    }
                    flush
                >
                    {recipients.length === 0 ? (
                        <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing from this batch has gone to a customer yet.</p>
                    ) : (
                        <CompactTable minWidth="70rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>Date</th>
                                    <th>Challan</th>
                                    <th>Customer</th>
                                    {can.see_customers && <th>Phone</th>}
                                    <th className="text-right">Quantity</th>
                                    <th className="text-right">Returned</th>
                                    <th>Invoice</th>
                                    <th>Parcel</th>
                                    {isRaw && <th>Batch</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {recipients.map((r, i) => (
                                    <tr key={`${r.delivery_id}-${r.batch_number}-${i}`}>
                                        <td className="whitespace-nowrap">{formatDate(r.date)}</td>
                                        <td className="font-mono text-xs whitespace-nowrap">
                                            {can.see_customers && r.delivery_id ? (
                                                <a href={`/sales/deliveries/${r.delivery_id}/print`} target="_blank" rel="noopener noreferrer">
                                                    {r.delivery_number}
                                                </a>
                                            ) : (
                                                r.delivery_number
                                            )}
                                            <div className="text-muted-foreground font-sans">{r.warehouse}</div>
                                        </td>
                                        <td>
                                            {can.see_customers && r.customer_id ? (
                                                <Link href={`/sales/customers/${r.customer_id}`}>{r.customer}</Link>
                                            ) : (
                                                r.customer
                                            )}
                                            {r.recipient && r.recipient !== r.customer && (
                                                <div className="text-muted-foreground text-xs">to {r.recipient}</div>
                                            )}
                                        </td>
                                        {can.see_customers && (
                                            <td className="whitespace-nowrap tabular-nums">
                                                {r.phone ?? '-'}
                                                {r.recipient_phone && r.recipient_phone !== r.phone && (
                                                    <div className="text-muted-foreground text-xs">{r.recipient_phone}</div>
                                                )}
                                            </td>
                                        )}
                                        <td className="text-right tabular-nums">{formatQuantity(r.quantity)}</td>
                                        <td className="text-right tabular-nums">{Number(r.returned) > 0 ? formatQuantity(r.returned) : '-'}</td>
                                        <td className="font-mono text-xs">
                                            {r.invoices.length === 0
                                                ? '-'
                                                : r.invoices.map((inv) =>
                                                      can.see_customers ? (
                                                          <div key={inv.id}>
                                                              <Link href={`/sales/invoices/${inv.id}`}>{inv.number}</Link>
                                                          </div>
                                                      ) : (
                                                          <div key={inv.id}>{inv.number}</div>
                                                      ),
                                                  )}
                                        </td>
                                        <td className="text-xs">
                                            {r.consignment ? (
                                                <>
                                                    <span className="font-mono">{r.consignment}</span>
                                                    <div className="text-muted-foreground">
                                                        {r.courier}
                                                        {r.parcel_status && ` · ${r.parcel_status.replace(/_/g, ' ')}`}
                                                    </div>
                                                </>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        {isRaw && (
                                            <td className="font-mono text-xs whitespace-nowrap">
                                                {r.batch_number}
                                                {r.via_run && <div className="text-muted-foreground font-sans">from {r.via_run.run_number}</div>}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </CompactTable>
                    )}
                </Panel>

                {returns.length > 0 && (
                    <Panel title="Returns back onto this batch" flush>
                        <CompactTable minWidth="40rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>Date</th>
                                    <th>Return</th>
                                    <th>Customer</th>
                                    <th>Challan</th>
                                    <th className="text-right">Quantity</th>
                                    <th>Went</th>
                                </tr>
                            </thead>
                            <tbody>
                                {returns.map((r, i) => (
                                    <tr key={`${r.return_id}-${i}`}>
                                        <td className="whitespace-nowrap">{formatDate(r.date)}</td>
                                        <td className="font-mono text-xs">
                                            {can.see_customers && r.return_id ? (
                                                <Link href={`/sales/returns/${r.return_id}`}>{r.return_number}</Link>
                                            ) : (
                                                r.return_number
                                            )}
                                        </td>
                                        <td>{r.customer}</td>
                                        <td className="font-mono text-xs">{r.delivery_number}</td>
                                        <td className="text-right tabular-nums">{formatQuantity(r.quantity)}</td>
                                        <td>{r.back_to_stock ? 'Back to stock' : 'Written off'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </CompactTable>
                    </Panel>
                )}

                {holds.length > 0 && (
                    <Panel title="Hold history">
                        <ul className="mt-2 space-y-1 text-sm">
                            {holds.map((h) => (
                                <li key={h.id}>
                                    <span className="text-muted-foreground tabular-nums">{formatDateTime(h.at)}</span>{' '}
                                    <span className="font-medium">{h.action === 'hold' ? 'Put on hold' : 'Released'}</span> in {h.warehouse}
                                    {h.by && ` by ${h.by}`}: {h.reason}
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                <Panel title="Every stock movement on this batch" flush>
                    <CompactTable minWidth="48rem">
                        <thead>
                            <tr className="text-muted-foreground">
                                <th>Date</th>
                                <th>Warehouse</th>
                                <th>What</th>
                                <th className="text-right">In</th>
                                <th className="text-right">Out</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.map((m) => (
                                <tr key={m.id}>
                                    <td className="whitespace-nowrap">{formatDate(m.date)}</td>
                                    <td className="text-muted-foreground">{m.warehouse}</td>
                                    <td>
                                        {REASON[m.reason] ?? m.reason}
                                        {m.undoes && <span className="text-muted-foreground text-xs"> (undo)</span>}
                                    </td>
                                    <td className="text-right tabular-nums">{m.direction === 'in' ? formatQuantity(m.quantity) : ''}</td>
                                    <td className="text-right tabular-nums">{m.direction === 'out' ? formatQuantity(m.quantity) : ''}</td>
                                    <td className="text-muted-foreground text-xs">
                                        {m.note}
                                        {m.by && <span> · {m.by}</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </CompactTable>
                </Panel>
            </div>

            {dialog && <HoldDialog key={`${dialog.action}-${dialog.batchId}`} {...dialog} label={label} onClose={() => setDialog(null)} />}
        </AppLayout>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <span className="mr-4 inline-block whitespace-nowrap">
            <span className="text-muted-foreground">{label}</span> <span className="tabular-nums">{children}</span>
        </span>
    );
}

/** A receipt, a run, opening stock: one line in plain words. */
function SourceLine({ source, can, compact = false }: { source: Source; can: Can; compact?: boolean }) {
    if (source.kind === 'production') {
        return (
            <p className={compact ? 'text-xs' : 'mt-2 text-sm'}>
                Made in production run{' '}
                {can.see_runs && source.run_id ? (
                    <Link href={`/inventory/production-runs/${source.run_id}`} className="font-mono underline underline-offset-2">
                        {source.run_number}
                    </Link>
                ) : (
                    <span className="font-mono">{source.run_number}</span>
                )}{' '}
                on {formatDate(source.date)}
                {source.recipe && `, recipe ${source.recipe}`}
                {source.run_status && source.run_status !== 'confirmed' && ` (${source.run_status})`}.{!compact && ' The batches it used:'}
            </p>
        );
    }

    if (source.kind === 'receipt') {
        return (
            <p className={compact ? 'text-xs' : 'mt-2 text-sm'}>
                <span className="font-medium">{source.supplier ?? 'Supplier not named'}</span>
                {', '}
                {can.see_purchases && source.purchase_order_id ? (
                    <Link href={`/purchasing/purchase-orders/${source.purchase_order_id}`} className="font-mono underline underline-offset-2">
                        {source.po_number}
                    </Link>
                ) : (
                    <span className="font-mono">{source.po_number}</span>
                )}
                {`, received ${formatDate(source.date)}`}
                {source.received_by && ` by ${source.received_by}`}
                {source.supplier_challan && `, supplier challan ${source.supplier_challan}`}
            </p>
        );
    }

    return (
        <p className={compact ? 'text-xs' : 'mt-2 text-sm'}>
            {source.kind === 'opening' ? 'Opening stock' : (REASON[source.reason ?? ''] ?? 'Stock in')}
            {source.date && `, ${formatDate(source.date)}`}
            {source.by && ` by ${source.by}`}
            {source.kind === 'other' && source.note && ` (${source.note})`}
        </p>
    );
}

function HoldDialog({
    action,
    batchId,
    everywhere,
    where,
    label,
    onClose,
}: {
    action: 'hold' | 'release';
    batchId: number;
    everywhere: boolean;
    where: string;
    label: string;
    onClose: () => void;
}) {
    const { data, setData, post, errors, processing } = useForm({ reason: '', everywhere });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/inventory/batches/${batchId}/${action}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>{action === 'hold' ? `Put batch ${label} on hold` : `Release batch ${label}`}</DialogTitle>
                        <DialogDescription>
                            {action === 'hold'
                                ? 'Nothing will pick it for the counter, a challan, a transfer or a production run until it is released. It stays in stock at its cost.'
                                : 'It can be picked again, oldest expiry first.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label htmlFor="hold-reason">Reason</Label>
                        <textarea
                            id="hold-reason"
                            required
                            rows={3}
                            maxLength={500}
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            placeholder={
                                action === 'hold'
                                    ? 'e.g. customer complaint about taste, waiting for the lab report'
                                    : 'e.g. lab report clear, dated 20 Oct'
                            }
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        />
                        <InputError message={errors.reason} />
                    </div>
                    {everywhere ? (
                        <p className="text-muted-foreground text-sm">This applies to the batch in every warehouse.</p>
                    ) : (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.everywhere} onCheckedChange={(v) => setData('everywhere', v === true)} />
                            Every warehouse, not only {where}
                        </label>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {action === 'hold' ? 'Put on hold' : 'Release'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
