import { SearchSelect } from '@/components/search-select';
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
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
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

interface CorrectionType {
    value: string;
    label: string;
    needs_punch: boolean;
    needs_time: boolean;
}

interface MySummary {
    days_recorded: number;
    attended: number;
    present: number;
    late: number;
    absent: number;
    missing_punch: number;
    on_leave: number;
    weekly_off: number;
    late_minutes: number;
    worked_hours: number;
    holds_payroll: number;
}

interface ClockProps {
    employee: { name: string; employee_code: string; office: string | null; may_clock_in_remotely: boolean };
    today: string;
    punches: Punch[];
    filters: { from: string; to: string; status: string; sort: string };
    summary: MySummary;
    statuses: { value: string; label: string }[];
    recent_days: RecentDay[];
    correction_types: CorrectionType[];
    directions: { value: string; label: string }[];
}

/**
 * An employee raising a correction on one of their own days (COR-01). It is a
 * request, not a change - it routes to their manager and then HR.
 */
function RequestCorrectionDialog({
    day,
    types,
    directions,
}: {
    day: RecentDay;
    types: CorrectionType[];
    directions: { value: string; label: string }[];
}) {
    const [open, setOpen] = useState(false);
    const [type, setType] = useState(types[0]?.value ?? 'add_punch');
    const [correctedAt, setCorrectedAt] = useState(`${day.date}T18:00`);
    const [direction, setDirection] = useState('');
    const [reason, setReason] = useState('');
    const [evidence, setEvidence] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const { t } = useTranslation();

    const selected = types.find((option) => option.value === type);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    {t('correction.request')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('correction.title_for', { date: day.date })}</DialogTitle>
                    <DialogDescription>{t('correction.description')}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="ctype">{t('correction.what_is_wrong')}</Label>
                        <SearchSelect id="ctype" value={type} onChange={(e) => setType(e.target.value)}>
                            {types.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    {selected?.needs_time && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="ctime">{t('correction.correct_time')}</Label>
                                <Input id="ctime" type="datetime-local" value={correctedAt} onChange={(e) => setCorrectedAt(e.target.value)} />
                                {errors.corrected_at && <p className="text-destructive text-xs">{errors.corrected_at}</p>}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="cdir">{t('correction.direction')}</Label>
                                <SearchSelect id="cdir" value={direction} onChange={(e) => setDirection(e.target.value)}>
                                    <option value="">{t('correction.unknown')}</option>
                                    {directions.map((d) => (
                                        <option key={d.value} value={d.value}>
                                            {d.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                        </div>
                    )}
                    {selected?.needs_punch && <p className="text-muted-foreground text-xs">{t('correction.needs_hr')}</p>}
                    <div className="grid gap-2">
                        <Label htmlFor="creason">{t('correction.reason')}</Label>
                        <Input id="creason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('correction.what_happened')} />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="cevidence">{t('correction.evidence')}</Label>
                        <Input
                            id="cevidence"
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp,.pdf"
                            onChange={(e) => setEvidence(e.target.files?.[0] ?? null)}
                        />
                    </div>
                    {errors.attendance_date && <p className="text-destructive text-xs">{errors.attendance_date}</p>}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">{t('correction.cancel')}</Button>
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
                        {t('correction.send')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clock in/out', href: '/hrm/clock' }];

/** One figure from the employee's own totals for the chosen period. */
function MyTile({ label, value, warn, bad }: { label: string; value: number; warn?: boolean; bad?: boolean }) {
    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-3">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p
                className={
                    bad
                        ? 'text-2xl font-semibold text-red-600 dark:text-red-400'
                        : warn
                          ? 'text-2xl font-semibold text-amber-600 dark:text-amber-400'
                          : 'text-2xl font-semibold'
                }
            >
                {value}
            </p>
        </div>
    );
}

export default function Clock({ employee, today, punches, filters, summary, statuses, recent_days, correction_types, directions }: ClockProps) {
    const [busy, setBusy] = useState<string | null>(null);
    const [locationError, setLocationError] = useState<string | null>(null);
    const [myFrom, setMyFrom] = useState(filters.from);
    const [myTo, setMyTo] = useState(filters.to);
    const [myStatus, setMyStatus] = useState(filters.status);
    const [mySort, setMySort] = useState(filters.sort);
    const { t } = useTranslation();

    const statusLabel = statuses.find((s) => s.value === filters.status)?.label ?? null;

    /**
     * One place that reloads the history, so the sortable Date header and the
     * Show button cannot drift into sending different sets of parameters. The
     * override is for the header, which changes sort and reloads in one click
     * rather than waiting for Show to be pressed.
     */
    const showHistory = (overrides: { from?: string; to?: string; status?: string; sort?: string } = {}) =>
        router.get('/hrm/clock', { from: myFrom, to: myTo, status: myStatus, sort: mySort, ...overrides }, { preserveScroll: true });

    /**
     * Location is captured in the browser and sent with the punch - the server
     * requires it, so a denied permission is explained here rather than turning
     * into a validation error the employee cannot act on.
     */
    const punch = (direction: 'in' | 'out') => {
        setLocationError(null);

        if (!navigator.geolocation) {
            setLocationError(t('clock.no_geolocation'));
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
                setLocationError(error.code === error.PERMISSION_DENIED ? t('clock.location_denied') : t('clock.location_failed'));
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('clock.title')} />
            <div className="px-4 pt-4">
                <SelfServiceTabs active="/hrm/clock" />
            </div>
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h2 className="text-lg font-medium">{employee.name}</h2>
                    <p className="text-muted-foreground text-sm">
                        {employee.employee_code}
                        {employee.office && ` · ${employee.office}`} · {today}
                    </p>
                </div>

                {/* ⚠ Hidden rather than disabled when it is not allowed: a greyed
                    button invites people to ask why it is greyed. The endpoint
                    refuses regardless, so this is presentation, not the rule. */}
                {employee.may_clock_in_remotely ? (
                    <>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap gap-3 rounded-xl border p-4">
                            <Button onClick={() => punch('in')} disabled={busy !== null}>
                                {busy === 'in' ? t('clock.recording') : t('clock.clock_in')}
                            </Button>
                            <Button variant="outline" onClick={() => punch('out')} disabled={busy !== null}>
                                {busy === 'out' ? t('clock.recording') : t('clock.clock_out')}
                            </Button>
                        </div>

                        {locationError && <p className="text-destructive text-sm">{locationError}</p>}

                        <p className="text-muted-foreground text-xs">{t('clock.privacy_note')}</p>
                    </>
                ) : (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                        <p className="text-sm font-medium">{t('clock.not_switched_on')}</p>
                        <p className="text-muted-foreground mt-1 text-sm">{t('clock.use_machine')}</p>
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">{t('clock.today')}</h3>
                    {punches.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('clock.nothing_today')}</p>
                    ) : (
                        <table className="w-full min-w-[32rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">{t('clock.time')}</th>
                                    <th className="py-2">{t('clock.direction')}</th>
                                    <th className="py-2">{t('clock.source')}</th>
                                    <th className="py-2">{t('common.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {punches.map((p) => (
                                    <tr key={p.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 font-mono text-xs">{p.time}</td>
                                        <td className="py-2">{p.direction ?? '-'}</td>
                                        <td className="text-muted-foreground py-2">{p.source}</td>
                                        <td className="py-2">
                                            {p.needs_review ? (
                                                <span className="text-muted-foreground text-xs">
                                                    <Badge variant="outline">{t('clock.flagged')}</Badge>
                                                    {p.review_reason && <span className="ml-2">{p.review_reason}</span>}
                                                </span>
                                            ) : (
                                                <Badge variant="secondary">{t('clock.recorded')}</Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {/*
                  ⚠ This range drives the history and the totals only. Clocking
                  in and out above is always about today, because a screen where
                  picking last March moved the punch button would be a way to
                  record attendance on the wrong day.
                */}
                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">{t('clock.my_attendance')}</h3>

                    <div className="mb-4 flex flex-wrap items-end gap-3">
                        <div className="grid gap-1.5">
                            <label htmlFor="my-from" className="text-muted-foreground text-xs">
                                {t('clock.from')}
                            </label>
                            <input
                                id="my-from"
                                type="date"
                                defaultValue={filters.from}
                                onChange={(e) => setMyFrom(e.target.value)}
                                className="border-input bg-background h-10 w-auto rounded-md border px-3 text-sm"
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <label htmlFor="my-to" className="text-muted-foreground text-xs">
                                {t('clock.to')}
                            </label>
                            <input
                                id="my-to"
                                type="date"
                                defaultValue={filters.to}
                                onChange={(e) => setMyTo(e.target.value)}
                                className="border-input bg-background h-10 w-auto rounded-md border px-3 text-sm"
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <label htmlFor="my-status" className="text-muted-foreground text-xs">
                                {t('common.status')}
                            </label>
                            <SearchSelect id="my-status" value={myStatus} onChange={(e) => setMyStatus(e.target.value)}>
                                <option value="">{t('clock.all_statuses')}</option>
                                {statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-1.5">
                            <label htmlFor="my-sort" className="text-muted-foreground text-xs">
                                {t('clock.date_order')}
                            </label>
                            <SearchSelect id="my-sort" value={mySort} onChange={(e) => setMySort(e.target.value)}>
                                <option value="newest">{t('clock.newest_first')}</option>
                                <option value="oldest">{t('clock.oldest_first')}</option>
                            </SearchSelect>
                        </div>
                        <Button onClick={() => showHistory()}>{t('clock.show')}</Button>
                    </div>

                    {/*
                      ⚠ These tiles are the totals for the WHOLE period and never
                      follow the status filter. "Late 3 of 14 days" only means
                      anything while the 14 is the whole period; if the tiles
                      narrowed too, picking Late would read as a perfect month.
                      The list below is the thing that narrows, and it says so.
                    */}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <MyTile label={t('clock.days_attended')} value={summary.attended} />
                        <MyTile label={t('clock.present')} value={summary.present} />
                        <MyTile label={t('clock.late')} value={summary.late} warn={summary.late > 0} />
                        <MyTile label={t('clock.absent')} value={summary.absent} bad={summary.absent > 0} />
                        <MyTile label={t('clock.on_leave')} value={summary.on_leave} />
                        <MyTile label={t('clock.hours_worked')} value={summary.worked_hours} />
                    </div>

                    {summary.holds_payroll > 0 && (
                        <p className="mt-3 text-sm text-amber-700 dark:text-amber-300">{t('clock.holds_payroll', { count: summary.holds_payroll })}</p>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <div className="mb-3 flex flex-wrap items-baseline gap-2">
                        <h3 className="text-sm font-medium">
                            {statusLabel ? `${statusLabel} ${t('clock.days').toLowerCase()}` : t('clock.days')}{' '}
                            {t('clock.days_range', { from: filters.from, to: filters.to })}
                        </h3>
                        {statusLabel && (
                            <span className="text-muted-foreground text-xs">
                                {t('clock.showing_only', { status: statusLabel })}
                                <button
                                    type="button"
                                    className="ml-2 underline underline-offset-2"
                                    onClick={() => {
                                        setMyStatus('');
                                        showHistory({ status: '' });
                                    }}
                                >
                                    {t('clock.show_all_statuses')}
                                </button>
                            </span>
                        )}
                    </div>
                    {recent_days.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {statusLabel
                                ? t('clock.no_days_between', { status: statusLabel.toLowerCase(), from: filters.from, to: filters.to })
                                : t('clock.nothing_processed')}
                        </p>
                    ) : (
                        <table className="w-full min-w-[40rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">
                                        {/* Clicking the column is the fastest way to flip the order, and the arrow says which way it is now. */}
                                        <button
                                            type="button"
                                            className="hover:text-foreground inline-flex items-center gap-1 font-medium"
                                            title={t('clock.sort_by_date')}
                                            onClick={() => {
                                                const next = mySort === 'newest' ? 'oldest' : 'newest';
                                                setMySort(next);
                                                showHistory({ sort: next });
                                            }}
                                        >
                                            {t('common.date')}
                                            <span aria-hidden="true">{filters.sort === 'oldest' ? '↑' : '↓'}</span>
                                            <span className="sr-only">{filters.sort === 'oldest' ? t('clock.oldest_first') : t('clock.newest_first')}</span>
                                        </button>
                                    </th>
                                    <th className="py-2">{t('common.status')}</th>
                                    <th className="py-2">{t('clock.in_out')}</th>
                                    <th className="py-2 text-right">{t('clock.something_wrong')}</th>
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
                                                    {t('clock.needs_fixing')}
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2 font-mono text-xs">
                                            {day.first_in ?? '-'} / {day.last_out ?? '-'}
                                        </td>
                                        <td className="py-2 text-right">
                                            {day.is_locked ? (
                                                <span className="text-muted-foreground text-xs">{t('clock.period_closed')}</span>
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
            </div>
        </AppLayout>
    );
}
