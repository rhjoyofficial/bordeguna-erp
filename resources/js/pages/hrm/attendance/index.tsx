import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Punch {
    id: number;
    employee_id: number;
    employee: string | null;
    employee_code: string | null;
    punched_at: string;
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

interface IndexProps {
    on: string;
    filters: { employee_id: number | null; review: boolean };
    punches: Punch[];
    summary: { total: number; needs_review: number; employees: number };
    employees: { id: number; name: string }[];
    directions: { value: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Punch log', href: '/hrm/attendance' }];

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

function ManualPunchDialog({ employees, directions, on }: { employees: { id: number; name: string }[]; directions: { value: string; label: string }[]; on: string }) {
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
                    <DialogDescription>
                        For the offline fallback — a manual register keyed in later. The punch is stored like any other and can never be edited
                        afterwards, so the reason below is what explains it to an auditor.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="employee_id">Employee</Label>
                        <select id="employee_id" className={selectClass} value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                            <option value="">— Select —</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </select>
                        {errors.employee_id && <p className="text-xs text-destructive">{errors.employee_id}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="punched_at">Date &amp; time</Label>
                            <Input id="punched_at" type="datetime-local" value={punchedAt} onChange={(e) => setPunchedAt(e.target.value)} />
                            {errors.punched_at && <p className="text-xs text-destructive">{errors.punched_at}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="direction">Direction (optional)</Label>
                            <select id="direction" className={selectClass} value={direction} onChange={(e) => setDirection(e.target.value)}>
                                <option value="">— Unknown —</option>
                                {directions.map((d) => (
                                    <option key={d.value} value={d.value}>
                                        {d.label}
                                    </option>
                                ))}
                            </select>
                            {errors.direction && <p className="text-xs text-destructive">{errors.direction}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="note">Reason</Label>
                        <Input
                            id="note"
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="e.g. Device offline, taken from the manual register"
                        />
                        {errors.note && <p className="text-xs text-destructive">{errors.note}</p>}
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
        router.get(route('hrm.attendance.index'), { on, ...filters, ...params }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Punch log" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Punch log</h2>
                        <p className="text-sm text-muted-foreground">
                            {summary.total} {summary.total === 1 ? 'punch' : 'punches'} from {summary.employees}{' '}
                            {summary.employees === 1 ? 'employee' : 'employees'}
                            {summary.needs_review > 0 && ` · ${summary.needs_review} flagged for review`}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Input type="date" className="w-40" value={on} onChange={(e) => reload({ on: e.target.value })} />
                        <select className={selectClass} value={filters.employee_id ?? ''} onChange={(e) => reload({ employee_id: e.target.value || null })}>
                            <option value="">All employees</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </select>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={filters.review} onChange={(e) => reload({ review: e.target.checked })} />
                            Flagged only
                        </label>
                        <ManualPunchDialog employees={employees} directions={directions} on={on} />
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    {punches.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No punches captured on {on}.</p>
                    ) : (
                        <table className="w-full min-w-[64rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Time</th>
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Direction</th>
                                    <th className="py-2">Source</th>
                                    <th className="py-2">Device / proof</th>
                                    <th className="py-2">Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                {punches.map((punch) => (
                                    <tr key={punch.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 font-mono text-xs">{punch.time}</td>
                                        <td className="py-2">
                                            {punch.employee}
                                            <span className="ml-2 text-xs text-muted-foreground">{punch.employee_code}</span>
                                            {punch.needs_review && (
                                                <Badge variant="outline" className="ml-2">
                                                    Review
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="py-2 text-muted-foreground">{punch.direction ?? '—'}</td>
                                        <td className="py-2 text-muted-foreground">{punch.source}</td>
                                        <td className="py-2 text-xs text-muted-foreground">
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
                                            {!punch.device_identifier && !punch.has_location && !punch.ip_address && !punch.enroll_id && '—'}
                                        </td>
                                        <td className="py-2 text-xs text-muted-foreground">
                                            {punch.note ?? '—'}
                                            {punch.recorded_by && <div>by {punch.recorded_by}</div>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <p className="text-xs text-muted-foreground">
                    Raw punches are immutable and cannot be edited or deleted. Corrections are recorded separately.
                </p>
            </div>
        </AppLayout>
    );
}
