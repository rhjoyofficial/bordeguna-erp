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
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Shift {
    id: number;
    name: string;
    start_time: string;
    end_time: string;
    grace_minutes: number;
    break_minutes: number;
    break_policy: string | null;
    meal_window_start: string | null;
    meal_window_end: string | null;
    meal_standard_minutes: number | null;
    meal_allowance_minutes: number | null;
    meal_deduct_when_not_punched: boolean;
    meal_short_day_tolerance_minutes: number | null;
    minimum_payable_hours: string | null;
    late_threshold_minutes: number | null;
    early_leave_threshold_minutes: number | null;
    is_overnight: boolean;
    cross_midnight_window_minutes: number | null;
    is_active: boolean;
}

interface IndexProps {
    shifts: Shift[];
    break_policies: { value: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Shifts', href: '/hrm/shifts' }];

type FormState = {
    name: string;
    start_time: string;
    end_time: string;
    grace_minutes: string;
    break_minutes: string;
    break_policy: string;
    meal_window_start: string;
    meal_window_end: string;
    meal_standard_minutes: string;
    meal_allowance_minutes: string;
    meal_deduct_when_not_punched: boolean;
    meal_short_day_tolerance_minutes: string;
    minimum_payable_hours: string;
    late_threshold_minutes: string;
    early_leave_threshold_minutes: string;
    is_overnight: boolean;
    cross_midnight_window_minutes: string;
};

function blankForm(): FormState {
    return {
        name: '',
        start_time: '09:00',
        end_time: '18:00',
        grace_minutes: '15',
        break_minutes: '60',
        break_policy: 'fixed_deduction',
        meal_window_start: '12:30',
        meal_window_end: '15:00',
        meal_standard_minutes: '60',
        meal_allowance_minutes: '60',
        meal_deduct_when_not_punched: true,
        meal_short_day_tolerance_minutes: '5',
        minimum_payable_hours: '',
        late_threshold_minutes: '',
        early_leave_threshold_minutes: '',
        is_overnight: false,
        cross_midnight_window_minutes: '',
    };
}

function formFrom(shift: Shift): FormState {
    const str = (n: number | string | null) => (n === null ? '' : String(n));
    return {
        name: shift.name,
        start_time: shift.start_time,
        end_time: shift.end_time,
        grace_minutes: str(shift.grace_minutes),
        break_minutes: str(shift.break_minutes),
        break_policy: shift.break_policy ?? 'fixed_deduction',
        meal_window_start: shift.meal_window_start ?? '12:30',
        meal_window_end: shift.meal_window_end ?? '15:00',
        meal_standard_minutes: str(shift.meal_standard_minutes ?? 60),
        meal_allowance_minutes: str(shift.meal_allowance_minutes ?? ''),
        meal_deduct_when_not_punched: shift.meal_deduct_when_not_punched ?? true,
        meal_short_day_tolerance_minutes: str(shift.meal_short_day_tolerance_minutes ?? 5),
        minimum_payable_hours: str(shift.minimum_payable_hours),
        late_threshold_minutes: str(shift.late_threshold_minutes),
        early_leave_threshold_minutes: str(shift.early_leave_threshold_minutes),
        is_overnight: shift.is_overnight,
        cross_midnight_window_minutes: str(shift.cross_midnight_window_minutes),
    };
}

function ShiftDialog({
    shift,
    trigger,
    breakPolicies,
}: {
    shift?: Shift;
    trigger: React.ReactNode;
    breakPolicies: { value: string; label: string }[];
}) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(() => (shift ? formFrom(shift) : blankForm()));
    const [errors, setErrors] = useState<Record<string, string>>({});

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((f) => ({ ...f, [key]: value }));

    const numberOrNull = (v: string) => (v.trim() === '' ? null : Number(v));

    const submit = () => {
        const payload = {
            name: form.name,
            start_time: form.start_time,
            end_time: form.end_time,
            grace_minutes: Number(form.grace_minutes || 0),
            break_minutes: Number(form.break_minutes || 0),
            break_policy: form.break_policy,
            meal_window_start: form.break_policy === 'meal_window' ? form.meal_window_start : null,
            meal_window_end: form.break_policy === 'meal_window' ? form.meal_window_end : null,
            meal_standard_minutes: form.break_policy === 'meal_window' ? numberOrNull(form.meal_standard_minutes) : null,
            meal_allowance_minutes: form.break_policy === 'meal_window' ? numberOrNull(form.meal_allowance_minutes) : null,
            meal_deduct_when_not_punched: form.meal_deduct_when_not_punched,
            meal_short_day_tolerance_minutes: form.break_policy === 'meal_window' ? numberOrNull(form.meal_short_day_tolerance_minutes) : null,
            minimum_payable_hours: numberOrNull(form.minimum_payable_hours),
            late_threshold_minutes: numberOrNull(form.late_threshold_minutes),
            early_leave_threshold_minutes: numberOrNull(form.early_leave_threshold_minutes),
            is_overnight: form.is_overnight,
            cross_midnight_window_minutes: form.is_overnight ? numberOrNull(form.cross_midnight_window_minutes) : null,
        };
        const options = {
            preserveScroll: true,
            onError: (e: Record<string, string>) => setErrors(e),
            onSuccess: () => {
                setOpen(false);
                setErrors({});
                if (!shift) setForm(blankForm());
            },
        };
        if (shift) {
            router.put(route('hrm.shifts.update', shift.id), payload, options);
        } else {
            router.post(route('hrm.shifts.store'), payload, options);
        }
    };

    const NumberField = ({ field, label, required }: { field: keyof FormState; label: string; required?: boolean }) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>
                {label}
                {required ? '' : ' (optional)'}
            </Label>
            <Input id={field} type="number" min={0} value={form[field] as string} onChange={(e) => set(field, e.target.value)} />
            {errors[field] && <p className="text-destructive text-xs">{errors[field]}</p>}
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{shift ? 'Edit shift' : 'New shift'}</DialogTitle>
                    <DialogDescription>
                        Timing template used by employee schedules. The attendance engine derives late/early against it.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="e.g. General, Morning, Night" />
                        {errors.name && <p className="text-destructive text-xs">{errors.name}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="start_time">Start time</Label>
                            <Input id="start_time" type="time" value={form.start_time} onChange={(e) => set('start_time', e.target.value)} />
                            {errors.start_time && <p className="text-destructive text-xs">{errors.start_time}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="end_time">End time</Label>
                            <Input id="end_time" type="time" value={form.end_time} onChange={(e) => set('end_time', e.target.value)} />
                            {errors.end_time && <p className="text-destructive text-xs">{errors.end_time}</p>}
                        </div>
                        <NumberField field="grace_minutes" label="Late grace (min)" required />
                        <NumberField field="break_minutes" label="Break (min)" required />
                        <NumberField field="minimum_payable_hours" label="Min payable hours" />
                        <NumberField field="late_threshold_minutes" label="Late threshold (min)" />
                        <NumberField field="early_leave_threshold_minutes" label="Early-leave threshold (min)" />
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="break_policy">Break treatment</Label>
                            <SearchSelect id="break_policy" value={form.break_policy} onChange={(e) => set('break_policy', e.target.value)}>
                                {breakPolicies.map((policy) => (
                                    <option key={policy.value} value={policy.value}>
                                        {policy.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            {errors.break_policy && <p className="text-destructive text-xs">{errors.break_policy}</p>}
                        </div>
                    </div>
                    {form.break_policy === 'meal_window' && (
                        <div className="border-sidebar-border/70 dark:border-sidebar-border grid gap-4 rounded-lg border p-3">
                            <p className="text-muted-foreground text-xs">
                                Lunch is read from punches inside this window. Missing or unreadable lunch punches fall back to the standard break and
                                raise a review flag. Lunch never blocks payroll.
                            </p>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="meal_window_start">Meal window start</Label>
                                    <Input
                                        id="meal_window_start"
                                        type="time"
                                        value={form.meal_window_start}
                                        onChange={(e) => set('meal_window_start', e.target.value)}
                                    />
                                    {errors.meal_window_start && <p className="text-destructive text-xs">{errors.meal_window_start}</p>}
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="meal_window_end">Meal window end</Label>
                                    <Input
                                        id="meal_window_end"
                                        type="time"
                                        value={form.meal_window_end}
                                        onChange={(e) => set('meal_window_end', e.target.value)}
                                    />
                                    {errors.meal_window_end && <p className="text-destructive text-xs">{errors.meal_window_end}</p>}
                                </div>
                                <NumberField field="meal_standard_minutes" label="Standard meal (min)" required />
                                <NumberField field="meal_allowance_minutes" label="Allowance before flag (min)" />
                                <NumberField field="meal_short_day_tolerance_minutes" label="Short-day tolerance (min)" />
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.meal_deduct_when_not_punched}
                                    onChange={(e) => set('meal_deduct_when_not_punched', e.target.checked)}
                                />
                                Deduct the standard meal when lunch is not punched
                            </label>
                        </div>
                    )}
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_overnight} onChange={(e) => set('is_overnight', e.target.checked)} />
                        Overnight shift (crosses midnight)
                    </label>
                    {form.is_overnight && <NumberField field="cross_midnight_window_minutes" label="Cross-midnight window (min)" />}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={form.name.trim() === ''}>
                        {shift ? 'Save' : 'Create'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function ShiftsIndex({ shifts, break_policies }: IndexProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shifts" />
            <div className="px-4 pt-4">
                <ShiftScheduleTabs active="/hrm/shifts" />
            </div>
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-lg font-medium">Shifts</h2>
                    <ShiftDialog breakPolicies={break_policies} trigger={<Button size="sm">New shift</Button>} />
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    {shifts.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No shifts yet. Create one to start scheduling.</p>
                    ) : (
                        <table className="w-full min-w-[52rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">Name</th>
                                    <th className="py-2">Hours</th>
                                    <th className="py-2">Grace</th>
                                    <th className="py-2">Break</th>
                                    <th className="py-2">Min payable</th>
                                    <th className="py-2">Status</th>
                                    <th className="py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {shifts.map((shift) => (
                                    <tr key={shift.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">
                                            {shift.name}
                                            {shift.is_overnight && (
                                                <Badge variant="outline" className="ml-2">
                                                    Overnight
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {shift.start_time} – {shift.end_time}
                                        </td>
                                        <td className="text-muted-foreground py-2">{shift.grace_minutes}m</td>
                                        <td className="text-muted-foreground py-2">{shift.break_minutes}m</td>
                                        <td className="text-muted-foreground py-2">{shift.minimum_payable_hours ?? '-'}</td>
                                        <td className="py-2">
                                            <Badge variant={shift.is_active ? 'default' : 'secondary'}>
                                                {shift.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            <ShiftDialog
                                                shift={shift}
                                                breakPolicies={break_policies}
                                                trigger={
                                                    <Button variant="ghost" size="sm">
                                                        Edit
                                                    </Button>
                                                }
                                            />
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => router.patch(route('hrm.shifts.toggle', shift.id), {}, { preserveScroll: true })}
                                            >
                                                {shift.is_active ? 'Deactivate' : 'Activate'}
                                            </Button>
                                            <ConfirmDialog
                                                trigger={
                                                    <Button variant="ghost" size="sm">
                                                        Delete
                                                    </Button>
                                                }
                                                title="Delete shift"
                                                description={`Delete "${shift.name}"? This cannot be undone.`}
                                                confirmLabel="Delete"
                                                destructive
                                                onConfirm={() => router.delete(route('hrm.shifts.destroy', shift.id), { preserveScroll: true })}
                                            />
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
