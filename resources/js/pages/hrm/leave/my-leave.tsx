import { RequestStatusBadge } from '@/components/request-status-badge';
import { SearchSelect } from '@/components/search-select';
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
import { datesInText, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
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
    got: number;
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

function RequestLeaveDialog({ types, portions }: { types: Props['leave_types']; portions: Props['portions'] }) {
    const { t } = useTranslation();
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
                <Button size="sm">{t('my_leave.request_leave')}</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('my_leave.request_leave')}</DialogTitle>
                    <DialogDescription>{t('my_leave.request_leave_hint')}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="leave_type_id" required>
                            {t('my_leave.leave_type')}
                        </Label>
                        <SearchSelect id="leave_type_id" value={typeId} onChange={(e) => setTypeId(e.target.value)}>
                            <option value="">{t('self.select')}</option>
                            {types.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.leave_type_id && <p className="text-destructive text-xs">{errors.leave_type_id}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="start_date" required>
                                {t('my_leave.from')}
                            </Label>
                            <Input
                                id="start_date"
                                aria-required="true"
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
                            <Label htmlFor="end_date" required>
                                {t('my_leave.to')}
                            </Label>
                            <Input id="end_date" aria-required="true" type="date" value={end} onChange={(e) => setEnd(e.target.value)} />
                            {errors.end_date && <p className="text-destructive text-xs">{errors.end_date}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="day_portion" required>
                            {t('my_leave.portion')}
                        </Label>
                        <SearchSelect id="day_portion" value={portion} onChange={(e) => setPortion(e.target.value)}>
                            {portions.map((p) => (
                                <option key={p.value} value={p.value}>
                                    {p.label}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.day_portion && <p className="text-destructive text-xs">{errors.day_portion}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reason" required>
                            {t('self.reason')}
                        </Label>
                        <Input id="reason" aria-required="true" value={reason} onChange={(e) => setReason(e.target.value)} />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">{t('self.cancel')}</Button>
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
                        {t('self.send_request')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RequestMovementDialog({ types }: { types: Props['movement_types'] }) {
    const { t } = useTranslation();
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
                    {t('my_leave.request_movement')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('my_leave.movement_title')}</DialogTitle>
                    <DialogDescription>
                        <span className="font-medium">{t('my_leave.movement_hint')}</span>
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="mtype" required>
                            {t('my_leave.type')}
                        </Label>
                        <SearchSelect id="mtype" value={type} onChange={(e) => chooseType(e.target.value)}>
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="movement_date" required>
                                {t('my_leave.date')}
                            </Label>
                            <Input id="movement_date" aria-required="true" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                            {errors.movement_date && <p className="text-destructive text-xs">{errors.movement_date}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="from_time">{t('my_leave.from_optional')}</Label>
                            <Input id="from_time" type="time" value={from} onChange={(e) => setFrom(e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="to_time">{t('my_leave.to_optional')}</Label>
                            <Input id="to_time" type="time" value={to} onChange={(e) => setTo(e.target.value)} />
                            {errors.to_time && <p className="text-destructive text-xs">{errors.to_time}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="place">{t('my_leave.place_optional')}</Label>
                        <Input id="place" value={place} onChange={(e) => setPlace(e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="mreason" required>
                            {t('self.reason')}
                        </Label>
                        <Input id="mreason" aria-required="true" value={reason} onChange={(e) => setReason(e.target.value)} />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">{t('self.cancel')}</Button>
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
                        {t('self.send_request')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function MyLeave({ year, employee, balances, ledger, requests, movements, leave_types, portions, movement_types }: Props) {
    // HRM-007 (14 Oct 2026): the most-used self-service form, in the reader's language.
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('my_leave.title'), href: '/hrm/my-leave' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('my_leave.title')} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">{t('my_leave.heading', { year })}</h2>
                        <p className="text-muted-foreground text-sm">
                            {employee.name} · {employee.employee_code}
                        </p>
                    </div>
                    <div className="flex gap-3">
                        {/* ⭐ 27 Oct 2026: earlier leave years stay readable (Jan to Dec, reset on 1 Jan). */}
                        <Input
                            type="number"
                            className="w-24"
                            aria-label={t('my_leave.year')}
                            title={t('my_leave.year')}
                            defaultValue={year}
                            onBlur={(e) => router.get(route('hrm.my-leave'), { year: Number(e.target.value) }, { preserveScroll: true })}
                        />
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
                                <div className="text-muted-foreground text-xs">{t('my_leave.days_available', { taken: b.taken, got: b.got })}</div>
                                {b.can_carry_forward && b.carry_forward_cap !== null && (
                                    <div className="text-muted-foreground mt-2 text-xs">
                                        {t('my_leave.carries_forward', { cap: b.carry_forward_cap })}
                                    </div>
                                )}
                            </div>
                        ))}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">{t('my_leave.my_requests')}</h3>
                    {requests.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('my_leave.no_requests')}</p>
                    ) : (
                        <table className="w-full min-w-[44rem] text-sm">
                            <tbody>
                                {requests.map((r) => (
                                    <tr key={r.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 whitespace-nowrap">
                                            {showDate(r.start_date)}
                                            {r.end_date !== r.start_date && ` → ${showDate(r.end_date)}`}
                                        </td>
                                        <td className="py-2">{r.leave_type}</td>
                                        <td className="text-muted-foreground py-2">
                                            {t(r.days === 1 ? 'my_leave.day_one' : 'my_leave.day_many', { count: r.days })}
                                            {r.unpaid_days > 0 && (
                                                <span className="text-destructive ml-1">{t('my_leave.unpaid', { count: r.unpaid_days })}</span>
                                            )}
                                        </td>
                                        <td className="py-2">
                                            <RequestStatusBadge status={r.status} />
                                            {r.stage && (
                                                <span className="text-muted-foreground ml-2 text-xs">{t('self.at_stage', { stage: r.stage })}</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {movements.length > 0 && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                        <h3 className="mb-3 text-sm font-medium">{t('my_leave.my_movements')}</h3>
                        <table className="w-full min-w-[40rem] text-sm">
                            <tbody>
                                {movements.map((m) => (
                                    <tr key={m.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="text-muted-foreground py-2">{datesInText(m.summary)}</td>
                                        <td className="py-2">
                                            <RequestStatusBadge status={m.status} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">{t('my_leave.ledger_heading')}</h3>
                    {ledger.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('my_leave.ledger_empty', { year })}</p>
                    ) : (
                        <table className="w-full min-w-[44rem] text-sm">
                            <tbody>
                                {ledger.map((entry) => (
                                    <tr key={entry.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 whitespace-nowrap">{showDate(entry.date)}</td>
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
