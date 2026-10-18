import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface Row {
    id: number;
    number: string;
    kind: string;
    kind_label: string;
    supplier: { id: number; name: string } | null;
    purchase_order: { id: number; po_number: string } | null;
    money_account: string | null;
    paid_on: string | null;
    amount: string;
    open: string;
    is_reversed: boolean;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Advances', href: '/purchasing/advances' },
];

/**
 * ⭐ Supplier advances (ruling P2, 18 Oct 2026): every advance paid, newest
 * first, with what is still open on each. Payment vouchers that sent money to
 * 1160 before advances had documents are on each supplier's advances page,
 * as the supplier's on-account balance.
 */
export default function AdvanceIndex({
    advances,
    filters,
    suppliers,
    can_record,
}: {
    advances: Paginated<Row>;
    filters: { supplier_id: string; state: string };
    suppliers: { id: number; name: string }[];
    can_record: boolean;
}) {
    const filter = (patch: Partial<typeof filters>) =>
        router.get('/purchasing/advances', { ...filters, ...patch }, { preserveState: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Supplier advances" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex flex-wrap items-end gap-3">
                        <SearchSelect fit id="supplier_filter" value={filters.supplier_id} onChange={(e) => filter({ supplier_id: e.target.value })}>
                            <option value="">All suppliers</option>
                            {suppliers.map((supplier) => (
                                <option key={supplier.id} value={supplier.id}>
                                    {supplier.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {(['', 'open', 'reversed'] as const).map((state) => (
                            <button
                                key={state || 'all'}
                                type="button"
                                onClick={() => filter({ state })}
                                className={`rounded-full border px-3 py-1 text-sm ${filters.state === state ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                            >
                                {state === '' ? 'All' : state === 'open' ? 'Standing' : 'Reversed'}
                            </button>
                        ))}
                        {filters.supplier_id && (
                            <Link href={`/purchasing/suppliers/${filters.supplier_id}/advances`} className="text-sm hover:underline">
                                This supplier&apos;s advances and bills
                            </Link>
                        )}
                    </div>
                    {can_record && (
                        <Button asChild>
                            <Link href="/purchasing/advances/new">Pay an advance</Link>
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Advance</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Supplier</th>
                                <th className="p-3">Purchase order</th>
                                <th className="p-3">Kind</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3 text-right">Open</th>
                            </tr>
                        </thead>
                        <tbody>
                            {advances.data.map((row) => (
                                <tr key={row.id} className={`border-t ${row.is_reversed ? 'text-muted-foreground' : ''}`}>
                                    <td className="p-3 font-medium">
                                        <Link href={`/purchasing/advances/${row.id}`} className="hover:underline">
                                            {row.number}
                                        </Link>
                                        {row.is_reversed && <span className="text-destructive ml-2 text-xs">reversed</span>}
                                    </td>
                                    <td className="p-3">{showDate(row.paid_on)}</td>
                                    <td className="p-3">{row.supplier?.name ?? '-'}</td>
                                    <td className="p-3">{row.purchase_order?.po_number ?? '-'}</td>
                                    <td className="p-3">{row.kind_label}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(row.amount)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(row.open)}</td>
                                </tr>
                            ))}
                            {advances.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                        No advances.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {advances.links.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {advances.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    preserveState
                                    className={`rounded border px-2 py-1 text-sm ${link.active ? 'bg-primary text-primary-foreground' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : null,
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
