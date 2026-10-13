import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { SupplierDialog, type DialogSupplier } from '@/components/purchasing/supplier-dialog';
import { SearchSelect } from '@/components/search-select';
import { SupplierImportPanel } from '@/components/supplier-import-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Supplier = DialogSupplier;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Suppliers', href: '/purchasing/suppliers' },
];

export default function SuppliersIndex({
    suppliers,
    filters: initialFilters,
    mayManage,
    supplierTypes,
}: {
    suppliers: Paginated<Supplier>;
    filters: { search: string; status: string };
    mayManage: boolean;
    supplierTypes: Record<string, string>;
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/purchasing/suppliers', initialFilters);
    // ⭐ One popup for a supplier (13 Oct 2026); the form that used to sit open
    // above the list is gone. Same fields, same routes, same server rules.
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Supplier | null>(null);

    const openNew = () => {
        setEditing(null);
        setDialogOpen(true);
    };

    const startEdit = (supplier: Supplier) => {
        setEditing(supplier);
        setDialogOpen(true);
    };

    const destroy = (supplier: Supplier) => {
        router.delete(route('purchasing.suppliers.destroy', supplier.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Suppliers" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-lg font-semibold">Suppliers</h1>
                </div>

                {/* ⚠ Behind the same permission as the popup form: an export
                    hands over bank account numbers, BIN and TIN in one file. */}
                {mayManage && <SupplierImportPanel actions={<Button onClick={openNew}>Add supplier</Button>} />}

                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Search" htmlFor="filter-search" className="w-80">
                        <Input
                            id="filter-search"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder="Search code, name, contact or email…"
                        />
                    </FilterField>
                    <FilterField label="Status" htmlFor="filter-status">
                        <SearchSelect id="filter-status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Code</th>
                            <th className="py-2">Name</th>
                            <th className="py-2">Contact</th>
                            <th className="py-2">Phone</th>
                            <th className="py-2">Email</th>
                            <th className="py-2">Terms</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {suppliers.data.map((supplier) => (
                            <tr key={supplier.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2 font-medium">{supplier.code ?? '-'}</td>
                                <td className="py-2">
                                    {/* C-360: the name opens the supplier's own page -
                                        payable, open bills, history in one place. */}
                                    <Link href={`/purchasing/suppliers/${supplier.id}`} className="font-medium underline-offset-4 hover:underline">
                                        {supplier.name}
                                    </Link>
                                    {!supplier.is_active && (
                                        <Badge variant="secondary" className="ml-2">
                                            Inactive
                                        </Badge>
                                    )}
                                </td>
                                <td className="text-muted-foreground py-2">{supplier.contact_name ?? '-'}</td>
                                <td className="text-muted-foreground py-2">{supplier.phone ?? '-'}</td>
                                <td className="text-muted-foreground py-2">{supplier.email ?? '-'}</td>
                                <td className="text-muted-foreground py-2">{supplier.payment_terms ?? '-'}</td>
                                <td className="py-2 text-right">
                                    {mayManage && (
                                        <Button variant="ghost" size="sm" onClick={() => startEdit(supplier)}>
                                            Edit
                                        </Button>
                                    )}
                                    {mayManage && supplier.is_active && (
                                        <Button variant="ghost" size="sm" onClick={() => destroy(supplier)}>
                                            Deactivate
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {suppliers.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="text-muted-foreground py-4 text-center">
                                    {hasActiveFilters ? 'No suppliers match these filters.' : 'No suppliers yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                <Pagination meta={suppliers} />

                {mayManage && <SupplierDialog open={dialogOpen} onOpenChange={setDialogOpen} supplier={editing} supplierTypes={supplierTypes} />}
            </div>
        </AppLayout>
    );
}
