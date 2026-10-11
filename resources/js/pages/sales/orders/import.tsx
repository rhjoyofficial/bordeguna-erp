import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, Fragment, useState } from 'react';

interface Problem {
    line: number | null;
    order_ref: string | null;
    field?: string;
    message: string;
}

interface PlannedLine {
    line: number;
    sku: string;
    name: string | null;
    quantity: string;
    unit_price: string;
    list_price: string | null;
    overridden: boolean;
    discount: string;
    net: string;
}

interface PlannedOrder {
    order_ref: string;
    line: number;
    order_date: string;
    brand: string | null;
    channel: string;
    customer_name: string;
    phone: string;
    customer: { id: number | null; name: string; existing: boolean };
    destination: string;
    lines: PlannedLine[];
    goods: string;
    order_discount: string;
    delivery_charge: string | null;
    total: string;
    advance: string;
    advance_method: string | null;
    payment_method: string;
    cod: string;
    weight_grams: number | null;
}

interface Preview {
    token: string;
    hash: string;
    file_name: string;
    rows: number;
    orders: PlannedOrder[];
    skipped: { order_ref: string; sales_order_id: number; order_number: string }[];
    errors: Problem[];
    warnings: Problem[];
    ignored_columns: string[];
    advance_methods: string[];
    totals: { orders: number; lines: number; new_customers: number; goods: string; total: string; advance: string };
}

interface ResultOrder {
    order_ref: string;
    outcome: 'created' | 'skipped' | 'failed';
    message: string | null;
    brand: string | null;
    channel: string | null;
    order: { id: number; order_number: string; status: string; waiting: boolean } | null;
}

interface Result {
    id: number;
    file_name: string;
    at: string | null;
    by: string | null;
    rows: number;
    created: number;
    skipped: number;
    failed: number;
    auto_approve: boolean;
    orders: ResultOrder[];
}

interface RecentImport {
    id: number;
    file_name: string;
    at: string | null;
    by: string | null;
    created: number;
    skipped: number;
    failed: number;
}

interface Column {
    name: string;
    level: string;
    help: string;
    required: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/orders' },
    { title: 'Orders', href: '/sales/orders' },
    { title: 'Import shop orders', href: '/sales/orders/import' },
];

function Stat({ label, value, tone }: { label: string; value: string | number; tone?: 'bad' | 'warn' | 'good' }) {
    const colour =
        tone === 'bad' && Number(value) > 0
            ? 'text-destructive'
            : tone === 'warn' && Number(value) > 0
              ? 'text-amber-600 dark:text-amber-400'
              : tone === 'good'
                ? 'text-emerald-600 dark:text-emerald-400'
                : '';

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-3">
            <div className={`text-2xl font-semibold tabular-nums ${colour}`}>{value}</div>
            <div className="text-muted-foreground text-xs">{label}</div>
        </div>
    );
}

/**
 * Sales > Orders > Import shop orders (11 Oct 2026): a sheet with one row per
 * order line, previewed with every problem named by row, then applied.
 */
