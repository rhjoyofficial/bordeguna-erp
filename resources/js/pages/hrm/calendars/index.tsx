import { ConfirmDialog } from '@/components/confirm-dialog';
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

interface Holiday {
    id: number;
    date: string;
    name: string;
}

interface WorkingDayOverride {
    id: number;
    date: string;
    reason: string;
}

interface Calendar {
    id: number;
    name: string;
    year: number;
    weekly_off_days: number[];
    weekly_off_label: string;
    is_default: boolean;
    is_active: boolean;
    holidays: Holiday[];
    working_day_overrides: WorkingDayOverride[];
}

interface Weekday {
    value: number;
    label: string;
}

interface IndexProps {
    calendars: Calendar[];
    weekdays: Weekday[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Calendars', href: '/hrm/calendars' }];

function CalendarDialog({ calendar, weekdays, trigger }: { calendar?: Calendar; weekdays: Weekday[]; trigger: React.ReactNode }) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState(calendar?.name ?? '');
    const [year, setYear] = useState(String(calendar?.year ?? new Date().getFullYear()));
    const [offDays, setOffDays] = useState<number[]>(calendar?.weekly_off_days ?? [5]);
    const [isDefault, setIsDefault] = useState(calendar?.is_default ?? false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const toggleDay = (value: number) => setOffDays((days) => (days.includes(value) ? days.filter((d) => d !== value) : [...days, value]));

    const submit = () => {
        const payload = { name, year: Number(year), weekly_off_days: offDays, is_default: isDefault };
        const options = {
            preserveScroll: true,
            onError: (e: Record<string, string>) => setErrors(e),
            onSuccess: () => {
                setOpen(false);
                setErrors({});
            },
        };
        if (calendar) {
            router.put(route('hrm.calendars.update', calendar.id), payload, options);
        } else {
            router.post(route('hrm.calendars.store'), payload, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{calendar ? 'Edit calendar' : 'New calendar'}</DialogTitle>
                    <DialogDescription>Named per year. Weekly off-days plus the holidays below define non-working days.</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="cal-name">Name</Label>
                            <Input id="cal-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Bangladesh, Head Office" />
                            {errors.name && <p className="text-destructive text-xs">{errors.name}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="cal-year">Year</Label>
                            <Input id="cal-year" type="number" value={year} onChange={(e) => setYear(e.target.value)} />
                            {errors.year && <p className="text-destructive text-xs">{errors.year}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label>Weekly off-days</Label>
                        <div className="flex flex-wrap gap-3">
                            {weekdays.map((day) => (
                                <label key={day.value} className="flex items-center gap-1.5 text-sm">
                                    <input type="checkbox" checked={offDays.includes(day.value)} onChange={() => toggleDay(day.value)} />
                                    {day.label.slice(0, 3)}
                                </label>
                            ))}
                        </div>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={isDefault} onChange={(e) => setIsDefault(e.target.checked)} />
                        Default calendar for this business
                    </label>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={name.trim() === ''}>
                        {calendar ? 'Save' : 'Create'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AddHoliday({ calendarId }: { calendarId: number }) {
    const [date, setDate] = useState('');
    const [name, setName] = useState('');
    const [error, setError] = useState('');

    const add = () => {
        router.post(
            route('hrm.calendars.holidays.store', calendarId),
            { date, name },
            {
                preserveScroll: true,
                onError: (e) => setError(e.date ?? e.name ?? 'Could not add holiday.'),
                onSuccess: () => {
                    setDate('');
                    setName('');
                    setError('');
                },
            },
        );
    };

    return (
        <div className="flex flex-wrap items-end gap-2">
            <div className="grid gap-1">
                <Label htmlFor={`date-${calendarId}`} className="text-xs">
                    Date
                </Label>
                <Input id={`date-${calendarId}`} type="date" value={date} onChange={(e) => setDate(e.target.value)} className="h-9" />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`name-${calendarId}`} className="text-xs">
                    Holiday name
                </Label>
                <Input
                    id={`name-${calendarId}`}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="e.g. Eid-ul-Fitr"
                    className="h-9"
                />
            </div>
            <Button size="sm" onClick={add} disabled={date === '' || name.trim() === ''}>
                Add
            </Button>
            {error && <p className="text-destructive w-full text-xs">{error}</p>}
        </div>
    );
}

function AddWorkingDay({ calendarId }: { calendarId: number }) {
    const [date, setDate] = useState('');
    const [reason, setReason] = useState('');
    const [error, setError] = useState('');

    const add = () => {
        router.post(
            route('hrm.calendars.working-days.store', calendarId),
            { date, reason },
            {
                preserveScroll: true,
                onError: (e) => setError(e.date ?? e.reason ?? 'Could not add the override.'),
                onSuccess: () => {
                    setDate('');
                    setReason('');
                    setError('');
                },
            },
        );
    };

    return (
        <div className="flex flex-wrap items-end gap-2">
            <div className="grid gap-1">
                <Label htmlFor={`wd-date-${calendarId}`} className="text-xs">
                    Date
                </Label>
                <Input id={`wd-date-${calendarId}`} type="date" value={date} onChange={(e) => setDate(e.target.value)} className="h-9" />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`wd-reason-${calendarId}`} className="text-xs">
                    Reason
                </Label>
                <Input
                    id={`wd-reason-${calendarId}`}
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    placeholder="e.g. Friday worked in place of a holiday"
                    className="h-9"
                />
            </div>
            <Button size="sm" variant="outline" onClick={add} disabled={date === '' || reason.trim() === ''}>
                Add working day
            </Button>
            {error && <p className="text-destructive w-full text-xs">{error}</p>}
        </div>
    );
}

export default function CalendarsIndex({ calendars, weekdays }: IndexProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calendars" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-lg font-medium">Holiday calendars</h2>
                    <CalendarDialog weekdays={weekdays} trigger={<Button size="sm">New calendar</Button>} />
                </div>

                {calendars.length === 0 ? (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                        <p className="text-muted-foreground text-sm">No calendars yet. Create one to define weekly off-days and holidays.</p>
                    </div>
                ) : (
                    calendars.map((calendar) => (
                        <div
                            key={calendar.id}
                            className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-xl border p-4"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h3 className="text-sm font-medium">
                                            {calendar.name} {calendar.year}
                                        </h3>
                                        {calendar.is_default && <Badge>Default</Badge>}
                                        <Badge variant={calendar.is_active ? 'default' : 'secondary'}>
                                            {calendar.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </div>
                                    <p className="text-muted-foreground mt-1 text-xs">Weekly off: {calendar.weekly_off_label || 'none'}</p>
                                </div>
                                <div className="flex flex-wrap items-center gap-1">
                                    <CalendarDialog
                                        calendar={calendar}
                                        weekdays={weekdays}
                                        trigger={
                                            <Button variant="ghost" size="sm">
                                                Edit
                                            </Button>
                                        }
                                    />
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.patch(route('hrm.calendars.toggle', calendar.id), {}, { preserveScroll: true })}
                                    >
                                        {calendar.is_active ? 'Deactivate' : 'Activate'}
                                    </Button>
                                    <ConfirmDialog
                                        trigger={
                                            <Button variant="ghost" size="sm">
                                                Delete
                                            </Button>
                                        }
                                        title="Delete calendar"
                                        description={`Delete "${calendar.name} ${calendar.year}" and its holidays? This cannot be undone.`}
                                        confirmLabel="Delete"
                                        destructive
                                        onConfirm={() => router.delete(route('hrm.calendars.destroy', calendar.id), { preserveScroll: true })}
                                    />
                                </div>
                            </div>

                            <div className="overflow-x-auto">
                                {calendar.holidays.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">No holidays yet.</p>
                                ) : (
                                    <table className="w-full min-w-[28rem] text-sm">
                                        <tbody>
                                            {calendar.holidays.map((holiday) => (
                                                <tr key={holiday.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="text-muted-foreground w-32 py-1.5">{holiday.date}</td>
                                                    <td className="py-1.5">{holiday.name}</td>
                                                    <td className="py-1.5 text-right">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.delete(route('hrm.calendars.holidays.destroy', [calendar.id, holiday.id]), {
                                                                    preserveScroll: true,
                                                                })
                                                            }
                                                        >
                                                            Remove
                                                        </Button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </div>

                            <AddHoliday calendarId={calendar.id} />

                            <div className="border-sidebar-border/70 dark:border-sidebar-border border-t pt-3">
                                <p className="text-muted-foreground mb-2 text-xs font-medium">
                                    Working-day overrides - dates that ARE working days despite a weekly off or holiday
                                </p>
                                {calendar.working_day_overrides.length > 0 && (
                                    <table className="w-full min-w-[28rem] text-sm">
                                        <tbody>
                                            {calendar.working_day_overrides.map((override) => (
                                                <tr key={override.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="text-muted-foreground w-32 py-1.5">{override.date}</td>
                                                    <td className="py-1.5">{override.reason}</td>
                                                    <td className="py-1.5 text-right">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.delete(
                                                                    route('hrm.calendars.working-days.destroy', [calendar.id, override.id]),
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                        >
                                                            Remove
                                                        </Button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                                <div className="mt-2">
                                    <AddWorkingDay calendarId={calendar.id} />
                                </div>
                            </div>
                        </div>
                    ))
                )}
            </div>
        </AppLayout>
    );
}
