import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

/**
 * The cash forecast (Module 2, Slice 6): eight weeks ahead from documents
 * that already exist. A week with no documents forecasts zero movement -
 * that honesty is the feature. The first week the running balance goes
 * negative is flagged in red, because that is the week the forecast exists
 * to catch.
 */
interface ForecastWeek {
    week_start: string;
    in_invoices: number;
    overdue_in: number;
    out_bills: number;
    out_recurring: number;
    out_payroll_estimate: number;
    net: number;
    running: number;
}

interface CashForecastProps {
    opening_cash: number;
    weeks: ForecastWeek[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cash forecast', href: '/accounting/cash-forecast' }];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function CashForecast({ opening_cash, weeks }: CashForecastProps) {
    const firstNegative = weeks.findIndex((week) => week.running < 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cash forecast" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center gap-4">
                    <div className="border-border bg-card rounded-lg border px-4 py-3">
                        <p className="text-muted-foreground text-xs">Cash right now, all accounts</p>
                        <p className="text-lg font-semibold tabular-nums">{money(opening_cash)} BDT</p>
                    </div>
                    {firstNegative >= 0 && (
                        <div className="border-destructive/40 bg-destructive/5 rounded-lg border px-4 py-3">
                            <p className="text-destructive text-sm font-semibold">
                                ⚠ Cash goes negative in the week of {weeks[firstNegative].week_start}
                            </p>
                        </div>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Week of</th>
                                <th className="px-3 py-2 text-right font-medium">In: invoices due</th>
                                <th className="px-3 py-2 text-right font-medium">Out: bills</th>
                                <th className="px-3 py-2 text-right font-medium">Out: standing costs</th>
                                <th className="px-3 py-2 text-right font-medium">Out: payroll (est.)</th>
                                <th className="px-3 py-2 text-right font-medium">Net</th>
                                <th className="px-3 py-2 text-right font-semibold">Running cash</th>
                            </tr>
                        </thead>
                        <tbody>
                            {weeks.map((week, index) => (
                                <tr key={week.week_start} className={'border-t ' + (week.running < 0 ? 'bg-destructive/5' : '')}>
                                    <td className="px-3 py-2">
                                        {showDate(week.week_start)}
                                        {index === 0 && week.overdue_in > 0 && (
                                            <span className="text-muted-foreground block text-xs">
                                                includes {money(week.overdue_in)} already overdue
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">{week.in_invoices !== 0 ? money(week.in_invoices) : '-'}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{week.out_bills !== 0 ? money(week.out_bills) : '-'}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {week.out_recurring !== 0 ? money(week.out_recurring) : '-'}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {week.out_payroll_estimate !== 0 ? money(week.out_payroll_estimate) : '-'}
                                    </td>
                                    <td className={'px-3 py-2 text-right tabular-nums ' + (week.net < 0 ? 'text-destructive' : '')}>
                                        {money(week.net)}
                                    </td>
                                    <td className={'px-3 py-2 text-right font-semibold tabular-nums ' + (week.running < 0 ? 'text-destructive' : '')}>
                                        {money(week.running)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
