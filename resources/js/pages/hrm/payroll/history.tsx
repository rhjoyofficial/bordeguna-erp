import { ReportHeading, ReportPage, ReportTable, StatRow, StatTile } from '@/components/report/report-shell';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface MonthRow {
    period_id: number;
    year: number | null;
    month: number | null;
    label: string | null;
    cycle: string | null;
    version: number;
    status: string;
    status_label: string;
    is_paid: boolean;
    employee_count: number | null;
    gross: number | null;
    earnings: number;
    deductions: number;
    net: number;
    paid_on: string | null;
    url: string;
}

interface YearGroup {
    year: number;
    months: MonthRow[];
    net_paid: number;
    net_unpaid: number;
    months_paid: number;
}

interface Props {
    filters: { employee_id: number | null };
    employee: { id: number; name: string; employee_code: string } | null;
    years: YearGroup[];
    grand_total_paid: number;
    months_paid: number;
    employees: { id: number; name: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Payroll', href: '/hrm/payroll' },
    { title: 'Salary history', href: '/hrm/payroll/history' },
];

const money = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * The salary register.
 *
 * ⚠ Every row says whether that month was actually PAID, and the totals count
 * only the paid ones. A month sitting at Calculated is a plan; adding it to a
 * ten-year wage total would overstate what left the business.
 */
export default function PayrollHistory({ filters, employee, years, grand_total_paid, months_paid, employees }: Props) {
    const perPerson = employee !== null;

    const choose = (employeeId: string) =>
        router.get(route('hrm.payroll.history'), employeeId === '' ? {} : { employee_id: Number(employeeId) }, {
            preserveState: true,
            replace: true,
        });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Salary history" />
            <ReportPage>
                <ReportHeading
                    title={perPerson ? `Salary history: ${employee?.name}` : 'Salary history'}
                    actions={
                        <div className="grid gap-1">
                            <Label htmlFor="employee" className="text-muted-foreground text-xs">
                                Whose history
                            </Label>
                            <SearchableSelect
                                id="employee"
                                value={filters.employee_id === null ? '' : String(filters.employee_id)}
                                onChange={choose}
                                clearable
                                placeholder="The whole business"
                                className="w-64"
                                options={[
                                    { value: '', label: 'The whole business' },
                                    ...employees.map((e) => ({ value: String(e.id), label: e.name })),
                                ]}
                            />
                        </div>
                    }
                />

                <StatRow columns={2}>
                    <StatTile
                        label={perPerson ? 'Total paid to this person' : 'Total wages paid'}
                        value={money(grand_total_paid)}
                        hint="Disbursed and locked months only"
                    />
                    <StatTile
                        label="Months paid"
                        value={months_paid}
                        hint={years.length === 0 ? 'No payroll yet' : `Across ${years.length} year(s)`}
                    />
                </StatRow>

                {years.length === 0 && (
                    <p className="text-muted-foreground py-6 text-center text-sm">
                        No payroll has been run yet. Once a month is calculated it stays here permanently.
                    </p>
                )}

                {years.map((group) => (
                    <div key={group.year} className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="text-base font-medium">{group.year}</h2>
                            <div className="text-muted-foreground flex flex-wrap items-baseline gap-4 text-xs">
                                <span>
                                    Paid this year: <span className="text-foreground font-medium">{money(group.net_paid)}</span> over{' '}
                                    {group.months_paid} month{group.months_paid === 1 ? '' : 's'}
                                </span>
                                {group.net_unpaid > 0 && <span>Not yet paid: {money(group.net_unpaid)}</span>}
                            </div>
                        </div>

                        <div className="mt-3">
                            <ReportTable
                                minWidth="58rem"
                                head={
                                    <>
                                        <th className="py-2 font-medium">Month</th>
                                        <th className="py-2 font-medium">Cycle</th>
                                        <th className="py-2 font-medium">Status</th>
                                        <th className="py-2 text-right font-medium">{perPerson ? 'Gross' : 'People'}</th>
                                        <th className="py-2 text-right font-medium">Earnings</th>
                                        <th className="py-2 text-right font-medium">Deductions</th>
                                        <th className="py-2 text-right font-medium">Net</th>
                                        <th className="py-2 font-medium">Paid on</th>
                                    </>
                                }
                            >
                                {group.months.map((row) => (
                                    <tr key={row.period_id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">
                                            <Link href={row.url} className="underline-offset-4 hover:underline">
                                                {row.label}
                                            </Link>
                                            {row.version > 1 && <span className="text-muted-foreground ml-2 text-xs">v{row.version}</span>}
                                        </td>
                                        <td className="text-muted-foreground py-2 text-xs">{row.cycle}</td>
                                        <td className="py-2">
                                            <Badge variant={row.is_paid ? 'default' : 'secondary'}>{row.status_label}</Badge>
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            {perPerson ? (row.gross === null ? '' : money(row.gross)) : row.employee_count}
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">{money(row.earnings)}</td>
                                        <td className="py-2 text-right whitespace-nowrap">{money(row.deductions)}</td>
                                        <td className="py-2 text-right font-medium whitespace-nowrap">{money(row.net)}</td>
                                        <td className="text-muted-foreground py-2 text-xs whitespace-nowrap">{row.paid_on ?? 'not yet'}</td>
                                    </tr>
                                ))}
                            </ReportTable>
                        </div>
                    </div>
                ))}
            </ReportPage>
        </AppLayout>
    );
}
