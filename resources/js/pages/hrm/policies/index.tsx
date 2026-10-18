import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const selectClass = 'h-9 w-full min-w-0 rounded-md border border-input bg-background px-2 text-sm';

// Numeric fields hold strings while being edited and numbers when they arrive
// from the server; submit() normalises. Cheaper than a parallel form type.
interface Tier {
    from_occurrence: number | string;
    consequence: string;
}

interface GraceWindow {
    key: string;
    label: string;
    from_minutes: number | string;
    to_minutes: number | string | null;
    tiers: Tier[];
    requires_review: boolean;
}

interface ElAccrual {
    enabled: boolean;
    days_per_month: number;
    max_ordinary_lates: number;
    max_serious_lates: number;
}

interface PolicyConfig {
    grace_windows: GraceWindow[];
    early_leave_grace_minutes: number;
    half_day_maximum_hours: number;
    full_day_minimum_hours: number;
    half_day_arrival_after_minutes: number | null;
    count_holiday_work_without_approval: boolean;
    duplicate_scan_window_seconds: number;
    el_accrual: ElAccrual;
}

interface CurrentPolicy {
    id: number;
    name: string;
    version: number;
    effective_from: string;
    config: PolicyConfig;
}

interface Scope {
    department_id: number | null;
    department: string | null;
    current: CurrentPolicy | null;
    history: { version: number; effective_from: string; effective_to: string }[];
}

