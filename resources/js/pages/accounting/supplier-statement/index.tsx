import { Amount, DateCell, DocNo, LedgerHead } from '@/components/ledger-text';
import { EmptyRow, FilterBar, ReportHeading, ReportPage, ReportTable, StatRow, StatTile, Verdict } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface SupplierOption {
    id: number;
    label: string;
    is_active: boolean;
}

interface Line {
    date: string;
    kind: 'bill' | 'credit_note' | 'payment' | 'adjustment';
    label: string;
    reference: string | null;
    charge: string | null;
    settlement: string | null;
    balance: string;
    balance_side: 'debit' | 'credit';
}

interface Summary {
    opening: string;
    opening_side: 'debit' | 'credit';
    charges: string;
    settlements: string;
    closing: string;
    closing_side: 'debit' | 'credit';
    checked: boolean;
    consistent: boolean;
    ledger_closing: string | null;
}

// A Manager may reach this screen from Purchases and cannot open the ledger (Q19).
const breadcrumbsFor = (canViewAccounting: boolean): BreadcrumbItem[] => [
    canViewAccounting ? { title: 'Accounting', href: '/accounting/journals' } : { title: 'Purchases', href: '/purchasing/suppliers' },
    { title: 'Supplier statement', href: '/accounting/supplier-statement' },
];

/* A credit balance means the business owes the supplier (advance or
 * over-payment), and it is the one state that must not read as a debt. */
const side = (amount: string, sideOf: 'debit' | 'credit') => (sideOf === 'credit' ? `${amount} Cr` : amount);

export default function SupplierStatementIndex({
    from,
    to,
    suppliers,
    supplier,
    lines,
    summary,
}: {
    from: string;
    to: string;
    suppliers: SupplierOption[];
    supplier: { id: number; name: string; code: string | null } | null;
    lines: Line[];
    summary: Summary | null;
}) {
    const [filters, setFilters] = useState({ supplier: supplier ? String(supplier.id) : '', from, to });
    const { canViewAccounting } = usePage<SharedData>().props;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.supplier-statement'), filters, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbsFor(canViewAccounting)}>
            <Head title="Supplier statement" />
            <ReportPage>
                <ReportHeading title="Supplier statement" />

                <FilterBar onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="supplier">Supplier</Label>
                        {/* No hand-written width. The picker sizes itself now; the
                            w-80 that used to live here was left over from the native
                            select this replaced. */}
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

                {supplier === null && <p className="text-muted-foreground py-6 text-center text-sm">Choose a supplier to see their statement.</p>}

                {supplier !== null && summary !== null && (
                    <>
                        {/* C-CST-1: the tie-out, shown only when there was something to check against. */}
                        {summary.checked && !summary.consistent && (
                            <Verdict tone="bad">
                                This statement does not agree with the ledger: the documents close at {side(summary.closing, summary.closing_side)}{' '}
                                but the supplier&apos;s share of Accounts Payable is {summary.ledger_closing}. A posting is missing a document or a
                                document is missing a posting. Run accounting:reconcile to find which.
                            </Verdict>
                        )}

                        <StatRow>
                            <StatTile label="Opening balance" value={side(summary.opening, summary.opening_side)} hint={`As at ${from}`} />
                            <StatTile label="Bills" value={summary.charges} />
                            <StatTile label="Payments and credits" value={summary.settlements} />
                            <StatTile
                                label="Closing balance"
                                value={side(summary.closing, summary.closing_side)}
                                tone={summary.closing_side === 'credit' ? 'good' : undefined}
                            />
                        </StatRow>

                        <ReportTable
                            minWidth="56rem"
                            head={
                                <>
                                    <th className="py-2">Date</th>
                                    <th className="py-2">Detail</th>
                                    <th className="py-2">Reference</th>
                                    <th className="py-2 text-right">Bills</th>
                                    <th className="py-2 text-right">Payments &amp; credits</th>
                                    <th className="py-2 text-right">Balance</th>
                                </>
                            }
                            foot={
                                <tr className="font-bold">
                                    <td className="py-2" colSpan={3}>
                                        Closing balance
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={summary.charges} tone="debit" className="font-bold" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={summary.settlements} tone="credit" className="font-bold" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={side(summary.closing, summary.closing_side)} tone="total" />
                                    </td>
                                </tr>
                            }
                        >
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    <DateCell>{from}</DateCell>
                                </td>
                                <td className="py-2 font-semibold" colSpan={4}>
                                    Opening balance
                                </td>
                                <td className="py-2 text-right">
                                    <Amount value={side(summary.opening, summary.opening_side)} tone="balance" />
                                </td>
                            </tr>
                            {lines.map((line, index) => (
                                <tr key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">
                                        <DateCell>{line.date}</DateCell>
                                    </td>
                                    <td className="py-2">
                                        <LedgerHead name={line.label} />
                                    </td>
                                    <td className="py-2">{line.reference ? <DocNo>{line.reference}</DocNo> : null}</td>
                                    <td className="py-2 text-right">
                                        <Amount value={line.charge} tone="debit" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={line.settlement} tone="credit" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={side(line.balance, line.balance_side)} tone="balance" />
                                    </td>
                                </tr>
                            ))}
                            {lines.length === 0 && <EmptyRow colSpan={6}>No transactions in this period.</EmptyRow>}
                        </ReportTable>
                    </>
                )}
            </ReportPage>
        </AppLayout>
    );
}
