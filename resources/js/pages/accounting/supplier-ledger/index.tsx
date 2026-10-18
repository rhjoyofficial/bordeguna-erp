import { type SupplierLedgerData, SupplierLedgerView } from '@/components/accounting/supplier-ledger-view';
import { FilterBar, ReportHeading, ReportPage } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface SupplierOption {
    id: number;
    label: string;
    is_active: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Supplier ledger', href: '/accounting/supplier-ledger' },
];

/**
 * ⭐ One supplier across Accounts Payable, GRNI and Advances (A1, 18 Oct 2026):
 * the view the General Ledger opens for a supplier's head, on its own page,
 * reachable from the supplier's profile and the supplier statement.
 */
export default function SupplierLedgerIndex({
    from,
    to,
    suppliers,
    ledger,
}: {
    from: string;
    to: string;
    suppliers: SupplierOption[];
    ledger: SupplierLedgerData | null;
}) {
    const [filters, setFilters] = useState({ supplier: ledger ? String(ledger.supplier.id) : '', from, to });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.supplier-ledger'), filters, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={ledger ? `Supplier ledger: ${ledger.supplier.name}` : 'Supplier ledger'} />
            <ReportPage>
                <ReportHeading
                    title={ledger ? `Supplier ledger: ${ledger.supplier.name}` : 'Supplier ledger'}
                    description={ledger ? `${showDate(from)} to ${showDate(to)}` : undefined}
                    actions={
                        ledger ? (
                            <>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={route('accounting.supplier-statement', { supplier: ledger.supplier.id, from, to })}>Statement</Link>
                                </Button>
                                <Button size="sm" onClick={() => window.print()}>
                                    Print
                                </Button>
                            </>
                        ) : undefined
                    }
                />

                <FilterBar onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="supplier">Supplier</Label>
                        <SearchSelect
                            fit
                            id="supplier"
                            value={filters.supplier}
                            onChange={(e) => setFilters({ ...filters, supplier: e.target.value })}
                        >
                            <option value="">Choose a supplier</option>
                            {suppliers.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                    {option.is_active ? '' : ' (inactive)'}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
                    </div>
                </FilterBar>

                {ledger === null ? (
                    <p className="text-muted-foreground py-6 text-center text-sm">Choose a supplier to see their ledger.</p>
                ) : (
                    <SupplierLedgerView ledger={ledger} />
                )}
            </ReportPage>
        </AppLayout>
    );
}
