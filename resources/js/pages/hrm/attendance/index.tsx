import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Punch {
    id: number;
    employee_id: number;
    employee: string | null;
    employee_code: string | null;
    punched_at: string;
    date: string;
    weekday: string;
    time: string;
    source: string;
    direction: string | null;
    device_identifier: string | null;
    enroll_id: string | null;
    has_location: boolean;
    location: string | null;
    gps_accuracy_meters: string | null;
    ip_address: string | null;
    is_outside_geofence: boolean;
    needs_review: boolean;
    review_reason: string | null;
    note: string | null;
    recorded_by: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface IndexProps {
    on: string;
    filters: { from: string; to: string; employee_id: number | null; review: boolean; sort: string; dir: string };
    punches: Paginated<Punch>;
    summary: { total: number; needs_review: number; employees: number; days: number };
    employees: { id: number; name: string }[];
    directions: { value: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Punch log', href: '/hrm/attendance' }];

function ManualPunchDialog({
    employees,
    directions,
    on,
}: {
    employees: { id: number; name: string }[];
    directions: { value: string; label: string }[];
    on: string;
}) {
    const [open, setOpen] = useState(false);
    const [employeeId, setEmployeeId] = useState('');
    const [punchedAt, setPunchedAt] = useState(`${on}T09:00`);
    const [direction, setDirection] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = () => {
        router.post(
            route('hrm.attendance.store'),
            {
                employee_id: Number(employeeId),
                punched_at: punchedAt.replace('T', ' ') + ':00',
                direction: direction === '' ? null : direction,
                note,
            },
            {
                preserveScroll: true,
                onError: (e: Record<string, string>) => setErrors(e),
                onSuccess: () => {
                    setOpen(false);
                    setErrors({});
                    setNote('');
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Record punch</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record a punch manually</DialogTitle>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="employee_id" required>
                            Employee
                        </Label>
                        <SearchSelect id="employee_id" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                            <option value="">- Select -</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.employee_id && <p className="text-destructive text-xs">{errors.employee_id}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="punched_at" required>
                                Date &amp; time
                            </Label>
                            <Input
                                id="punched_at"
                                aria-required
                                type="datetime-local"
                                value={punchedAt}
                                onChange={(e) => setPunchedAt(e.target.value)}
                            />
                            {errors.punched_at && <p className="text-destructive text-xs">{errors.punched_at}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="direction">Direction (optional)</Label>
                            <SearchSelect id="direction" value={direction} onChange={(e) => setDirection(e.target.value)}>
                                <option value="">- Unknown -</option>
                                {directions.map((d) => (
                                    <option key={d.value} value={d.value}>
                                        {d.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            {errors.direction && <p className="text-destructive text-xs">{errors.direction}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="note" required>
                            Reason
                        </Label>
                        <Input
                            id="note"
                            aria-required
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="e.g. Device offline, taken from the manual register"
                        />
                        {errors.note && <p className="text-destructive text-xs">{errors.note}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={employeeId === '' || note.trim() === ''}>
                        Record
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function AttendanceIndex({ on, filters, punches, summary, employees, directions }: IndexProps) {
    const reload = (params: Record<string, string | number | boolean | null>) => {
        // `on` is deliberately dropped: it is the single-day shortcut, and
        // carrying it alongside from/to would pin the range to one day forever.
        router.get(
            route('hrm.attendance.index'),
            { ...filters, ...params, page: null },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const sortBy = (column: 'time' | 'employee') => reload({ sort: column, dir: filters.sort === column && filters.dir === 'desc' ? 'asc' : 'desc' });

    const arrow = (column: string) => (filters.sort === column ? (filters.dir === 'asc' ? ' ↑' : ' ↓') : '');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Punch log" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Punch log</h2>
                        <p className="text-muted-foreground text-sm">
                            {summary.total} {summary.total === 1 ? 'punch' : 'punches'} from {summary.employees}{' '}
                            {summary.employees === 1 ? 'employee' : 'employees'} across {summary.days} {summary.days === 1 ? 'day' : 'days'}
                            {summary.needs_review > 0 && ` · ${summary.needs_review} flagged for review`}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Label htmlFor="from" className="text-muted-foreground text-sm">
                            From
                        </Label>
                        <Input id="from" type="date" value={filters.from} onChange={(e) => reload({ from: e.target.value })} />
                        <Label htmlFor="to" className="text-muted-foreground text-sm">
                            To
                        </Label>
                        <Input id="to" type="date" value={filters.to} onChange={(e) => reload({ to: e.target.value })} />
                        <SearchSelect value={filters.employee_id ?? ''} onChange={(e) => reload({ employee_id: e.target.value || null })}>
                            <option value="">All employees</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={filters.review} onChange={(e) => reload({ review: e.target.checked })} />
                            Flagged only
                        </label>
                        <ManualPunchDialog employees={employees} directions={directions} on={on} />
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    {punches.data.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No punches captured between {filters.from} and {filters.to}. Widen the dates, or check the import screen.
                        </p>
                    ) : (
                        <table className="w-full min-w-[64rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">
                                        <button type="button" className="hover:text-foreground" onClick={() => sortBy('time')}>
                                            Date &amp; time{arrow('time')}
                                        </button>
                                    </th>
                                    <th className="py-2">
                                        <button type="button" className="hover:text-foreground" onClick={() => sortBy('employee')}>
                                            Employee{arrow('employee')}
                                        </button>
                                    </th>
                                    <th className="py-2">Direction</th>
                                    <th className="py-2">Source</th>
                                    <th className="py-2">Device / proof</th>
                                    <th className="py-2">Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                {punches.data.map((punch) => (
                                    <tr key={punch.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 font-mono text-xs whitespace-nowrap">
                                            {showDate(punch.date)} <span className="text-muted-foreground">{punch.weekday}</span> {punch.time}
                                        </td>
                                        <td className="py-2">
                                            {punch.employee}
                                            <span className="text-muted-foreground ml-2 text-xs">{punch.employee_code}</span>
                                            {punch.needs_review && (
                                                <Badge variant="outline" className="ml-2">
                                                    Review
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2">{punch.direction ?? '-'}</td>
                                        <td className="text-muted-foreground py-2">{punch.source}</td>
                                        <td className="text-muted-foreground py-2 text-xs">
                                            {punch.device_identifier && <div>Device: {punch.device_identifier}</div>}
                                            {punch.enroll_id && <div>Enroll: {punch.enroll_id}</div>}
                                            {punch.has_location && (
                                                <div>
                                                    {punch.location}
                                                    {punch.gps_accuracy_meters && ` (±${punch.gps_accuracy_meters} m)`}
                                                </div>
                                            )}
                                            {punch.ip_address && <div>IP: {punch.ip_address}</div>}
                                            {punch.review_reason && <div className="text-destructive">{punch.review_reason}</div>}
                                            {!punch.device_identifier && !punch.has_location && !punch.ip_address && !punch.enroll_id && '-'}
                                        </td>
                                        <td className="text-muted-foreground py-2 text-xs">
                                            {punch.note ?? '-'}
                                            {punch.recorded_by && <div>by {punch.recorded_by}</div>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {punches.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            Showing {punches.from}–{punches.to} of {punches.total}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={punches.prev_page_url === null}
                                onClick={() =>
                                    punches.prev_page_url && router.get(punches.prev_page_url, {}, { preserveState: true, preserveScroll: true })
                                }
                            >
                                Previous
                            </Button>
                            <span className="text-muted-foreground self-center">
                                Page {punches.current_page} of {punches.last_page}
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={punches.next_page_url === null}
                                onClick={() =>
                                    punches.next_page_url && router.get(punches.next_page_url, {}, { preserveState: true, preserveScroll: true })
                                }
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
