import { ReportHeading } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Row = {
    employee_id: number;
    employee_code: string | null;
    name: string | null;
    status: string | null;
    first_in: string | null;
    last_out: string | null;
    worked_minutes: number;
    payable_minutes: number;
    has_punch: boolean;
    already_credited: boolean;
    needs_review: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Holiday work', href: '/hrm/holiday-work' }];

/**
 * Credit work done on a day nobody had to come in.
 *
 * ⚠ People who actually punched are listed first and pre-ticked. The system
 * already knows who came in; making somebody retype that from memory is how
 * a person gets missed.
 */
export default function HolidayWork({ date, is_non_working_day, rows }: { date: string; is_non_working_day: boolean; rows: Row[] }) {
    const [day, setDay] = useState(date);

    const form = useForm({
        date,
        employee_ids: rows.filter((r) => r.has_punch && !r.already_credited).map((r) => r.employee_id),
        reason: '',
    });

    const toggle = (id: number) =>
        form.setData(
            'employee_ids',
            form.data.employee_ids.includes(id) ? form.data.employee_ids.filter((x) => x !== id) : [...form.data.employee_ids, id],
        );

    const load = () => router.get('/hrm/holiday-work', { date: day }, { preserveState: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Holiday work" />

            <div className="space-y-6 p-4">
                <ReportHeading
                    title="Holiday work"
                    description="Record who worked on a holiday or weekly off. Approved days count as normal present days."
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/hrm/holiday-work/report">Year report</Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="date" required>
                            Date
                        </Label>
                        <Input id="date" type="date" value={day} onChange={(e) => setDay(e.target.value)} />
                    </div>
                    <Button variant="outline" onClick={load}>
                        Show this day
                    </Button>
                </div>

                {!is_non_working_day && (
                    <p className="border-border bg-muted/40 rounded-md border p-3 text-sm">
                        This date is not a holiday or weekly off for anyone, so there is no holiday work to credit. If it should be a holiday, add it
                        to the holiday calendar first and the day will be reprocessed.
                    </p>
                )}

                <div className="border-border overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="w-10 p-3"> </th>
                                <th className="p-3">Employee</th>
                                <th className="p-3">Day</th>
                                <th className="p-3">In</th>
                                <th className="p-3">Out</th>
                                <th className="p-3">State</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-3">
                                        Nothing has been processed for this date yet.
                                    </td>
                                </tr>
                            )}
                            {rows.map((row) => (
                                <tr key={row.employee_id} className="border-border border-t">
                                    <td className="p-3">
                                        <Checkbox
                                            checked={form.data.employee_ids.includes(row.employee_id)}
                                            disabled={row.already_credited}
                                            onCheckedChange={() => toggle(row.employee_id)}
                                            aria-label={`Credit ${row.name}`}
                                        />
                                    </td>
                                    <td className="p-3">
                                        <span className="font-medium">{row.name}</span>
                                        <span className="text-muted-foreground"> {row.employee_code}</span>
                                    </td>
                                    <td className="text-muted-foreground p-3">{row.status}</td>
                                    <td className="p-3">{row.first_in ?? '-'}</td>
                                    <td className="p-3">
                                        {row.last_out ?? (row.first_in ? <span className="text-destructive">never clocked out</span> : '-')}
                                    </td>
                                    <td className="p-3">
                                        {row.already_credited ? (
                                            <span className="text-emerald-700 dark:text-emerald-300">Already recorded</span>
                                        ) : row.needs_review ? (
                                            <span className="text-destructive">Waiting for a decision</span>
                                        ) : row.has_punch ? (
                                            'Punched, not credited'
                                        ) : (
                                            <span className="text-muted-foreground">No punch</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="reason" required>
                        Why they worked
                    </Label>
                    <Input
                        id="reason"
                        value={form.data.reason}
                        placeholder="Dispatch for the Savar order"
                        onChange={(e) => form.setData('reason', e.target.value)}
                        className="max-w-xl"
                    />
                    {form.errors.reason && <p className="text-destructive text-sm">{form.errors.reason}</p>}
                </div>

                <div className="flex items-center gap-3">
                    <Button
                        disabled={form.processing || form.data.employee_ids.length === 0 || form.data.reason.trim() === ''}
                        onClick={() => {
                            // The date box is local state so the day can be changed
                            // without a round trip; the form has to be told before it posts.
                            form.transform((d) => ({ ...d, date: day }));
                            form.post('/hrm/holiday-work', { preserveScroll: true });
                        }}
                    >
                        Record for {form.data.employee_ids.length} {form.data.employee_ids.length === 1 ? 'person' : 'people'}
                    </Button>
                    <p className="text-muted-foreground text-sm">
                        Their day is recalculated straight away. Nobody is paid extra: an approved holiday day counts as a normal present day.
                    </p>
                </div>
            </div>
        </AppLayout>
    );
}
