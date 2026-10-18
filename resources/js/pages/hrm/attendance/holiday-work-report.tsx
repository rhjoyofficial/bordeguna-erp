import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Row = { employee_code: string | null; name: string | null; days: number };
type DayRow = { date: string; people: number };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Holiday work', href: '/hrm/holiday-work' },
    { title: 'Year report', href: '/hrm/holiday-work/report' },
];

/**
 * A year of holiday work, per person.
 *
 * ⚠ Counts APPROVED and applied movements only. A punch on a holiday that
 * nobody ever approved is not holiday work; it is an open question, and showing
 * it here would quietly turn it into a credit.
 */
export default function HolidayWorkReport({ year, rows, dates, total }: { year: number; rows: Row[]; dates: DayRow[]; total: number }) {
    const [pick, setPick] = useState(String(year));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Holiday work ${year}`} />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <HeadingSmall title={`Holiday work in ${year}`} />
                    <Link href="/hrm/holiday-work" className="text-sm underline">
                        Record holiday work
                    </Link>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="year">Year</Label>
                        <Input id="year" type="number" value={pick} onChange={(e) => setPick(e.target.value)} className="w-32" />
                    </div>
                    <Button variant="outline" onClick={() => router.get('/hrm/holiday-work/report', { year: pick }, { preserveState: true })}>
                        Show
                    </Button>
                </div>

                <p className="text-sm">
                    <span className="font-medium">{total}</span> holiday {total === 1 ? 'day' : 'days'} worked across {rows.length}{' '}
                    {rows.length === 1 ? 'person' : 'people'}, on {dates.length} {dates.length === 1 ? 'date' : 'dates'}.
                </p>

                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="border-border overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Employee</th>
                                    <th className="p-3 text-right">Days</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={2} className="text-muted-foreground p-3">
                                            No approved holiday work in {year}.
                                        </td>
                                    </tr>
                                )}
                                {rows.map((row) => (
                                    <tr key={row.employee_code ?? row.name} className="border-border border-t">
                                        <td className="p-3">
                                            <span className="font-medium">{row.name}</span>
                                            <span className="text-muted-foreground"> {row.employee_code}</span>
                                        </td>
                                        <td className="p-3 text-right">{row.days}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="border-border overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Date</th>
                                    <th className="p-3 text-right">People</th>
                                </tr>
                            </thead>
                            <tbody>
                                {dates.length === 0 && (
                                    <tr>
                                        <td colSpan={2} className="text-muted-foreground p-3">
                                            No dates yet.
                                        </td>
                                    </tr>
                                )}
                                {dates.map((row) => (
                                    <tr key={row.date} className="border-border border-t">
                                        <td className="p-3">{showDate(row.date)}</td>
                                        <td className="p-3 text-right">{row.people}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
