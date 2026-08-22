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
import { useEffect, useState } from 'react';

interface Correction {
    id: number;
    employee: string | null;
    employee_code: string | null;
    attendance_date: string;
    type: string;
    summary: string;
    reason: string;
    override_reason: string | null;
    requested_by: string | null;
    status: string;
    stage: string | null;
    is_applied: boolean;
    applied_at: string | null;
}

interface Lock {
    id: number;
    period_start: string;
    period_end: string;
    note: string | null;
    is_active: boolean;
    locked_by: string | null;
    unlocked_by: string | null;
    unlock_reason: string | null;
}

interface CorrectionType {
    value: string;
    label: string;
    needs_punch: boolean;
    needs_time: boolean;
}

interface Props {
    corrections: Correction[];
    locks: Lock[];
    types: CorrectionType[];
    directions: { value: string; label: string }[];
    employees: { id: number; name: string }[];
    prefill?: { employee_id: number | null; attendance_date: string | null };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Punch log', href: '/hrm/attendance' },
    { title: 'Corrections', href: '/hrm/attendance/corrections' },
];

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

/**
 * A datetime-local value to the timestamp the API expects.
 *
 * ⚠⚠ This used to be `value.replace('T', ' ') + ':00'`, which appended seconds
 * whether or not the value already had them. Feed it a time that DOES carry
 * seconds and it built "2026-08-08 11:13:33:00" - not a timestamp, so the
 * request failed validation and the dialog simply sat there.
 *
 * Seconds matter here more than anywhere else in the app: the commonest reason
 * to amend a punch back is to restore what the DEVICE recorded, and the device
 * records to the second. A form that can only say 11:13 cannot put 11:13:33
 * back, so an "undo" would quietly land 33 seconds away from the original.
 */
function toTimestamp(value: string): string {
    const [datePart, timePart = '00:00'] = value.split('T');
    const [hh = '00', mm = '00', ss = '00'] = timePart.split(':');

    return `${datePart} ${hh.padStart(2, '0')}:${mm.padStart(2, '0')}:${ss.padStart(2, '0')}`;
}

