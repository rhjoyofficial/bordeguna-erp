import SelfServiceTabs from '@/components/self-service-tabs';
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

interface Balance {
    id: number;
    name: string;
    code: string;
    deducts_balance: boolean;
    annual_quota: number;
    balance: number;
    taken: number;
    can_carry_forward: boolean;
    carry_forward_cap: number | null;
}

interface LedgerEntry {
    id: number;
    date: string;
    type: string | null;
    entry_type: string;
    days: number;
    note: string | null;
}

interface LeaveRow {
    id: number;
    leave_type: string | null;
    start_date: string;
    end_date: string;
    days: number;
    unpaid_days: number;
    status: string;
    stage: string | null;
}

interface Props {
    year: number;
    employee: { name: string; employee_code: string };
    balances: Balance[];
    ledger: LedgerEntry[];
    requests: LeaveRow[];
    movements: { id: number; summary: string; status: string }[];
    leave_types: { id: number; name: string; allows_half_day: boolean; deducts_balance: boolean }[];
    portions: { value: string; label: string }[];
    movement_types: { value: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My leave', href: '/hrm/my-leave' }];
const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

function RequestLeaveDialog({ types, portions }: { types: Props['leave_types']; portions: Props['portions'] }) {
    const [open, setOpen] = useState(false);
    const [typeId, setTypeId] = useState('');
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');
    const [portion, setPortion] = useState('full_day');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Request leave</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Request leave</DialogTitle>
                    <DialogDescription>
                        Holidays and weekly offs inside the dates are not charged. If your balance does not cover it, the remainder is recorded as
                        unpaid rather than refused.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="leave_type_id">Leave type</Label>
                        <select id="leave_type_id" className={selectClass} value={typeId} onChange={(e) => setTypeId(e.target.value)}>
                            <option value="">- Select -</option>
                            {types.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </select>
                        {errors.leave_type_id && <p className="text-destructive text-xs">{errors.leave_type_id}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="start_date">From</Label>
                            <Input
                                id="start_date"
                                type="date"
                                value={start}
                                onChange={(e) => {
                                    setStart(e.target.value);
                                    if (end === '') setEnd(e.target.value);
                                }}
                            />
                            {errors.start_date && <p className="text-destructive text-xs">{errors.start_date}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="end_date">To</Label>
                            <Input id="end_date" type="date" value={end} onChange={(e) => setEnd(e.target.value)} />
                            {errors.end_date && <p className="text-destructive text-xs">{errors.end_date}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="day_portion">Portion</Label>
                        <select id="day_portion" className={selectClass} value={portion} onChange={(e) => setPortion(e.target.value)}>
                            {portions.map((p) => (
                                <option key={p.value} value={p.value}>
                                    {p.label}
                                </option>
                            ))}
                        </select>
                        {errors.day_portion && <p className="text-destructive text-xs">{errors.day_portion}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reason">Reason</Label>
                        <Input id="reason" value={reason} onChange={(e) => setReason(e.target.value)} />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={typeId === '' || start === '' || end === '' || reason.trim() === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.my-leave.requests.store'),
                                { leave_type_id: Number(typeId), start_date: start, end_date: end, day_portion: portion, reason },
                                {
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

function RequestMovementDialog({ types }: { types: Props['movement_types'] }) {
    const [open, setOpen] = useState(false);
    const [type, setType] = useState(types[0]?.value ?? 'field_duty');
    const [date, setDate] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [place, setPlace] = useState('');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    // ⚠ Same as the HR form: the place of a day at home is not worth asking.
    const chooseType = (value: string) => {
        setType(value);

        if (value === 'work_from_home' && place.trim() === '') {
            setPlace('Home');
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    Request movement
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Request a movement</DialogTitle>
                    <DialogDescription>
                        For field duty, official work outside the office, working on a holiday, or a day worked from home. A movement draws on no
                        leave balance.{' '}
                        {/* ⚠⚠ C-WFH-2. The one thing a person requesting a day at
                            home has to know before the day arrives. */}
                        <span className="font-medium">A day at home still needs your clock in and clock out.</span>
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="mtype">Type</Label>
                        <select id="mtype" className={selectClass} value={type} onChange={(e) => chooseType(e.target.value)}>
                            {types.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="movement_date">Date</Label>
                            <Input id="movement_date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                            {errors.movement_date && <p className="text-destructive text-xs">{errors.movement_date}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="from_time">From (optional)</Label>
                            <Input id="from_time" type="time" value={from} onChange={(e) => setFrom(e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="to_time">To (optional)</Label>
                            <Input id="to_time" type="time" value={to} onChange={(e) => setTo(e.target.value)} />
                            {errors.to_time && <p className="text-destructive text-xs">{errors.to_time}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="place">Place (optional)</Label>
                        <Input id="place" value={place} onChange={(e) => setPlace(e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="mreason">Reason</Label>
                        <Input id="mreason" value={reason} onChange={(e) => setReason(e.target.value)} />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={date === '' || reason.trim() === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.my-leave.movements.store'),
                                {
                                    type,
                                    movement_date: date,
                                    from_time: from === '' ? null : from,
                                    to_time: to === '' ? null : to,
                                    place: place === '' ? null : place,
                                    reason,
                                },
                                {
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

export default function MyLeave({ year, employee, balances, ledger, requests, movements, leave_types, portions, movement_types }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My leave" />
            <div className="px-4 pt-4">
                <SelfServiceTabs active="/hrm/my-leave" />
            </div>
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">My leave - {year}</h2>
                        <p className="text-muted-foreground text-sm">
                            {employee.name} · {employee.employee_code}
                        </p>
                    </div>
                    <div className="flex gap-3">
                        <RequestMovementDialog types={movement_types} />
                        <RequestLeaveDialog types={leave_types} portions={portions} />
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {balances
                        .filter((b) => b.deducts_balance)
                        .map((b) => (
                            <div key={b.id} className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                                <div className="text-sm font-medium">{b.name}</div>
                                <div className="mt-1 text-2xl font-medium">{b.balance}</div>
                                <div className="text-muted-foreground text-xs">
                                    days available · {b.taken} taken of {b.annual_quota}
                                </div>
                                {b.can_carry_forward && b.carry_forward_cap !== null && (
                                    <div className="text-muted-foreground mt-2 text-xs">
                                        Carries forward up to {b.carry_forward_cap} days; anything above lapses at year end.
                                    </div>
                                )}
                            </div>
                        ))}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">My requests</h3>
                    {requests.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No leave requested yet.</p>
                    ) : (
                        <table className="w-full min-w-[44rem] text-sm">
                            <tbody>
                                {requests.map((r) => (
                                    <tr key={r.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 whitespace-nowrap">
                                            {r.start_date}
                                            {r.end_date !== r.start_date && ` → ${r.end_date}`}
                                        </td>
                                        <td className="py-2">{r.leave_type}</td>
                                        <td className="text-muted-foreground py-2">
                                            {r.days} day{r.days === 1 ? '' : 's'}
                                            {r.unpaid_days > 0 && <span className="text-destructive ml-1">({r.unpaid_days} unpaid)</span>}
                                        </td>
                                        <td className="py-2">
                                            <Badge variant={r.status === 'approved' ? 'default' : 'secondary'}>{r.status}</Badge>
                                            {r.stage && <span className="text-muted-foreground ml-2 text-xs">at {r.stage}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {movements.length > 0 && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                        <h3 className="mb-3 text-sm font-medium">My movements</h3>
                        <table className="w-full min-w-[40rem] text-sm">
                            <tbody>
                                {movements.map((m) => (
                                    <tr key={m.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="text-muted-foreground py-2">{m.summary}</td>
                                        <td className="py-2">
                                            <Badge variant={m.status === 'approved' ? 'default' : 'secondary'}>{m.status}</Badge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">How my balance got here</h3>
                    {ledger.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing recorded for {year} yet.</p>
                    ) : (
                        <table className="w-full min-w-[44rem] text-sm">
                            <tbody>
                                {ledger.map((entry) => (
                                    <tr key={entry.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 whitespace-nowrap">{entry.date}</td>
                                        <td className="py-2">{entry.type}</td>
                                        <td className="text-muted-foreground py-2">{entry.entry_type}</td>
                                        <td className={`py-2 ${entry.days < 0 ? 'text-destructive' : ''}`}>
                                            {entry.days > 0 ? `+${entry.days}` : entry.days}
                                        </td>
                                        <td className="text-muted-foreground py-2 text-xs">{entry.note}</td>
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
