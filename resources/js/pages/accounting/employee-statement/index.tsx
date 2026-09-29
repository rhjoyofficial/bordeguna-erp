import { EmptyRow, FilterBar, ReportHeading, ReportPage, ReportTable, StatRow, StatTile } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface EmployeeOption {
    id: number;
    label: string;
    is_active: boolean;
}

interface Line {
    date: string;
    journal_number: string;
    journal_id: number;
    memo: string | null;
    source: string | null;
    debit: string | null;
    credit: string | null;
    balance: string;
    balance_side: 'debit' | 'credit';
}

interface Head {
    account: string;
    code: string;
    slug: string;
    normal_balance: 'debit' | 'credit';
    opening: string;
    opening_side: 'debit' | 'credit';
    debits: string;
    credits: string;
    closing: string;
    closing_side: 'debit' | 'credit';
    lines: Line[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Employee statement', href: '/accounting/employee-statement' },
];

const side = (amount: string, sideOf: 'debit' | 'credit') => (amount === '0.00' ? amount : sideOf === 'credit' ? `${amount} Cr` : `${amount} Dr`);

/* What each head's closing balance MEANS for this person, in plain words. */
const MEANING: Record<string, { tile: string; debit: string; credit: string }> = {
    employee_advances: { tile: 'Advances outstanding', debit: 'owes the business', credit: 'has repaid more than advanced' },
    salaries_payable: { tile: 'Salary owed', debit: 'was paid ahead of the accrual', credit: 'is owed by the business' },
    employee_deductions_payable: { tile: 'Deductions held', debit: 'was paid out beyond what was held', credit: 'held by the business' },
    employee_reimbursements_payable: { tile: 'Claims owed', debit: 'was reimbursed beyond what was approved', credit: 'owed by the business' },
};

/**
 * One employee's ledgers (26 Sep 2026): the accountant's "ledger head per
 * employee", read from the party-tagged journal lines. Four heads, each its
 * own running ledger, because an asset and three liabilities never add into
 * one honest number.
 */
export default function EmployeeStatementIndex({
    from,
    to,
    employees,
    employee,
    heads,
}: {
    from: string;
    to: string;
    employees: EmployeeOption[];
    employee: { id: number; name: string; code: string } | null;
    heads: Head[];
}) {
    const [filters, setFilters] = useState({ employee: employee ? String(employee.id) : '', from, to });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.employee-statement'), filters, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employee statement" />
            <ReportPage>
                <ReportHeading title="Employee statement" />

                <FilterBar onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="employee">Employee</Label>
                        <SearchSelect
                            fit
                            id="employee"
                            value={filters.employee}
                            onChange={(e) => setFilters({ ...filters, employee: e.target.value })}
                        >
                            <option value="">Choose an employee</option>
                            {employees.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                    {option.is_active ? '' : ' (left)'}
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

                {employee === null && <p className="text-muted-foreground py-6 text-center text-sm">Choose an employee to see their ledgers.</p>}

                {employee !== null && (
                    <>
                        <StatRow>
                            {heads.map((head) => (
                                <StatTile
                                    key={head.slug}
                                    label={MEANING[head.slug]?.tile ?? head.account}
                                    value={side(head.closing, head.closing_side)}
                                    hint={`As at ${to}`}
                                />
                            ))}
                        </StatRow>

                        {heads.map((head) => {
                            const meaning = MEANING[head.slug];
                            const isDebit = head.closing_side === 'debit';

                            return (
                                <section key={head.slug} className="grid gap-2">
                                    <h2 className="text-base font-semibold">
                                        {head.account}
                                        <span className="text-muted-foreground ml-2 text-sm font-normal">
                                            {employee.name}
                                            {head.closing !== '0.00' && meaning
                                                ? ` ${isDebit ? meaning.debit : meaning.credit} ${head.closing}`
                                                : ' has nothing outstanding'}
                                        </span>
                                    </h2>
                                    <ReportTable
                                        minWidth="56rem"
                                        head={
                                            <>
                                                <th className="py-2">Date</th>
                                                <th className="py-2">Voucher</th>
                                                <th className="py-2">Particulars</th>
                                                <th className="py-2">Source</th>
                                                <th className="py-2 text-right">Debit</th>
                                                <th className="py-2 text-right">Credit</th>
                                                <th className="py-2 text-right">Balance</th>
                                            </>
                                        }
                                        foot={
                                            <tr className="font-medium">
                                                <td className="py-2" colSpan={4}>
                                                    Closing balance
                                                </td>
                                                <td className="py-2 text-right tabular-nums">{head.debits}</td>
                                                <td className="py-2 text-right tabular-nums">{head.credits}</td>
                                                <td className="py-2 text-right tabular-nums">{side(head.closing, head.closing_side)}</td>
                                            </tr>
                                        }
                                    >
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td className="text-muted-foreground py-2">{from}</td>
                                            <td className="py-2 font-medium" colSpan={5}>
                                                Opening balance
                                            </td>
                                            <td className="py-2 text-right font-medium tabular-nums">{side(head.opening, head.opening_side)}</td>
                                        </tr>
                                        {head.lines.map((line, index) => (
                                            <tr key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                <td className="text-muted-foreground py-2">{line.date}</td>
                                                <td className="py-2 font-mono text-xs">
                                                    <Link
                                                        href={`/accounting/journals/${line.journal_id}`}
                                                        className="underline-offset-2 hover:underline"
                                                    >
                                                        {line.journal_number}
                                                    </Link>
                                                </td>
                                                <td className="py-2">{line.memo}</td>
                                                <td className="text-muted-foreground py-2">{line.source}</td>
                                                <td className="py-2 text-right tabular-nums">{line.debit}</td>
                                                <td className="py-2 text-right tabular-nums">{line.credit}</td>
                                                <td className="py-2 text-right tabular-nums">{side(line.balance, line.balance_side)}</td>
                                            </tr>
                                        ))}
                                        {head.lines.length === 0 && <EmptyRow colSpan={7}>No entries in this period.</EmptyRow>}
                                    </ReportTable>
                                </section>
                            );
                        })}
                    </>
                )}
            </ReportPage>
        </AppLayout>
    );
}