function CorrectionDialog({ types, directions, employees, prefill }: { types: CorrectionType[]; directions: { value: string; label: string }[]; employees: { id: number; name: string }[]; prefill?: { employee_id: number | null; attendance_date: string | null } }) {
    // Opens by itself when the Fix button on a held day sent us here, so the
    // journey from "this day blocks payroll" to "here is why, and here is the
    // correction" is one click rather than a hunt through the screen.
    const [open, setOpen] = useState(prefill?.employee_id != null && prefill?.attendance_date != null);
    const [employeeId, setEmployeeId] = useState(prefill?.employee_id != null ? String(prefill.employee_id) : '');
    const [date, setDate] = useState(prefill?.attendance_date ?? new Date().toISOString().slice(0, 10));
    const [type, setType] = useState(types[0]?.value ?? 'add_punch');
    const [punchId, setPunchId] = useState('');
    const [correctedAt, setCorrectedAt] = useState('');
    const [direction, setDirection] = useState('');
    const [reason, setReason] = useState('');
    const [overrideReason, setOverrideReason] = useState('');
    const [evidence, setEvidence] = useState<File | null>(null);
    const [punches, setPunches] = useState<{ id: number; label: string }[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const selected = types.find((t) => t.value === type);

    // Load that employee's punches for the day so a correction points at a real
    // one rather than an id typed from memory.
    useEffect(() => {
        if (!open || employeeId === '' || !selected?.needs_punch) return;
        fetch(route('hrm.attendance.corrections.punches', { employee_id: employeeId, date }), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then(setPunches)
            .catch(() => setPunches([]));
    }, [open, employeeId, date, type, selected?.needs_punch]);

    const submit = () => {
        router.post(
            route('hrm.attendance.corrections.store'),
            {
                employee_id: Number(employeeId),
                attendance_date: date,
                type,
                punch_id: selected?.needs_punch && punchId !== '' ? Number(punchId) : null,
                corrected_at: selected?.needs_time && correctedAt !== '' ? toTimestamp(correctedAt) : null,
                direction: direction === '' ? null : direction,
                reason,
                override_reason: overrideReason === '' ? null : overrideReason,
                evidence,
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (e: Record<string, string>) => setErrors(e),
                onSuccess: () => {
                    setOpen(false);
                    setErrors({});
                    setReason('');
                    setOverrideReason('');
                    setEvidence(null);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Record correction</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record a correction</DialogTitle>
                    <DialogDescription>
                        The original punches are never changed - a correction sits alongside them and the day is reprocessed against it. Recording one
                        here applies it immediately, which is the privileged HR path.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="employee_id">Employee</Label>
                            <select id="employee_id" className={selectClass} value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                                <option value="">- Select -</option>
                                {employees.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.name}
                                    </option>
                                ))}
                            </select>
                            {errors.employee_id && <p className="text-xs text-destructive">{errors.employee_id}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="attendance_date">Date</Label>
                            <Input id="attendance_date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                            {errors.attendance_date && <p className="text-xs text-destructive">{errors.attendance_date}</p>}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="type">What needs correcting</Label>
                        <select id="type" className={selectClass} value={type} onChange={(e) => setType(e.target.value)}>
                            {types.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {selected?.needs_punch && (
                        <div className="grid gap-2">
                            <Label htmlFor="punch_id">Which punch</Label>
                            <select id="punch_id" className={selectClass} value={punchId} onChange={(e) => setPunchId(e.target.value)}>
                                <option value="">- Select -</option>
                                {punches.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                            {punches.length === 0 && employeeId !== '' && (
                                <p className="text-xs text-muted-foreground">No punches captured on this date.</p>
                            )}
                            {errors.punch_id && <p className="text-xs text-destructive">{errors.punch_id}</p>}
                        </div>
                    )}

                    {selected?.needs_time && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="corrected_at">Correct time</Label>
                                <Input
                                    id="corrected_at"
                                    type="datetime-local"
                                    // step=1 puts a seconds field on the control. Without it the
                                    // browser rounds to the minute and a device time cannot be
                                    // restored exactly.
                                    step={1}
                                    value={correctedAt}
                                    onChange={(e) => setCorrectedAt(e.target.value)}
                                />
                                {errors.corrected_at && <p className="text-xs text-destructive">{errors.corrected_at}</p>}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="direction">Direction</Label>
                                <select id="direction" className={selectClass} value={direction} onChange={(e) => setDirection(e.target.value)}>
                                    <option value="">- Unknown -</option>
                                    {directions.map((d) => (
                                        <option key={d.value} value={d.value}>
                                            {d.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="reason">Reason</Label>
                        <Input id="reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why this correction is needed" />
                        {errors.reason && <p className="text-xs text-destructive">{errors.reason}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="evidence">Evidence (optional)</Label>
                        <Input id="evidence" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(e) => setEvidence(e.target.files?.[0] ?? null)} />
                        {errors.evidence && <p className="text-xs text-destructive">{errors.evidence}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="override_reason">Override reason (only if the period is locked)</Label>
                        <Input
                            id="override_reason"
                            value={overrideReason}
                            onChange={(e) => setOverrideReason(e.target.value)}
                            placeholder="Required to correct a locked period"
                        />
                        {errors.override_reason && <p className="text-xs text-destructive">{errors.override_reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={employeeId === '' || reason.trim() === ''}>
                        Record
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function LockDialog() {
    const [open, setOpen] = useState(false);
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    Lock a period
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Lock an attendance period</DialogTitle>
                    <DialogDescription>
                        Employees can no longer request corrections inside a locked period, and HR needs a stated override reason to change it.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="period_start">From</Label>
                            <Input id="period_start" type="date" value={start} onChange={(e) => setStart(e.target.value)} />
                            {errors.period_start && <p className="text-xs text-destructive">{errors.period_start}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="period_end">To</Label>
                            <Input id="period_end" type="date" value={end} onChange={(e) => setEnd(e.target.value)} />
                            {errors.period_end && <p className="text-xs text-destructive">{errors.period_end}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="lock_note">Note (optional)</Label>
                        <Input id="lock_note" value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Week 32, payroll cutoff" />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={start === '' || end === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.attendance.locks.store'),
                                { period_start: start, period_end: end, note: note === '' ? null : note },
                                {
                                    preserveScroll: true,
                                    onError: (e: Record<string, string>) => setErrors(e),
                                    onSuccess: () => {
                                        setOpen(false);
                                        setErrors({});
                                    },
                                },
                            )
                        }
                    >
                        Lock
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function UnlockDialog({ lock }: { lock: Lock }) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Reopen
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reopen this period</DialogTitle>
                    <DialogDescription>The lock is kept as history - reopening records who did it and why.</DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="unlock_reason">Reason</Label>
                    <Input id="unlock_reason" value={reason} onChange={(e) => setReason(e.target.value)} />
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={reason.trim() === ''}
                        onClick={() =>
                            router.patch(
                                route('hrm.attendance.locks.unlock', lock.id),
                                { unlock_reason: reason },
                                { preserveScroll: true, onSuccess: () => setOpen(false) },
                            )
                        }
                    >
                        Reopen
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function Corrections({ corrections, locks, types, directions, employees, prefill }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Attendance corrections" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Attendance corrections</h2>
                        <p className="text-sm text-muted-foreground">
                            Raw punches are never edited. A correction is recorded next to them and the day is reprocessed.
                        </p>
                    </div>
                    <div className="flex gap-3">
                        <LockDialog />
                        <CorrectionDialog types={types} directions={directions} employees={employees} prefill={prefill} />
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Corrections</h3>
                    {corrections.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No corrections recorded yet.</p>
                    ) : (
                        <table className="w-full min-w-[60rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Date</th>
                                    <th className="py-2">Correction</th>
                                    <th className="py-2">Reason</th>
                                    <th className="py-2">Requested by</th>
                                    <th className="py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {corrections.map((c) => (
                                    <tr key={c.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 whitespace-nowrap">{c.attendance_date}</td>
                                        <td className="py-2">
                                            {c.summary}
                                            {c.override_reason && (
                                                <div className="text-xs text-destructive">Locked-period override: {c.override_reason}</div>
                                            )}
                                        </td>
                                        <td className="py-2 text-xs text-muted-foreground">{c.reason}</td>
                                        <td className="py-2 text-muted-foreground">{c.requested_by ?? '-'}</td>
                                        <td className="py-2">
                                            {c.is_applied ? (
                                                <Badge variant="default">Applied</Badge>
                                            ) : (
                                                <Badge variant="secondary">{c.status}</Badge>
                                            )}
                                            {c.stage && !c.is_applied && <span className="ml-2 text-xs text-muted-foreground">at {c.stage}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Locked periods</h3>
                    {locks.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No periods locked. Lock a week or a payroll cutoff to close it to further corrections.
                        </p>
                    ) : (
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Period</th>
                                    <th className="py-2">Note</th>
                                    <th className="py-2">Status</th>
                                    <th className="py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {locks.map((lock) => (
                                    <tr key={lock.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 whitespace-nowrap">
                                            {lock.period_start} → {lock.period_end}
                                        </td>
                                        <td className="py-2 text-muted-foreground">{lock.note ?? '-'}</td>
                                        <td className="py-2">
                                            {lock.is_active ? (
                                                <Badge variant="default">Locked</Badge>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    <Badge variant="secondary">Reopened</Badge>
                                                    {lock.unlock_reason && <span className="ml-2">{lock.unlock_reason}</span>}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 text-right">{lock.is_active && <UnlockDialog lock={lock} />}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
