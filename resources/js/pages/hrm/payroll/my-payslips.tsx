import { UpcomingPayAdjustments, type UpcomingPayAdjustment } from '@/components/hrm/upcoming-pay-adjustments';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
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
    /** My deductions (27 Oct 2026): approved, not on a finished payslip yet. */
    upcoming_adjustments: UpcomingPayAdjustment[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My payslips', href: '/hrm/my-payslips' }];
const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4';
const rowClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b';
const headClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground';

const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function MyPayslips({ employee, password_hint, password_unavailable, payslips, upcoming_adjustments }: Props) {
    const { t } = useTranslation();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('payslips.title')} />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h2 className="text-lg font-medium">{t('payslips.title')}</h2>
                    <p className="text-muted-foreground text-sm">
                        {employee.name} - {employee.employee_code}
                    </p>
                </div>

                {password_unavailable ? (
                    <div className={cardClass}>
                        <p className="text-destructive text-sm">{password_unavailable}</p>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-xs">{t('payslips.password_note', { hint: password_hint })}</p>
                )}

                <UpcomingPayAdjustments rows={upcoming_adjustments} />

                {payslips.length === 0 ? (
                    <div className={cardClass}>
                        <p className="text-muted-foreground text-sm">{t('payslips.none')}</p>
                    </div>
                ) : (
                    <div className={cardClass}>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className={headClass}>
                                        <th className="py-2">{t('payslips.period')}</th>
                                        <th className="py-2 text-right">{t('payslips.gross')}</th>
                                        <th className="py-2 text-right">{t('payslips.earnings')}</th>
                                        <th className="py-2 text-right">{t('payslips.deductions')}</th>
                                        <th className="py-2 text-right">{t('payslips.net')}</th>
                                        <th className="py-2">{t('payslips.payment')}</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {payslips.map((payslip) => (
                                        <tr key={payslip.id} className={rowClass}>
                                            <td className="py-2">
                                                {payslip.period}
                                                <span className="text-muted-foreground block text-xs">{payslip.status}</span>
                                            </td>
                                            <td className="py-2 text-right">{money(payslip.gross_salary)}</td>
                                            <td className="py-2 text-right">{money(payslip.total_earnings)}</td>
                                            <td className="py-2 text-right">{money(payslip.total_deductions)}</td>
                                            <td className="py-2 text-right font-medium">{money(payslip.net)}</td>
                                            <td className="py-2">
                                                <Badge variant="outline">{payslip.payment_status}</Badge>
                                                {payslip.paid_total > 0 && (
                                                    <span className="text-muted-foreground ml-2 text-xs">
                                                        {money(payslip.paid_total)} {t('payslips.paid')}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                <div className="flex justify-end gap-1">
                                                    <a href={route('hrm.payslips.show', payslip.id)}>
                                                        <Button size="sm" variant="ghost">
                                                            {t('payslips.view')}
                                                        </Button>
                                                    </a>
                                                    {password_unavailable === null && (
                                                        <a href={route('hrm.payslips.pdf', payslip.id)}>
                                                            <Button size="sm" variant="outline">
                                                                {t('payslips.pdf')}
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
