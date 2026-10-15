import InputError from '@/components/input-error';
import { ActionsRow, CourierSettingsLink } from '@/components/sales/sales-ui';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface OrderRow {
    id: number;
    order_number: string;
    shop_reference: string | null;
    ordered_at: string | null;
    customer: string | null;
    recipient: string | null;
    phone: string | null;
    place: string;
    brand: string | null;
    kind: 'challan' | 'book';
    delivery_number: string | null;
    weight_grams: number | null;
    cod: number;
    default_account_id: number | null;
    destinations: Record<string, string | null>;
    in_flight: boolean;
    open_booking: boolean;
}

interface Account {
    id: number;
    label: string;
    provider_name: string;
    store_name: string | null;
    is_default: boolean;
}

interface RunSummary {
    id: number;
    status: string;
    total: number;
    done: number;
    booked: number;
    refused: number;
    unknown: number;
    fix_address: number;
    account: string | null;
    by: string | null;
    at: string | null;
    finished: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Parcels to book', href: '/sales/parcels-to-book' },
];

/**
 * Sales > Parcels to book (11 Oct 2026): approved orders waiting for a courier.
 * Tick, press once: challans are made and every parcel is booked in a queued
 * run, with each order's answer on the next page.
 */
export default function ParcelsToBook({
    orders,
    fix_addresses,
    accounts,
    warehouses,
    runs,
    can_manage_locations,
    max_orders,
}: {
    orders: OrderRow[];
    fix_addresses: { id: number; order_number: string; place: string; message: string }[];
    accounts: Account[];
    warehouses: { id: number; name: string; code: string | null }[];
    runs: RunSummary[];
    can_manage_locations: boolean;
    max_orders: number;
}) {
    const [search, setSearch] = useState('');
    const form = useForm<{ order_ids: number[]; courier_account_id: string; warehouse_id: string }>({
        order_ids: [],
        courier_account_id: '',
        warehouse_id: warehouses.length === 1 ? String(warehouses[0].id) : '',
    });

    const accountFor = (row: OrderRow): number | null =>
        form.data.courier_account_id ? Number(form.data.courier_account_id) : row.default_account_id;
    const problemFor = (row: OrderRow): string | null => {
        const id = accountFor(row);
        if (id === null) return 'No courier account can book this order.';
        return row.destinations[String(id)] ?? null;
    };

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (q === '') return orders;
        return orders.filter((o) =>
            [o.order_number, o.shop_reference, o.customer, o.recipient, o.phone, o.place, o.brand].some((v) => (v ?? '').toLowerCase().includes(q)),
        );
    }, [orders, search]);

    const selectable = visible.filter((o) => !o.in_flight && !o.open_booking && problemFor(o) === null);
    const selected = new Set(form.data.order_ids);
    const allSelected = selectable.length > 0 && selectable.every((o) => selected.has(o.id));

    const toggle = (id: number) =>
        form.setData('order_ids', selected.has(id) ? form.data.order_ids.filter((x) => x !== id) : [...form.data.order_ids, id].slice(0, max_orders));
    const toggleAll = () => form.setData('order_ids', allSelected ? [] : selectable.slice(0, max_orders).map((o) => o.id));

    const submit = () => {
        // Rows that became unbookable after a courier change are dropped here.
        const ids = form.data.order_ids.filter((id) => selectable.some((o) => o.id === id));
        form.transform((data) => ({
            order_ids: ids,
            courier_account_id: data.courier_account_id === '' ? null : Number(data.courier_account_id),
            warehouse_id: data.warehouse_id === '' ? null : Number(data.warehouse_id),
        }));
        form.post(route('sales.parcels-to-book.store'));
    };

    const accountName = (id: number | null) => accounts.find((a) => a.id === id)?.label ?? '-';
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Parcels to book" />
            <div className="flex flex-col gap-4 p-4">
                <ActionsRow
                    note={
                        <span className="block max-w-3xl">
                            Approved orders that no courier has yet. One press makes the challan for everything ordered (or uses the order's challan),
                            then books each parcel with the courier and lists every answer. An order short of stock, or going somewhere the courier
                            has no zone for yet, is refused by name and nothing moves for it.
                        </span>
                    }
                >
                    <CourierSettingsLink />
                </ActionsRow>

                {accounts.length === 0 ? (
                    <p className="text-destructive text-sm">
                        No courier account can book for this business yet. An Owner links one with a pickup store in Settings &gt; Couriers.
                    </p>
                ) : (
                    <div className="flex flex-wrap items-end gap-4">
                        <div className="grid gap-1">
                            <Label htmlFor="account">Courier</Label>
                            <select
                                id="account"
                                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                value={form.data.courier_account_id}
                                onChange={(e) => form.setData('courier_account_id', e.target.value)}
                            >
                                <option value="">Default for each order's brand</option>
                                {accounts.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.label} ({a.provider_name}
                                        {a.store_name ? `, ${a.store_name}` : ''}){a.is_default ? ', default' : ''}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.courier_account_id} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="warehouse">Leaves from</Label>
                            <select
                                id="warehouse"
                                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                value={form.data.warehouse_id}
                                onChange={(e) => form.setData('warehouse_id', e.target.value)}
                            >
                                <option value="">Choose a warehouse</option>
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name}
                                    </option>
                                ))}
                            </select>
                            <span className="text-muted-foreground text-xs">An order with its own warehouse leaves from that one.</span>
                            <InputError message={errors.warehouse_id} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="search">Find</Label>
                            <Input
                                id="search"
                                className="w-64"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Order, shop number, name, phone"
                            />
                        </div>
                        <Button onClick={submit} disabled={form.processing || form.data.order_ids.length === 0}>
                            {form.processing ? 'Starting' : `Create challans and book (${form.data.order_ids.length})`}
                        </Button>
                    </div>
                )}
                <InputError message={errors.order_ids} />

                {fix_addresses.length > 0 && (
                    <div className="rounded-lg border border-amber-500/40 p-3 text-sm">
                        <p className="mb-1 font-medium text-amber-700 dark:text-amber-400">Fix addresses first ({fix_addresses.length})</p>
                        <p className="text-muted-foreground mb-2 text-xs">
                            The default courier has no zone for these places yet.{' '}
                            {can_manage_locations ? (
                                <Link href={route('couriers.settings.locations.index')} className="underline underline-offset-2">
                                    Map them in Couriers &gt; Locations
                                </Link>
                            ) : (
                                'An Owner maps them in Settings > Couriers > Locations.'
                            )}
                        </p>
                        <ul className="list-disc pl-5">
                            {fix_addresses.map((f) => (
                                <li key={f.id}>
                                    <Link href={route('sales.orders.show', f.id)} className="underline underline-offset-2">
                                        {f.order_number}
                                    </Link>{' '}
                                    {f.place}: {f.message}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {visible.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Nothing is waiting for a courier.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-2">
                                        <input type="checkbox" aria-label="Select all" checked={allSelected} onChange={toggleAll} />
                                    </th>
                                    <th className="py-2 pr-3">Order</th>
                                    <th className="py-2 pr-3">Brand</th>
                                    <th className="py-2 pr-3">Recipient</th>
                                    <th className="py-2 pr-3">Place</th>
                                    <th className="py-2 pr-3">Courier</th>
                                    <th className="py-2 pr-3 text-right">Weight</th>
                                    <th className="py-2 pr-3 text-right">To collect</th>
                                    <th className="py-2">Will</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((o) => {
                                    const problem = problemFor(o);
                                    const blocked = o.in_flight || o.open_booking || problem !== null;

                                    return (
                                        <tr key={o.id} className="border-sidebar-border/40 border-b align-top">
                                            <td className="py-2 pr-2">
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Select ${o.order_number}`}
                                                    disabled={blocked}
                                                    checked={selected.has(o.id)}
                                                    onChange={() => toggle(o.id)}
                                                />
                                            </td>
                                            <td className="py-2 pr-3">
                                                <Link href={route('sales.orders.show', o.id)} className="underline underline-offset-2">
                                                    {o.order_number}
                                                </Link>
                                                {o.shop_reference && (
                                                    <div className="text-muted-foreground font-mono text-xs">{o.shop_reference}</div>
                                                )}
                                            </td>
                                            <td className="py-2 pr-3">{o.brand ?? '-'}</td>
                                            <td className="py-2 pr-3">
                                                {o.recipient}
                                                <div className="text-muted-foreground text-xs">{o.phone}</div>
                                            </td>
                                            <td className="py-2 pr-3">
                                                {o.place}
                                                {problem && <div className="text-xs text-amber-700 dark:text-amber-400">{problem}</div>}
                                            </td>
                                            <td className="py-2 pr-3">{accountName(accountFor(o))}</td>
                                            <td className="py-2 pr-3 text-right tabular-nums">
                                                {o.weight_grams !== null ? `${o.weight_grams} g` : 'not known'}
                                            </td>
                                            <td className="py-2 pr-3 text-right tabular-nums">{formatMoney(o.cod)}</td>
                                            <td className="py-2">
                                                {o.in_flight ? (
                                                    <Badge variant="secondary">being booked</Badge>
                                                ) : o.open_booking ? (
                                                    <Badge variant="destructive">settle the open booking</Badge>
                                                ) : o.kind === 'challan' ? (
                                                    'make challan, book'
                                                ) : (
                                                    `book ${o.delivery_number}`
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {runs.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h2 className="text-sm font-semibold">Recent runs</h2>
                        <ul className="text-sm">
                            {runs.map((r) => (
                                <li key={r.id}>
                                    <Link href={route('sales.parcels-to-book.runs.show', r.id)} className="underline underline-offset-2">
                                        Run #{r.id}
                                    </Link>{' '}
                                    <span className="text-muted-foreground">
                                        {r.at}, {r.by ?? 'someone'}: {r.booked} booked of {r.total}
                                        {r.refused > 0 ? `, ${r.refused} refused` : ''}
                                        {r.unknown > 0 ? `, ${r.unknown} unknown` : ''}
                                        {r.fix_address > 0 ? `, ${r.fix_address} to fix` : ''}
                                        {r.finished ? '' : ` (${r.status})`}
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
