import { Chip, Pill } from '@/components/assets/assets-ui';
import { Pagination } from '@/components/data-table/pagination';
import { ListFooter, MoneyStrip, type MoneyStripData, SearchField } from '@/components/purchasing/purchases-ui';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
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
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Bills & payments', href: '/purchasing/bills' },
    { title: 'Credit notes', href: '/purchasing/credit-notes' },
];

function shortDate(iso: string | null): string {
    if (!iso) return '-';

    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Supplier credit notes (Purchases redesign, 15 Oct 2026). A credit note
 * settles the claim a late return creates: goods went back AFTER the bill
 * arrived, so the supplier owes us and this is their document saying so.
 */
export default function CreditNotesIndex({
    creditNotes,
    filters: initialFilters,
    counts,
    strip,
    can_record,
    suppliers,
}: {
    creditNotes: Paginated<CreditNote>;
    filters: { search: string; supplier_id: string; state: string };
    counts: { all: number; reversed: number };
    strip: MoneyStripData;
    can_record: boolean;
    suppliers: { id: number; name: string }[];
}) {
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/purchasing/credit-notes', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Supplier credit notes" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <p className="text-muted-foreground text-sm">What a supplier owes us back, after goods went back once the bill had arrived.</p>
                    {can_record && (
                        <Button size="sm" asChild>
                            <Link href="/purchasing/credit-notes/new">Record credit note</Link>
                        </Button>
                    )}
                </div>

                <MoneyStrip strip={strip} />

                <section className="bg-card overflow-hidden rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div role="group" aria-label="Show credit notes" className="flex flex-wrap gap-1.5">
                            <Chip active={!hasActiveFilters} count={counts.all} onClick={reset}>
                                All
                            </Chip>
                            <Chip
                                active={filters.state === 'posted'}
                                count={counts.all - counts.reversed}
                                onClick={() => setFilter('state', 'posted')}
                            >
                                Posted
                            </Chip>
                            <Chip active={filters.state === 'reversed'} count={counts.reversed} onClick={() => setFilter('state', 'reversed')}>
                                Reversed
                            </Chip>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <SearchSelect
                                value={filters.supplier_id}
                                onChange={(e) => setFilter('supplier_id', e.target.value)}
                                aria-label="Supplier"
                            >
                                <option value="">All suppliers</option>
                                {suppliers.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <SearchField
                                value={filters.search}
                                onChange={(v) => setFilter('search', v)}
                                placeholder="Number or supplier"
                                label="Search credit notes"
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[36rem] text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3.5 [&_tbody_td]:py-2.5 [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th>Credit note</th>
                                    <th>Supplier</th>
                                    <th>Date</th>
                                    <th className="text-right!">Amount</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {creditNotes.data.map((creditNote) => (
                                    <tr key={creditNote.id} className="hover:bg-muted/40">
                                        <td>
                                            <Link href={`/purchasing/credit-notes/${creditNote.id}`} className="font-medium hover:underline">
                                                {creditNote.credit_note_number}
                                            </Link>
                                            {creditNote.note && (
                                                <div className="text-muted-foreground max-w-xs truncate text-xs">{creditNote.note}</div>
                                            )}
                                        </td>
                                        <td>
                                            {creditNote.supplier ? (
                                                <Link href={`/purchasing/suppliers/${creditNote.supplier.id}`} className="hover:underline">
                                                    {creditNote.supplier.name}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap">{shortDate(creditNote.credit_date_only)}</td>
                                        <td className="text-right font-medium tabular-nums">{creditNote.total_taka}</td>
                                        <td>{creditNote.reversed_at ? <Pill tone="info">Reversed</Pill> : <Pill tone="ok">Posted</Pill>}</td>
                                    </tr>
                                ))}
                                {creditNotes.data.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                            {hasActiveFilters
                                                ? 'No credit note matches this.'
                                                : 'No credit notes yet. One belongs here when goods go back after the bill arrived.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <ListFooter count={creditNotes.data.length} word="credit note">
                        A credit note is corrected by reversing it with a reason, never edited.
                    </ListFooter>
                </section>

                <Pagination meta={creditNotes} />
            </div>
        </AppLayout>
    );
}
