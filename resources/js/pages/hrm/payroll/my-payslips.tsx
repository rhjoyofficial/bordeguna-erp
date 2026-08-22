import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface PayslipRow {
    id: number;
    period: string | null;
    status: string | null;
    gross_salary: number;
    total_earnings: number;
    total_deductions: number;
    net: number;
    payment_status: string;
    paid_total: number;
    emailed_at: string | null;
}

interface Props {
    employee: { name: string; employee_code: string | null };
    password_hint: string;
    password_unavailable: string | null;
    payslips: PayslipRow[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My payslips', href: '/hrm/my-payslips' }];
const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4';
const rowClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b';
const headClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground';

const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function MyPayslips({ employee, password_hint, password_unavailable, payslips }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My payslips" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h2 className="text-lg font-medium">My payslips</h2>
                    <p className="text-sm text-muted-foreground">
                        {employee.name} - {employee.employee_code}. A payslip appears here once its payroll has been approved.
                    </p>
                </div>

                {password_unavailable ? (
                    <div className={cardClass}>
                        <p className="text-sm text-destructive">{password_unavailable}</p>
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">Downloaded payslips are password protected. Your password: {password_hint}</p>
                )}

                {payslips.length === 0 ? (
                    <div className={cardClass}>
                        <p className="text-sm text-muted-foreground">No payslips yet.</p>
                    </div>
                ) : (
                    <div className={cardClass}>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className={headClass}>
                                        <th className="py-2">Period</th>
                                        <th className="py-2 text-right">Gross</th>
                                        <th className="py-2 text-right">Earnings</th>
                                        <th className="py-2 text-right">Deductions</th>
                                        <th className="py-2 text-right">Net</th>
                                        <th className="py-2">Payment</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {payslips.map((payslip) => (
                                        <tr key={payslip.id} className={rowClass}>
                                            <td className="py-2">
                                                {payslip.period}
                                                <span className="block text-xs text-muted-foreground">{payslip.status}</span>
                                            </td>
                                            <td className="py-2 text-right">{money(payslip.gross_salary)}</td>
                                            <td className="py-2 text-right">{money(payslip.total_earnings)}</td>
                                            <td className="py-2 text-right">{money(payslip.total_deductions)}</td>
                                            <td className="py-2 text-right font-medium">{money(payslip.net)}</td>
                                            <td className="py-2">
                                                <Badge variant="outline">{payslip.payment_status}</Badge>
                                                {payslip.paid_total > 0 && (
                                                    <span className="ml-2 text-xs text-muted-foreground">{money(payslip.paid_total)} paid</span>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                <div className="flex justify-end gap-1">
                                                    <a href={route('hrm.payslips.show', payslip.id)}>
                                                        <Button size="sm" variant="ghost">
                                                            View
                                                        </Button>
                                                    </a>
                                                    {password_unavailable === null && (
                                                        <a href={route('hrm.payslips.pdf', payslip.id)}>
                                                            <Button size="sm" variant="outline">
                                                                PDF
                                                            </Button>
                                                        </a>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
