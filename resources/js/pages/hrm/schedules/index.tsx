import { ConfirmDialog } from '@/components/confirm-dialog';
import { SearchSelect } from '@/components/search-select';
import ShiftScheduleTabs from '@/components/shift-schedule-tabs';
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
import { dhakaToday, showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Assignment {
    id: number;
    scope: string;
    target: string | null;
    schedule_type: string;
    shift: string | null;
    calendar: string | null;
    effective_from: string;
    effective_to: string | null;
    is_temporary: boolean;
    is_auto_restored: boolean;
    is_active: boolean;
    is_future: boolean;
    note: string | null;
    created_by: string | null;
}

interface RosterRow {
    id: number;
    employee_code: string;
    name: string;
    placement: string | null;
    schedule_type: string | null;
    shift: string | null;
    shift_times: string | null;
    calendar: string | null;
    source: string | null;
}

interface Named {
    id: number;
    name: string;
}

interface OfficeNode extends Named {
    departments: (Named & { sections: Named[] })[];
}

interface Options {
    scopes: { value: string; label: string; needs_target: boolean }[];
    schedule_types: { value: string; label: string; requires_shift: boolean }[];
    shifts: Named[];
    calendars: Named[];
    offices: OfficeNode[];
    employees: Named[];
}

interface IndexProps {
    on: string;
    assignments: Assignment[];
    roster: RosterRow[];
    options: Options;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Schedules', href: '/hrm/schedules' }];

type FormState = {
    scope_type: string;
    scope_id: string;
    schedule_type: string;
    shift_id: string;
    holiday_calendar_id: string;
    effective_from: string;
    effective_to: string;
    note: string;
};

function blankForm(on: string): FormState {
    return {
        scope_type: 'business',
        scope_id: '',
        schedule_type: 'fixed',
        shift_id: '',
        holiday_calendar_id: '',
        effective_from: on,
        effective_to: '',
        note: '',
    };
}

/**
 * The targets available for the chosen scope. Departments and sections are
 * flattened with their parent in the label so a name reused across offices
 * ("Production") is still unambiguous.
 */
function targetsFor(scope: string, options: Options): Named[] {
    if (scope === 'office') return options.offices;
    if (scope === 'department') {
        return options.offices.flatMap((office) => office.departments.map((d) => ({ id: d.id, name: `${office.name} › ${d.name}` })));
    }
    if (scope === 'section') {
        return options.offices.flatMap((office) =>
            office.departments.flatMap((d) => d.sections.map((s) => ({ id: s.id, name: `${office.name} › ${d.name} › ${s.name}` }))),
        );
    }
    if (scope === 'employee') return options.employees;
    return [];
}

function AssignDialog({ on, options }: { on: string; options: Options }) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(() => blankForm(on));
    const [errors, setErrors] = useState<Record<string, string>>({});

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((f) => ({ ...f, [key]: value }));

    const scope = options.scopes.find((s) => s.value === form.scope_type);
    const type = options.schedule_types.find((t) => t.value === form.schedule_type);
    const targets = targetsFor(form.scope_type, options);

    const submit = () => {
        router.post(
            route('hrm.schedules.store'),
            {
                scope_type: form.scope_type,
                scope_id: scope?.needs_target ? Number(form.scope_id) : null,
                schedule_type: form.schedule_type,
                shift_id: form.shift_id === '' ? null : Number(form.shift_id),
                holiday_calendar_id: form.holiday_calendar_id === '' ? null : Number(form.holiday_calendar_id),
                effective_from: form.effective_from,
                effective_to: form.effective_to === '' ? null : form.effective_to,
                note: form.note.trim() === '' ? null : form.note,
            },
            {
                preserveScroll: true,
                onError: (e: Record<string, string>) => setErrors(e),
                onSuccess: () => {
                    setOpen(false);
                    setErrors({});
                    setForm(blankForm(on));
                },
            },
        );
    };

    const incomplete =
        (scope?.needs_target && form.scope_id === '') || form.holiday_calendar_id === '' || (type?.requires_shift && form.shift_id === '');

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Assign schedule</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Assign a schedule</DialogTitle>
                </DialogHeader>
                <div className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="scope_type" required>
                                Applies to
                            </Label>
                            <SearchSelect
                                id="scope_type"
                                value={form.scope_type}
                                onChange={(e) => setForm((f) => ({ ...f, scope_type: e.target.value, scope_id: '' }))}
                            >
                                {options.scopes.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            {errors.scope_type && <p className="text-destructive text-xs">{errors.scope_type}</p>}
                        </div>
                        {scope?.needs_target && (
                            <div className="grid gap-2">
                                <Label htmlFor="scope_id" required>
                                    {scope.label}
                                </Label>
                                <SearchSelect id="scope_id" value={form.scope_id} onChange={(e) => set('scope_id', e.target.value)}>
                                    <option value="">- Select -</option>
                                    {targets.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                                {errors.scope_id && <p className="text-destructive text-xs">{errors.scope_id}</p>}
                            </div>
                        )}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="schedule_type" required>
                                Schedule type
                            </Label>
                            <SearchSelect id="schedule_type" value={form.schedule_type} onChange={(e) => set('schedule_type', e.target.value)}>
                                {options.schedule_types.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            {errors.schedule_type && <p className="text-destructive text-xs">{errors.schedule_type}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="shift_id" required={type?.requires_shift}>
                                Shift{type?.requires_shift ? '' : ' (optional)'}
                            </Label>
                            <SearchSelect id="shift_id" value={form.shift_id} onChange={(e) => set('shift_id', e.target.value)}>
                                <option value="">- None -</option>
                                {options.shifts.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            {errors.shift_id && <p className="text-destructive text-xs">{errors.shift_id}</p>}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="holiday_calendar_id" required>
                            Holiday calendar
                        </Label>
                        <SearchSelect
                            id="holiday_calendar_id"
                            value={form.holiday_calendar_id}
                            onChange={(e) => set('holiday_calendar_id', e.target.value)}
                        >
                            <option value="">- Select -</option>
                            {options.calendars.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.holiday_calendar_id && <p className="text-destructive text-xs">{errors.holiday_calendar_id}</p>}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="effective_from" required>
                                Effective from
                            </Label>
                            <Input
                                id="effective_from"
                                aria-required
                                type="date"
                                value={form.effective_from}
                                onChange={(e) => set('effective_from', e.target.value)}
                            />
                            {errors.effective_from && <p className="text-destructive text-xs">{errors.effective_from}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="effective_to">Until (optional - temporary)</Label>
                            <Input id="effective_to" type="date" value={form.effective_to} onChange={(e) => set('effective_to', e.target.value)} />
                            {errors.effective_to && <p className="text-destructive text-xs">{errors.effective_to}</p>}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="note">Note (optional)</Label>
                        <Input id="note" value={form.note} onChange={(e) => set('note', e.target.value)} placeholder="e.g. Ramadan hours 2026" />
                        {errors.note && <p className="text-destructive text-xs">{errors.note}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={incomplete}>
                        Assign
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function EndDialog({ assignment }: { assignment: Assignment }) {
    const [open, setOpen] = useState(false);
    const [date, setDate] = useState(() => dhakaToday());
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    End
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>End this schedule</DialogTitle>
                    <DialogDescription>
                        The assignment stays as history - it just stops applying after this date. Anyone it covered falls back to the next broadest
                        assignment.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="end_on" required>
                        Last day it applies
                    </Label>
                    <Input id="end_on" aria-required type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                    {errors.effective_to && <p className="text-destructive text-xs">{errors.effective_to}</p>}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        onClick={() =>
                            router.patch(
                                route('hrm.schedules.end', assignment.id),
                                { effective_to: date },
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
                        End schedule
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function SchedulesIndex({ on, assignments, roster, options }: IndexProps) {
    const [date, setDate] = useState(on);

    const viewOn = (value: string) => {
        setDate(value);
        router.get(route('hrm.schedules.index'), { on: value }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Schedules" />
            <div className="px-4 pt-4">
                <ShiftScheduleTabs active="/hrm/schedules" />
            </div>
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-medium">Schedules</h2>
                    <div className="flex items-center gap-3">
                        <Label htmlFor="on" className="text-muted-foreground text-sm">
                            Roster on
                        </Label>
                        <Input id="on" type="date" value={date} onChange={(e) => viewOn(e.target.value)} />
                        <AssignDialog on={on} options={options} />
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Roster</h3>
                    {roster.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No employees are placed in this business on {showDate(on)}.</p>
                    ) : (
                        <table className="w-full min-w-[60rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Placement</th>
                                    <th className="py-2">Schedule</th>
                                    <th className="py-2">Shift</th>
                                    <th className="py-2">Calendar</th>
                                    <th className="py-2">From</th>
                                </tr>
                            </thead>
                            <tbody>
                                {roster.map((row) => (
                                    <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">
                                            {row.name}
                                            <span className="text-muted-foreground ml-2 text-xs">{row.employee_code}</span>
                                        </td>
                                        <td className="text-muted-foreground py-2">{row.placement ?? '-'}</td>
                                        <td className="py-2">{row.schedule_type ?? <Badge variant="secondary">Unscheduled</Badge>}</td>
                                        <td className="text-muted-foreground py-2">
                                            {row.shift ? (
                                                <>
                                                    {row.shift}
                                                    <span className="ml-2 text-xs">{row.shift_times}</span>
                                                </>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2">{row.calendar ?? '-'}</td>
                                        <td className="text-muted-foreground py-2 text-xs">{row.source ?? '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Assignments</h3>
                    {assignments.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing assigned yet.</p>
                    ) : (
                        <table className="w-full min-w-[64rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">Applies to</th>
                                    <th className="py-2">Type</th>
                                    <th className="py-2">Shift</th>
                                    <th className="py-2">Calendar</th>
                                    <th className="py-2">Period</th>
                                    <th className="py-2">Note</th>
                                    <th className="py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {assignments.map((a) => (
                                    <tr key={a.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">
                                            {a.target ?? a.scope}
                                            {a.target && <span className="text-muted-foreground ml-2 text-xs">{a.scope}</span>}
                                        </td>
                                        <td className="text-muted-foreground py-2">{a.schedule_type}</td>
                                        <td className="text-muted-foreground py-2">{a.shift ?? '-'}</td>
                                        <td className="text-muted-foreground py-2">{a.calendar ?? '-'}</td>
                                        <td className="text-muted-foreground py-2">
                                            {showDate(a.effective_from)} → {showDate(a.effective_to) || 'Current'}
                                            {a.is_active && (
                                                <Badge variant="default" className="ml-2">
                                                    In effect
                                                </Badge>
                                            )}
                                            {a.is_future && (
                                                <Badge variant="outline" className="ml-2">
                                                    Scheduled
                                                </Badge>
                                            )}
                                            {a.is_temporary && (
                                                <Badge variant="outline" className="ml-2">
                                                    Temporary
                                                </Badge>
                                            )}
                                            {a.is_auto_restored && (
                                                <Badge variant="secondary" className="ml-2">
                                                    Auto-restored
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2 text-xs">{a.note ?? '-'}</td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            {a.effective_to === null && <EndDialog assignment={a} />}
                                            {a.is_future && (
                                                <ConfirmDialog
                                                    trigger={
                                                        <Button variant="ghost" size="sm">
                                                            Remove
                                                        </Button>
                                                    }
                                                    title="Remove scheduled assignment"
                                                    description={`Remove the schedule starting ${showDate(a.effective_from)} for ${a.target ?? a.scope}? It has not taken effect yet.`}
                                                    confirmLabel="Remove"
                                                    destructive
                                                    onConfirm={() => router.delete(route('hrm.schedules.destroy', a.id), { preserveScroll: true })}
                                                />
                                            )}
                                        </td>
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
