import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
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
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Punch {
    id: number;
    time: string;
    direction: string | null;
    source: string;
    needs_review: boolean;
    review_reason: string | null;
}

interface RecentDay {
    date: string;
    status: string;
    first_in: string | null;
    last_out: string | null;
    holds_payroll: boolean;
    is_provisional: boolean;
    is_locked: boolean;
}

interface MyCorrection {
    id: number;
    date: string;
    summary: string;
    status: string;
    stage: string | null;
}

interface CorrectionType {
    value: string;
    label: string;
    needs_punch: boolean;
    needs_time: boolean;
}

interface ClockProps {
    employee: { name: string; employee_code: string; office: string | null };
    today: string;
    punches: Punch[];
    recent_days: RecentDay[];
    my_corrections: MyCorrection[];
    correction_types: CorrectionType[];
    directions: { value: string; label: string }[];
}

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

/**
 * An employee raising a correction on one of their own days (COR-01). It is a
 * request, not a change - it routes to their manager and then HR.
 */
function RequestCorrectionDialog({ day, types, directions }: { day: RecentDay; types: CorrectionType[]; directions: { value: string; label: string }[] }) {
    const [open, setOpen] = useState(false);
    const [type, setType] = useState(types[0]?.value ?? 'add_punch');
    const [correctedAt, setCorrectedAt] = useState(`${day.date}T18:00`);
    const [direction, setDirection] = useState('');
    const [reason, setReason] = useState('');
    const [evidence, setEvidence] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const selected = types.find((t) => t.value === type);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Request correction
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Request a correction for {day.date}</DialogTitle>
                    <DialogDescription>
                        Your manager reviews this first, then HR. Nothing changes until it is approved, and your original punches are never altered.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="ctype">What is wrong</Label>
                        <select id="ctype" className={selectClass} value={type} onChange={(e) => setType(e.target.value)}>
                            {types.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    {selected?.needs_time && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="ctime">Correct time</Label>
                                <Input id="ctime" type="datetime-local" value={correctedAt} onChange={(e) => setCorrectedAt(e.target.value)} />
                                {errors.corrected_at && <p className="text-xs text-destructive">{errors.corrected_at}</p>}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="cdir">Direction</Label>
                                <select id="cdir" className={selectClass} value={direction} onChange={(e) => setDirection(e.target.value)}>
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
                    {selected?.needs_punch && (
                        <p className="text-xs text-muted-foreground">
                            Amending or disregarding a specific punch needs HR to pick it - ask them, or choose “Add a missing punch”.
                        </p>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="creason">Reason</Label>
                        <Input id="creason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="What happened" />
                        {errors.reason && <p className="text-xs text-destructive">{errors.reason}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="cevidence">Evidence (optional)</Label>
                        <Input id="cevidence" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(e) => setEvidence(e.target.files?.[0] ?? null)} />
                    </div>
                    {errors.attendance_date && <p className="text-xs text-destructive">{errors.attendance_date}</p>}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={reason.trim() === '' || selected?.needs_punch}
                        onClick={() =>
                            router.post(
                                route('hrm.clock.corrections.store'),
                                {
                                    attendance_date: day.date,
                                    type,
                                    corrected_at: selected?.needs_time ? correctedAt.replace('T', ' ') + ':00' : null,
                                    direction: direction === '' ? null : direction,
                                    reason,
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
                                    },
                                },
                            )
                        }
                    >
                        Send request
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clock in/out', href: '/hrm/clock' }];

export default function Clock({ employee, today, punches, recent_days, my_corrections, correction_types, directions }: ClockProps) {
    const [busy, setBusy] = useState<string | null>(null);
    const [locationError, setLocationError] = useState<string | null>(null);

    /**
     * Location is captured in the browser and sent with the punch - the server
     * requires it, so a denied permission is explained here rather than turning
     * into a validation error the employee cannot act on.
     */
    const punch = (direction: 'in' | 'out') => {
        setLocationError(null);

        if (!navigator.geolocation) {
            setLocationError('This browser cannot share location. Ask HR to record your attendance manually.');
            return;
        }

        setBusy(direction);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                router.post(
                    route('hrm.clock.store'),
                    {
                        direction,
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        gps_accuracy_meters: position.coords.accuracy,
                        device_metadata: {
                            platform: navigator.platform,
                            language: navigator.language,
                            screen: `${window.screen.width}x${window.screen.height}`,
                        },
                    },
                    {
                        preserveScroll: true,
                        onFinish: () => setBusy(null),
                    },
                );
            },
            (error) => {
                setBusy(null);
                setLocationError(
                    error.code === error.PERMISSION_DENIED
                        ? 'Location permission was denied. Allow it in your browser, or ask HR to record your attendance manually.'
                        : 'Could not read your location. Try again, or ask HR to record your attendance manually.',
                );
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clock in/out" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h2 className="text-lg font-medium">{employee.name}</h2>
                    <p className="text-sm text-muted-foreground">
                        {employee.employee_code}
                        {employee.office && ` · ${employee.office}`} · {today}
                    </p>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap gap-3 rounded-xl border p-4">
                    <Button onClick={() => punch('in')} disabled={busy !== null}>
                        {busy === 'in' ? 'Recording…' : 'Clock in'}
                    </Button>
                    <Button variant="outline" onClick={() => punch('out')} disabled={busy !== null}>
                        {busy === 'out' ? 'Recording…' : 'Clock out'}
                    </Button>
                </div>

                {locationError && <p className="text-sm text-destructive">{locationError}</p>}

                <p className="text-xs text-muted-foreground">
                    Your location, accuracy, IP address and device details are recorded with each punch. If you are away from your office the punch is
                    still accepted, and flagged for your manager to review.
                </p>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Today</h3>
                    {punches.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Nothing recorded yet today.</p>
                    ) : (
                        <table className="w-full min-w-[32rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Time</th>
                                    <th className="py-2">Direction</th>
                                    <th className="py-2">Source</th>
                                    <th className="py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {punches.map((p) => (
                                    <tr key={p.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 font-mono text-xs">{p.time}</td>
                                        <td className="py-2">{p.direction ?? '-'}</td>
                                        <td className="py-2 text-muted-foreground">{p.source}</td>
                                        <td className="py-2">
                                            {p.needs_review ? (
                                                <span className="text-xs text-muted-foreground">
                                                    <Badge variant="outline">Flagged</Badge>
                                                    {p.review_reason && <span className="ml-2">{p.review_reason}</span>}
                                                </span>
                                            ) : (
                                                <Badge variant="secondary">Recorded</Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Recent days</h3>
                    {recent_days.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Nothing processed yet.</p>
                    ) : (
                        <table className="w-full min-w-[40rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Date</th>
                                    <th className="py-2">Status</th>
                                    <th className="py-2">In / Out</th>
                                    <th className="py-2 text-right">Something wrong?</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recent_days.map((day) => (
                                    <tr key={day.date} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 whitespace-nowrap">{day.date}</td>
                                        <td className="py-2">
                                            {day.status}
                                            {day.holds_payroll && (
                                                <Badge variant="outline" className="ml-2">
                                                    Needs fixing
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="py-2 font-mono text-xs text-muted-foreground">
                                            {day.first_in ?? '-'} / {day.last_out ?? '-'}
                                        </td>
                                        <td className="py-2 text-right">
                                            {day.is_locked ? (
                                                <span className="text-xs text-muted-foreground">Period closed - ask HR</span>
                                            ) : (
                                                <RequestCorrectionDialog day={day} types={correction_types} directions={directions} />
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {my_corrections.length > 0 && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                        <h3 className="mb-3 text-sm font-medium">My correction requests</h3>
                        <table className="w-full min-w-[40rem] text-sm">
                            <tbody>
                                {my_corrections.map((c) => (
                                    <tr key={c.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 whitespace-nowrap">{c.date}</td>
                                        <td className="py-2 text-muted-foreground">{c.summary}</td>
                                        <td className="py-2">
                                            <Badge variant={c.status === 'approved' ? 'default' : 'secondary'}>{c.status}</Badge>
                                            {c.stage && <span className="ml-2 text-xs text-muted-foreground">at {c.stage}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
