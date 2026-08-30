import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Employee {
    id: number;
    code: string;
    name: string;
    status: string;
    has_left: boolean;
    separation_type: string | null;
    separation_date: string | null;
    joining_date: string | null;
}

interface Figures {
    unpaid_salary: number;
    loan_outstanding: number;
    encashable_leave_days: number;
}

const money = (value: number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * The final settlement statement.
 *
 * ⚠⚠ Every figure on this page is a ledger fact: salary already earned and
 * unpaid, a loan already lent and unrecovered. Nothing here is priced by a rule
 * that does not exist, which is precisely why it may be totalled at all.
 */
export default function SettlementShow({
    employee,
    figures,
    net,
    can_total,
    encashment_is_live,
    issuer,
}: {
    employee: Employee;
    figures: Figures;
    net: number;
    can_total: boolean;
    encashment_is_live: boolean;
    issuer: Issuer;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Employees', href: '/hrm/employees' },
        { title: employee.code, href: `/hrm/employees/${employee.id}` },
        { title: 'Final settlement', href: `/hrm/employees/${employee.id}/settlement` },
    ];

    const owedToEmployee = net >= 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Final settlement ${employee.code}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/hrm/employees/${employee.id}`}>Back to the profile</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        Print / save as PDF
                    </Button>
                </div>

                {!employee.has_left && (
                    <div className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm print:hidden">
                        {employee.name} has not left. This is what a settlement would look like today, not a settlement.
                    </div>
                )}

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-2xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title="Final settlement" subtitle={employee.code} />

                    <div className="mt-6 flex flex-wrap justify-between gap-6 text-sm">
                        <div>
                            <p className="text-black/60">Employee</p>
                            <p className="font-medium">{employee.name}</p>
                            <p className="text-black/60">{employee.code}</p>
                        </div>
                        <div className="text-right">
                            {employee.joining_date && (
                                <p>
                                    <span className="text-black/60">Joined: </span>
                                    {employee.joining_date}
                                </p>
                            )}
                            {employee.separation_date && (
                                <p>
                                    <span className="text-black/60">Last working day: </span>
                                    {employee.separation_date}
                                </p>
                            )}
                            {employee.separation_type && (
                                <p>
                                    <span className="text-black/60">Reason: </span>
                                    {employee.separation_type}
                                </p>
                            )}
                        </div>
                    </div>

                    <table className="mt-6 w-full text-sm">
                        <tbody>
                            <tr className="border-y border-black/20">
                                <td className="py-2">Salary earned and not yet paid</td>
                                <td className="py-2 text-right">{money(figures.unpaid_salary)}</td>
                            </tr>
                            <tr className="border-b border-black/10">
                                <td className="py-2">Loan outstanding, recovered from this settlement</td>
                                <td className="py-2 text-right">
                                    {figures.loan_outstanding > 0 ? `(${money(figures.loan_outstanding)})` : money(0)}
                                </td>
                            </tr>
                            <tr className="border-b border-black/10">
                                <td className="py-2">
                                    Unused encashable leave
                                    {/* ⚠ Days, never money. No leave type in this business is
                                        encashable, so nothing here is owed and nothing is priced. */}
                                    <span className="block text-xs text-black/50">
                                        {figures.encashable_leave_days > 0
                                            ? `${figures.encashable_leave_days} day(s) on the ledger`
                                            : 'None encashable under the current leave policy'}
                                    </span>
                                </td>
                                <td className="py-2 text-right text-black/50">not paid</td>
                            </tr>
                        </tbody>
                    </table>

                    {can_total ? (
                        <div className="mt-4 flex justify-end">
                            <dl className="w-72 text-sm">
                                <div className="flex justify-between border-t border-black/20 py-2 font-semibold">
                                    <dt>{owedToEmployee ? 'Payable to the employee' : 'Recoverable from the employee'}</dt>
                                    <dd>BDT {money(Math.abs(net))}</dd>
                                </div>
                            </dl>
                        </div>
                    ) : (
                        <div className="mt-4 rounded-md border border-black/30 p-3 text-sm">
                            <p className="font-medium">No total is shown on this statement.</p>
                            <p className="text-black/70">
                                A leave type is marked encashable, and the rate at which unused leave is bought back has never been agreed. The parts
                                above are all recorded facts; the total is not, until that rate exists.
                            </p>
                        </div>
                    )}

                    <p className="mt-6 text-xs text-black/50">
                        Prepared from the payroll and loan ledgers. This statement records the position and settles nothing by itself.
                    </p>

                    <div className="mt-16 flex justify-between text-sm">
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">Employee</div>
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">For {issuer.name}</div>
                    </div>
                </div>

                {encashment_is_live && (
                    <p className="text-muted-foreground mx-auto max-w-2xl text-sm print:hidden">
                        Leave encashment is switched on for at least one leave type. Until the encashment rate is decided, this statement will not
                        total, and no settlement should be paid from it.
                    </p>
                )}
            </div>
        </AppLayout>
    );
}
