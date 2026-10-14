import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface CreditNote {
    id: number;
    credit_note_number: string;
    credit_date_only: string | null;
    total_taka: string;
    note: string | null;
    supplier: { id: number; name: string } | null;
    /** PUR-003 (14 Oct 2026): set once the credit note is reversed. */
    reversed_at: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Credit notes', href: '/purchasing/credit-notes' },
];

export default function CreditNotesIndex({
    creditNotes,
    filters: initialFilters,
    suppliers,
}: {
    creditNotes: Paginated<CreditNote>;
    filters: { search: string; supplier_id: string };
    suppliers: { id: number; name: string }[];
}) {
    const { t } = useTranslation();
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/purchasing/credit-notes', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Supplier Credit Notes" />
            <div className="flex flex-col gap-6 p-4">
                {/* A credit note settles the claim a late return creates:
                    goods went back AFTER the bill arrived, so the supplier
                    owes us and this is their document saying so. */}
                <ReportHeading
                    title="Supplier credit notes"
                    actions={
                        <Button asChild size="sm">
                            <Link href="/purchasing/credit-notes/new">Record credit note</Link>
                        </Button>
                    }
                />

                <FilterBar showClear={hasActiveFilters} onClear={reset}>
                    <Input
                        placeholder="Search number or supplier"
                        value={filters.search}
                        onChange={(e) => setFilter('search', e.target.value)}
                        className="max-w-xs"
                    />
                    <SearchSelect value={filters.supplier_id} onChange={(e) => setFilter('supplier_id', e.target.value)}>
                        <option value="">All suppliers</option>
                        {suppliers.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </SearchSelect>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Credit note</th>
                                <th className="p-3">Supplier</th>
                                <th className="p-3">Date</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {creditNotes.data.map((creditNote) => (
                                <tr key={creditNote.id} className="border-t">
                                    <td className="p-3 font-medium">
                                        {creditNote.credit_note_number}
                                        {creditNote.reversed_at && (
                                            <Badge variant="destructive" className="ml-2">
                                                {t('reverse.badge')}
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3">{creditNote.supplier?.name ?? '-'}</td>
                                    <td className="text-muted-foreground p-3">{creditNote.credit_date_only ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{creditNote.total_taka}</td>
                                    <td className="p-3 text-right">
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/purchasing/credit-notes/${creditNote.id}`}>View</Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {creditNotes.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                        No credit notes yet. One belongs here when goods go back after the bill arrived.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={creditNotes} />
            </div>
        </AppLayout>
    );
}