export default function ShopOrderImport({
    preview,
    result,
    recent,
    columns,
    money_accounts,
    can,
    limits,
}: {
    preview: Preview | null;
    result: Result | null;
    recent: RecentImport[];
    columns: Column[];
    money_accounts: { id: number; name: string; type: string }[];
    can: { approve: boolean; override_price: boolean; record_receipt: boolean; book_parcels: boolean };
    limits: { max_rows: number; max_mb: number };
}) {
    const upload = useForm<{ file: File | null }>({ file: null });
    const apply = useForm<{ token: string; hash: string; file_name: string; auto_approve: boolean; mappings: Record<string, string> }>({
        token: '',
        hash: '',
        file_name: '',
        auto_approve: false,
        mappings: {},
    });
    const [showColumns, setShowColumns] = useState(false);
    const [open, setOpen] = useState<string | null>(null);

    const submitFile: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('sales.orders.import.preview'), { forceFormData: true });
    };

    const submitApply: FormEventHandler = (e) => {
        e.preventDefault();
        // The token is read from the preview on screen at the moment of Apply
        // (the price importer's lesson of 29 Sep 2026).
        apply.transform((data) => ({
            ...data,
            token: preview?.token ?? '',
            hash: preview?.hash ?? '',
            file_name: preview?.file_name ?? '',
        }));
        apply.post(route('sales.orders.import.store'));
    };

    const blocked = (preview?.errors.length ?? 0) > 0 || (preview?.orders.length ?? 0) === 0;
    const applyErrors = apply.errors as Record<string, string | undefined>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import shop orders" />
            <div className="flex flex-col gap-6 p-4">
                <div className="max-w-3xl">
                    <h1 className="text-lg font-semibold">Import shop orders</h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        One row per product line. Lines with the same <span className="font-mono">order_ref</span> are one order. An order number
                        already in the ERP is skipped, so sending the same sheet again is safe. Up to {limits.max_rows.toLocaleString()} rows,{' '}
                        {limits.max_mb} MB, .xlsx or .csv.
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('sales.orders.import.template', { format: 'xlsx' })}>Download template (.xlsx)</a>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('sales.orders.import.template', { format: 'csv' })}>Download template (.csv)</a>
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => setShowColumns(!showColumns)}>
                            {showColumns ? 'Hide the columns' : 'What each column takes'}
                        </Button>
                    </div>
                </div>

                {showColumns && (
                    <table className="w-full max-w-4xl text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-1 pr-3">Column</th>
                                <th className="py-1 pr-3">One per</th>
                                <th className="py-1">What it takes</th>
                            </tr>
                        </thead>
                        <tbody>
                            {columns.map((c) => (
                                <tr key={c.name} className="border-sidebar-border/40 border-b align-top">
                                    <td className="py-1 pr-3 font-mono">
                                        {c.name}
                                        {c.required && <span className="text-destructive"> *</span>}
                                    </td>
                                    <td className="text-muted-foreground py-1 pr-3">{c.level}</td>
                                    <td className="py-1">{c.help}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                <form onSubmit={submitFile} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="file" required>
                            Order sheet
                        </Label>
                        <input
                            id="file"
                            type="file"
                            accept=".xlsx,.csv,text/csv"
                            onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                            className="file:border-input file:bg-background text-sm file:mr-3 file:rounded-md file:border file:px-3 file:py-2 file:text-sm"
                        />
                        <InputError message={upload.errors.file} />
                    </div>
                    <Button disabled={upload.processing || !upload.data.file}>{upload.processing ? 'Reading' : 'Show me what it would do'}</Button>
                </form>

                {preview && (
                    <div className="flex flex-col gap-4">
                        <div className="text-muted-foreground text-sm">
                            <span className="text-foreground font-medium">{preview.file_name}</span>: {preview.rows} row(s).
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                            <Stat label="orders to create" value={preview.totals.orders} tone="good" />
                            <Stat label="lines" value={preview.totals.lines} />
                            <Stat label="new customers" value={preview.totals.new_customers} />
                            <Stat label="already imported" value={preview.skipped.length} />
                            <Stat label="order value" value={formatMoney(preview.totals.total)} />
                            <Stat label="advance paid" value={formatMoney(preview.totals.advance)} />
                            <Stat label="warnings" value={preview.warnings.length} tone="warn" />
                            <Stat label="errors" value={preview.errors.length} tone="bad" />
                        </div>

                        {preview.errors.length > 0 && (
                            <div className="border-destructive/40 rounded-lg border p-3">
                                <p className="text-destructive mb-2 text-sm font-medium">
                                    Fix these in the sheet and upload it again. Nothing is imported while any remain.
                                </p>
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground text-left">
                                            <th className="py-1 pr-3">Row</th>
                                            <th className="py-1 pr-3">Order</th>
                                            <th className="py-1 pr-3">Column</th>
                                            <th className="py-1">Problem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {preview.errors.map((p, i) => (
                                            <tr key={i} className="border-sidebar-border/40 border-t align-top">
                                                <td className="py-1 pr-3 tabular-nums">{p.line ?? '-'}</td>
                                                <td className="py-1 pr-3 font-mono">{p.order_ref ?? '-'}</td>
                                                <td className="py-1 pr-3 font-mono">{p.field}</td>
                                                <td className="py-1">{p.message}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {preview.warnings.length > 0 && (
                            <div className="rounded-lg border border-amber-500/40 p-3 text-sm">
                                <p className="mb-1 font-medium text-amber-700 dark:text-amber-400">Worth a look (these do not stop the import)</p>
                                <ul className="list-disc pl-5">
                                    {preview.warnings.map((w, i) => (
                                        <li key={i}>
                                            {w.line !== null && <span className="text-muted-foreground">Row {w.line}: </span>}
                                            {w.order_ref && <span className="font-mono">{w.order_ref} </span>}
                                            {w.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {preview.skipped.length > 0 && (
                            <div className="text-sm">
                                <p className="text-muted-foreground mb-1">Already in the ERP, skipped:</p>
                                <div className="flex flex-wrap gap-2">
                                    {preview.skipped.map((s) => (
                                        <Link
                                            key={s.order_ref}
                                            href={route('sales.orders.show', s.sales_order_id)}
                                            className="underline underline-offset-2"
                                        >
                                            {s.order_ref} as {s.order_number}
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        )}

                        {preview.orders.length > 0 && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                            <th className="py-2 pr-3">Shop order</th>
                                            <th className="py-2 pr-3">Date</th>
                                            <th className="py-2 pr-3">Brand</th>
                                            <th className="py-2 pr-3">Customer</th>
                                            <th className="py-2 pr-3">Goes to</th>
                                            <th className="py-2 pr-3 text-right">Goods</th>
                                            <th className="py-2 pr-3 text-right">Delivery</th>
                                            <th className="py-2 pr-3 text-right">Total</th>
                                            <th className="py-2 pr-3 text-right">Advance</th>
                                            <th className="py-2 text-right">To collect</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {preview.orders.map((o) => (
                                            <Fragment key={o.order_ref}>
                                                <tr
                                                    className="border-sidebar-border/40 hover:bg-muted/40 cursor-pointer border-b align-top"
                                                    onClick={() => setOpen(open === o.order_ref ? null : o.order_ref)}
                                                >
                                                    <td className="py-2 pr-3 font-mono">{o.order_ref}</td>
                                                    <td className="py-2 pr-3 tabular-nums">{o.order_date}</td>
                                                    <td className="py-2 pr-3">{o.brand ?? '-'}</td>
                                                    <td className="py-2 pr-3">
                                                        {o.customer_name}
                                                        <div className="text-muted-foreground text-xs">
                                                            {o.phone} · {o.customer.existing ? `existing: ${o.customer.name}` : 'new customer'} ·{' '}
                                                            {o.channel}
                                                        </div>
                                                    </td>
                                                    <td className="py-2 pr-3">{o.destination}</td>
                                                    <td className="py-2 pr-3 text-right tabular-nums">
                                                        {formatMoney(o.goods)}
                                                        <div className="text-muted-foreground text-xs">
                                                            {o.lines.length} line(s)
                                                            {Number(o.order_discount) > 0 ? `, less ${formatMoney(o.order_discount)}` : ''}
                                                        </div>
                                                    </td>
                                                    <td className="py-2 pr-3 text-right tabular-nums">{formatMoney(o.delivery_charge)}</td>
                                                    <td className="py-2 pr-3 text-right font-medium tabular-nums">{formatMoney(o.total)}</td>
                                                    <td className="py-2 pr-3 text-right tabular-nums">
                                                        {Number(o.advance) > 0 ? formatMoney(o.advance) : '-'}
                                                        {o.advance_method && <div className="text-muted-foreground text-xs">{o.advance_method}</div>}
                                                    </td>
                                                    <td className="py-2 text-right tabular-nums">
                                                        {formatMoney(o.cod)}
                                                        <div className="text-muted-foreground text-xs">{o.payment_method}</div>
                                                    </td>
                                                </tr>
                                                {open === o.order_ref && (
                                                    <tr className="bg-muted/30">
                                                        <td colSpan={10} className="px-3 py-2">
                                                            <table className="w-full text-xs">
                                                                <tbody>
                                                                    {o.lines.map((l) => (
                                                                        <tr key={l.line}>
                                                                            <td className="text-muted-foreground py-0.5 pr-3">Row {l.line}</td>
                                                                            <td className="py-0.5 pr-3 font-mono">{l.sku}</td>
                                                                            <td className="py-0.5 pr-3">{l.name}</td>
                                                                            <td className="py-0.5 pr-3 text-right tabular-nums">{l.quantity}</td>
                                                                            <td className="py-0.5 pr-3 text-right tabular-nums">
                                                                                x {formatMoney(l.unit_price)}
                                                                                {l.overridden && (
                                                                                    <Badge variant="outline" className="ml-1">
                                                                                        list {formatMoney(l.list_price)}
                                                                                    </Badge>
                                                                                )}
                                                                            </td>
                                                                            <td className="py-0.5 pr-3 text-right tabular-nums">
                                                                                {Number(l.discount) > 0 ? `less ${formatMoney(l.discount)}` : ''}
                                                                            </td>
                                                                            <td className="py-0.5 text-right tabular-nums">{formatMoney(l.net)}</td>
                                                                        </tr>
                                                                    ))}
                                                                    {o.weight_grams !== null && (
                                                                        <tr>
                                                                            <td colSpan={7} className="text-muted-foreground py-0.5">
                                                                                Parcel weight typed: {o.weight_grams} g
                                                                            </td>
                                                                        </tr>
                                                                    )}
                                                                </tbody>
                                                            </table>
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        <form onSubmit={submitApply} className="flex flex-col gap-4 rounded-lg border p-4">
                            {preview.advance_methods.length > 0 && (
                                <div className="flex flex-col gap-2">
                                    <p className="text-sm font-medium">Where did the advances go?</p>
                                    <p className="text-muted-foreground text-xs">
                                        Choose the account for each way of paying and the advance is recorded as a payment for its order. Left empty,
                                        the advance is only written into the order note.
                                        {!can.record_receipt && ' Recording payments needs the receipt permission, which your role does not have.'}
                                    </p>
                                    <div className="flex flex-wrap gap-4">
                                        {preview.advance_methods.map((method) => (
                                            <div key={method} className="grid gap-1">
                                                <Label htmlFor={`map-${method}`}>{method}</Label>
                                                <select
                                                    id={`map-${method}`}
                                                    disabled={!can.record_receipt}
                                                    className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                                    value={apply.data.mappings[method] ?? ''}
                                                    onChange={(e) => apply.setData('mappings', { ...apply.data.mappings, [method]: e.target.value })}
                                                >
                                                    <option value="">Do not record</option>
                                                    {money_accounts.map((a) => (
                                                        <option key={a.id} value={a.id}>
                                                            {a.name} ({a.type})
                                                        </option>
                                                    ))}
                                                </select>
                                                <InputError message={applyErrors[`mappings.${method}`]} />
                                            </div>
                                        ))}
                                    </div>
                                    <InputError message={applyErrors.mappings} />
                                </div>
                            )}

                            {can.approve && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={apply.data.auto_approve}
                                        onChange={(e) => apply.setData('auto_approve', e.target.checked)}
                                    />
                                    Approve the imported orders now (the credit rules still apply; one they refuse stays waiting)
                                </label>
                            )}
                            {!can.approve && (
                                <p className="text-muted-foreground text-sm">The imported orders wait for approval; approvers get one notice.</p>
                            )}

                            <div className="flex flex-wrap items-center gap-3">
                                <Button disabled={apply.processing || blocked}>
                                    {apply.processing ? 'Importing' : `Import ${preview.orders.length} order(s)`}
                                </Button>
                                {blocked && preview.errors.length > 0 && <span className="text-destructive text-sm">Fix the errors first.</span>}
                            </div>
                            <InputError message={applyErrors.file ?? applyErrors.token ?? applyErrors.auto_approve} />
                        </form>
                    </div>
                )}

                {result && (
                    <div className="flex flex-col gap-3">
                        <h2 className="font-semibold">
                            Import #{result.id}: {result.file_name}
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {result.at} by {result.by ?? 'someone'}, {result.rows} row(s).
                            {result.auto_approve ? ' Approved on import where the credit rules allowed.' : ''}
                        </p>
                        <div className="grid grid-cols-3 gap-3 sm:w-fit">
                            <Stat label="created" value={result.created} tone="good" />
                            <Stat label="already there" value={result.skipped} />
                            <Stat label="failed" value={result.failed} tone="bad" />
                        </div>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-3">Shop order</th>
                                    <th className="py-2 pr-3">Outcome</th>
                                    <th className="py-2 pr-3">ERP order</th>
                                    <th className="py-2 pr-3">Brand</th>
                                    <th className="py-2">Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                {result.orders.map((o) => (
                                    <tr key={o.order_ref} className="border-sidebar-border/40 border-b align-top">
                                        <td className="py-2 pr-3 font-mono">{o.order_ref}</td>
                                        <td className="py-2 pr-3">
                                            <Badge
                                                variant={o.outcome === 'failed' ? 'destructive' : o.outcome === 'created' ? 'default' : 'secondary'}
                                            >
                                                {o.outcome}
                                            </Badge>
                                        </td>
                                        <td className="py-2 pr-3">
                                            {o.order ? (
                                                <Link href={route('sales.orders.show', o.order.id)} className="underline underline-offset-2">
                                                    {o.order.order_number}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                            {o.order?.waiting && <div className="text-muted-foreground text-xs">waiting for approval</div>}
                                        </td>
                                        <td className="py-2 pr-3">{o.brand ?? '-'}</td>
                                        <td className="py-2">{o.message}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {can.book_parcels && (
                            <Button variant="outline" className="w-fit" asChild>
                                <Link href={route('sales.parcels-to-book.index')}>Go to parcels to book</Link>
                            </Button>
                        )}
                    </div>
                )}

                {recent.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h2 className="text-sm font-semibold">Recent imports</h2>
                        <ul className="text-sm">
                            {recent.map((r) => (
                                <li key={r.id}>
                                    <Link href={route('sales.orders.imports.show', r.id)} className="underline underline-offset-2">
                                        #{r.id} {r.file_name}
                                    </Link>{' '}
                                    <span className="text-muted-foreground">
                                        {r.at}, {r.by ?? 'someone'}: {r.created} created, {r.skipped} already there
                                        {r.failed > 0 ? `, ${r.failed} failed` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