interface Option {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Attendance policy', href: '/hrm/policies' }];

const ordinal = (value: number | string) => {
    const n = Number(value);
    const suffix = n % 10 === 1 && n % 100 !== 11 ? 'st' : n % 10 === 2 && n % 100 !== 12 ? 'nd' : n % 10 === 3 && n % 100 !== 13 ? 'rd' : 'th';
    return `${n}${suffix}`;
};

const rangeText = (w: GraceWindow) =>
    w.to_minutes === null ? `more than ${w.from_minutes} min late` : `${w.from_minutes} to ${w.to_minutes} min late`;

/**
 * The ladder in words: each tier holds from its floor until the next one takes
 * over, which is exactly how the engine reads it (highest reached floor wins).
 */
const ladderText = (w: GraceWindow, consequences: Option[]) => {
    const label = (value: string) => consequences.find((c) => c.value === value)?.label ?? value;
    return w.tiers
        .map((tier, i) => {
            const from = Number(tier.from_occurrence);
            const next = w.tiers[i + 1] ? Number(w.tiers[i + 1].from_occurrence) : null;
            const span = next !== null ? (from === next - 1 ? ordinal(from) : `${ordinal(from)} to ${ordinal(next - 1)}`) : `${ordinal(from)} onward`;
            return `${span}: ${label(tier.consequence)}`;
        })
        .join(' · ');
};

/**
 * Revise one policy: everything the engine reads, editable, saved as a NEW
 * version from a chosen date. Days already judged keep the version that judged
 * them - the note in the dialog says so because that is the whole design.
 */
function ReviseDialog({ policy, consequences, isDefaultScope }: { policy: CurrentPolicy; consequences: Option[]; isDefaultScope: boolean }) {
    const [open, setOpen] = useState(false);
    const [effectiveFrom, setEffectiveFrom] = useState('');
    const [general, setGeneral] = useState({
        early_leave_grace_minutes: '0',
        half_day_maximum_hours: '4',
        full_day_minimum_hours: '8',
        half_day_arrival_after_minutes: '',
        count_holiday_work_without_approval: false,
        duplicate_scan_window_seconds: '60',
    });
    // One accrual rule for the business, so only the company-default card edits
    // it; a department revision leaves the stored rule untouched.
    const [accrual, setAccrual] = useState({
        enabled: false,
        days_per_month: '1',
        max_ordinary_lates: '3',
        max_serious_lates: '1',
    });
    const [windows, setWindows] = useState<GraceWindow[]>([]);
    const { errors } = usePage().props as { errors: Record<string, string> };

    const onOpenChange = (next: boolean) => {
        if (next) {
            const c = policy.config;
            setEffectiveFrom(dhakaToday());
            setGeneral({
                early_leave_grace_minutes: String(c.early_leave_grace_minutes),
                half_day_maximum_hours: String(c.half_day_maximum_hours),
                full_day_minimum_hours: String(c.full_day_minimum_hours),
                half_day_arrival_after_minutes: c.half_day_arrival_after_minutes === null ? '' : String(c.half_day_arrival_after_minutes),
                count_holiday_work_without_approval: c.count_holiday_work_without_approval,
                duplicate_scan_window_seconds: String(c.duplicate_scan_window_seconds),
            });
            setAccrual({
                enabled: c.el_accrual?.enabled ?? false,
                days_per_month: String(c.el_accrual?.days_per_month ?? 1),
                max_ordinary_lates: String(c.el_accrual?.max_ordinary_lates ?? 3),
                max_serious_lates: String(c.el_accrual?.max_serious_lates ?? 1),
            });
            setWindows(JSON.parse(JSON.stringify(c.grace_windows)) as GraceWindow[]);
        }
        setOpen(next);
    };

    const patchWindow = (index: number, patch: Partial<GraceWindow>) =>
        setWindows((current) => current.map((w, i) => (i === index ? { ...w, ...patch } : w)));

    const patchTier = (windowIndex: number, tierIndex: number, patch: Partial<Tier>) =>
        setWindows((current) =>
            current.map((w, i) => (i === windowIndex ? { ...w, tiers: w.tiers.map((t, j) => (j === tierIndex ? { ...t, ...patch } : t)) } : w)),
        );

    const submit = () =>
        router.post(
            route('hrm.policies.revise', policy.id),
            {
                effective_from: effectiveFrom,
                early_leave_grace_minutes: Number(general.early_leave_grace_minutes),
                half_day_maximum_hours: Number(general.half_day_maximum_hours),
                full_day_minimum_hours: Number(general.full_day_minimum_hours),
                half_day_arrival_after_minutes: general.half_day_arrival_after_minutes === '' ? null : Number(general.half_day_arrival_after_minutes),
                count_holiday_work_without_approval: general.count_holiday_work_without_approval,
                duplicate_scan_window_seconds: Number(general.duplicate_scan_window_seconds),
                ...(isDefaultScope
                    ? {
                          el_accrual: {
                              enabled: accrual.enabled,
                              days_per_month: Number(accrual.days_per_month),
                              max_ordinary_lates: Number(accrual.max_ordinary_lates),
                              max_serious_lates: Number(accrual.max_serious_lates),
                          },
                      }
                    : {}),
                grace_windows: windows.map((w) => ({
                    ...w,
                    from_minutes: Number(w.from_minutes),
                    to_minutes: w.to_minutes === null || String(w.to_minutes) === '' ? null : Number(w.to_minutes),
                    tiers: w.tiers.map((t) => ({ from_occurrence: Number(t.from_occurrence), consequence: t.consequence })),
                })),
            },
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    Revise
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Revise {policy.name}</DialogTitle>
                    <DialogDescription>
                        Saving creates version {policy.version + 1} from the date you pick. Days already judged keep version {policy.version} -
                        history never recalculates.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2 sm:max-w-xs">
                        <Label htmlFor="pol_from" required>
                            Takes effect from
                        </Label>
                        <Input aria-required id="pol_from" type="date" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} />
                        <InputError message={errors.effective_from} />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-1">
                            <Label className="text-xs" required>
                                Full day needs (hours)
                            </Label>
                            <Input
                                aria-required
                                value={general.full_day_minimum_hours}
                                onChange={(e) => setGeneral({ ...general, full_day_minimum_hours: e.target.value })}
                            />
                            <InputError message={errors.full_day_minimum_hours} />
                        </div>
                        <div className="grid gap-1">
                            <Label className="text-xs" required>
                                Half day up to (hours)
                            </Label>
                            <Input
                                aria-required
                                value={general.half_day_maximum_hours}
                                onChange={(e) => setGeneral({ ...general, half_day_maximum_hours: e.target.value })}
                            />
                            <InputError message={errors.half_day_maximum_hours} />
                        </div>
                        <div className="grid gap-1">
                            <Label className="text-xs" required>
                                Early-leave grace (minutes)
                            </Label>
                            <Input
                                aria-required
                                value={general.early_leave_grace_minutes}
                                onChange={(e) => setGeneral({ ...general, early_leave_grace_minutes: e.target.value })}
                            />
                            <InputError message={errors.early_leave_grace_minutes} />
                        </div>
                        <div className="grid gap-1">
                            <Label className="text-xs">Half day if later than (minutes, empty = off)</Label>
                            <Input
                                value={general.half_day_arrival_after_minutes}
                                onChange={(e) => setGeneral({ ...general, half_day_arrival_after_minutes: e.target.value })}
                            />
                            <InputError message={errors.half_day_arrival_after_minutes} />
                        </div>
                        <div className="grid gap-1">
                            <Label className="text-xs" required>
                                Duplicate scan window (seconds)
                            </Label>
                            <Input
                                aria-required
                                value={general.duplicate_scan_window_seconds}
                                onChange={(e) => setGeneral({ ...general, duplicate_scan_window_seconds: e.target.value })}
                            />
                            <InputError message={errors.duplicate_scan_window_seconds} />
                        </div>
                        <label className="flex items-end gap-2 pb-2 text-sm">
                            <Checkbox
                                checked={general.count_holiday_work_without_approval}
                                onCheckedChange={(v) => setGeneral({ ...general, count_holiday_work_without_approval: v === true })}
                            />
                            Count holiday work without approval
                        </label>
                    </div>

                    {isDefaultScope && (
                        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-3">
                            <label className="flex items-center gap-2 text-sm font-semibold">
                                <Checkbox checked={accrual.enabled} onCheckedChange={(v) => setAccrual({ ...accrual, enabled: v === true })} />
                                Earn one leave day per month
                            </label>
                            <div className="mt-3 grid gap-2 sm:grid-cols-3">
                                <div className="grid gap-1">
                                    <Label className="text-xs" required>
                                        Days per month
                                    </Label>
                                    <Input
                                        aria-required
                                        value={accrual.days_per_month}
                                        onChange={(e) => setAccrual({ ...accrual, days_per_month: e.target.value })}
                                    />
                                    <InputError message={errors['el_accrual.days_per_month']} />
                                </div>
                                <div className="grid gap-1">
                                    <Label className="text-xs" required>
                                        Ordinary lates allowed
                                    </Label>
                                    <Input
                                        aria-required
                                        value={accrual.max_ordinary_lates}
                                        onChange={(e) => setAccrual({ ...accrual, max_ordinary_lates: e.target.value })}
                                    />
                                    <InputError message={errors['el_accrual.max_ordinary_lates']} />
                                </div>
                                <div className="grid gap-1">
                                    <Label className="text-xs" required>
                                        Serious lates allowed
                                    </Label>
                                    <Input
                                        aria-required
                                        value={accrual.max_serious_lates}
                                        onChange={(e) => setAccrual({ ...accrual, max_serious_lates: e.target.value })}
                                    />
                                    <InputError message={errors['el_accrual.max_serious_lates']} />
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-semibold">Lateness windows</h3>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                const last = windows[windows.length - 1];
                                setWindows([
                                    ...windows,
                                    {
                                        key: '',
                                        label: '',
                                        from_minutes: last?.to_minutes ?? 0,
                                        to_minutes: null,
                                        tiers: [{ from_occurrence: 1, consequence: 'none' }],
                                        requires_review: false,
                                    },
                                ]);
                            }}
                        >
                            Add window
                        </Button>
                    </div>
                    <InputError message={errors.grace_windows} />

                    {windows.map((w, wi) => (
                        <div key={wi} className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-3">
                            <div className="grid gap-2 sm:grid-cols-[1fr_7rem_7rem_auto]">
                                <div className="grid gap-1">
                                    <Label className="text-xs" required>
                                        Window label
                                    </Label>
                                    <Input aria-required value={w.label} onChange={(e) => patchWindow(wi, { label: e.target.value })} />
                                </div>
                                <div className="grid gap-1">
                                    <Label className="text-xs" required>
                                        From (min)
                                    </Label>
                                    <Input
                                        aria-required
                                        value={String(w.from_minutes)}
                                        onChange={(e) => patchWindow(wi, { from_minutes: e.target.value })}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label className="text-xs">To (min, empty = open)</Label>
                                    <Input
                                        value={w.to_minutes === null ? '' : String(w.to_minutes)}
                                        onChange={(e) => patchWindow(wi, { to_minutes: e.target.value === '' ? null : e.target.value })}
                                    />
                                </div>
                                <div className="flex items-end">
                                    {windows.length > 1 && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setWindows(windows.filter((_, i) => i !== wi))}
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </div>
                            </div>
                            <label className="mt-2 flex items-center gap-2 text-xs">
                                <Checkbox checked={w.requires_review} onCheckedChange={(v) => patchWindow(wi, { requires_review: v === true })} />
                                Days in this window need HR review before payroll
                            </label>

                            <div className="mt-3 grid gap-2">
                                {w.tiers.map((t, ti) => (
                                    <div key={ti} className="flex flex-wrap items-center gap-2">
                                        <span className="text-muted-foreground text-xs">from the</span>
                                        <Input
                                            className="h-8 w-16"
                                            aria-label="From occurrence"
                                            aria-required
                                            value={String(t.from_occurrence)}
                                            onChange={(e) => patchTier(wi, ti, { from_occurrence: e.target.value })}
                                        />
                                        <span className="text-muted-foreground text-xs">occurrence this month:</span>
                                        <SearchSelect
                                            className={`${selectClass} w-56`}
                                            value={t.consequence}
                                            onChange={(e) => patchTier(wi, ti, { consequence: e.target.value })}
                                        >
                                            {consequences.map((c) => (
                                                <option key={c.value} value={c.value}>
                                                    {c.label}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                        {w.tiers.length > 1 && (
                                            <button
                                                type="button"
                                                className="text-muted-foreground hover:text-destructive text-xs"
                                                onClick={() => patchWindow(wi, { tiers: w.tiers.filter((_, j) => j !== ti) })}
                                            >
                                                Remove
                                            </button>
                                        )}
                                    </div>
                                ))}
                                <div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            patchWindow(wi, {
                                                tiers: [
                                                    ...w.tiers,
                                                    {
                                                        from_occurrence: (Number(w.tiers[w.tiers.length - 1]?.from_occurrence) || 0) + 1,
                                                        consequence: 'none',
                                                    },
                                                ],
                                            })
                                        }
                                    >
                                        Add tier
                                    </Button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>

                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={effectiveFrom === '' || windows.some((w) => w.label.trim() === '')}>
                        Save as version {policy.version + 1}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function PolicyIndex({ scopes, consequences }: { scopes: Scope[]; consequences: Option[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Attendance policy" />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h2 className="text-lg font-medium">Attendance policy</h2>
                </div>

                {scopes.map((scope) => (
                    <div key={scope.department_id ?? 0} className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <h3 className="text-sm font-semibold">{scope.department ?? 'Company default'}</h3>
                                {scope.current && (
                                    <Badge variant="secondary">
                                        v{scope.current.version} since {showDate(scope.current.effective_from)}
                                    </Badge>
                                )}
                            </div>
                            {scope.current && (
                                <ReviseDialog policy={scope.current} consequences={consequences} isDefaultScope={scope.department_id === null} />
                            )}
                        </div>

                        {scope.current === null ? (
                            <p className="text-muted-foreground mt-2 text-sm">No policy is currently in force for this scope.</p>
                        ) : (
                            <>
                                <p className="text-muted-foreground mt-1 text-sm">{scope.current.name}</p>
                                <p className="mt-2 text-sm">
                                    Full day needs {scope.current.config.full_day_minimum_hours}h · half day up to{' '}
                                    {scope.current.config.half_day_maximum_hours}h · early-leave grace{' '}
                                    {scope.current.config.early_leave_grace_minutes}m · duplicate scans within{' '}
                                    {scope.current.config.duplicate_scan_window_seconds}s collapse ·{' '}
                                    {scope.current.config.count_holiday_work_without_approval
                                        ? 'holiday work counts without approval'
                                        : 'holiday work needs approval'}
                                </p>
                                <table className="mt-3 w-full text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-sidebar-border/70 dark:border-sidebar-border border-b text-left">
                                            <th className="py-1.5 pr-3 font-medium">Lateness band</th>
                                            <th className="py-1.5 pr-3 font-medium">How late</th>
                                            <th className="py-1.5 font-medium">Free days, then what happens</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {scope.current.config.grace_windows.map((w) => (
                                            <tr key={w.key} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-0">
                                                <td className="py-1.5 pr-3 font-medium whitespace-nowrap">{w.label}</td>
                                                <td className="text-muted-foreground py-1.5 pr-3 whitespace-nowrap">{rangeText(w)}</td>
                                                <td className="text-muted-foreground py-1.5">
                                                    {ladderText(w, consequences)}
                                                    {w.requires_review && (
                                                        <Badge variant="secondary" className="ml-2">
                                                            HR review
                                                        </Badge>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {scope.department_id === null && (
                                    <p className="text-muted-foreground mt-3 text-sm">
                                        {scope.current.config.el_accrual?.enabled ? (
                                            <>
                                                Earned leave: {scope.current.config.el_accrual.days_per_month} day per month to permanent employees,
                                                withheld above {scope.current.config.el_accrual.max_ordinary_lates} ordinary or{' '}
                                                {scope.current.config.el_accrual.max_serious_lates} serious lates in the month.
                                            </>
                                        ) : (
                                            'Earned leave accrual is off.'
                                        )}
                                    </p>
                                )}
                            </>
                        )}

                        {scope.history.length > 0 && (
                            <p className="text-muted-foreground mt-3 text-xs">
                                History:{' '}
                                {scope.history.map((h) => `v${h.version} (${showDate(h.effective_from)} to ${showDate(h.effective_to)})`).join(' · ')}
                            </p>
                        )}
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
