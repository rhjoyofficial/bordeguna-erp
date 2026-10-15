import { Head, Link, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { KIND_STYLE, formatClock } from '@/lib/schedule-engine';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type ScheduleKind, type ScheduleSlot } from '@/types';

interface SlotRow extends ScheduleSlot {
    is_active: boolean;
    sort_order: number;
}

interface Draft {
    starts_at: string;
    ends_at: string;
    title: string;
    subtitle: string;
    kind: ScheduleKind;
    weekdays: number[];
    is_active: boolean;
    sort_order: number;
}

const KINDS: ScheduleKind[] = ['focus', 'break', 'meal', 'prayer', 'wrap'];
/** Saturday first: the office week runs Saturday to Thursday. */
const WEEK: number[] = [6, 0, 1, 2, 3, 4, 5];

const blank = (): Draft => ({
    starts_at: '09:00',
    ends_at: '10:00',
    title: '',
    subtitle: '',
    kind: 'focus',
    weekdays: [6, 0, 1, 2, 3, 4],
    is_active: true,
    sort_order: 0,
});

/**
 * Settings > Work schedule (schedule.manage: Owner and Manager, 14 Oct 2026):
 * the slots of the working day for the whole team, how long an alert stays,
 * and the switch that turns the alerts off for everybody. Group-wide.
 *
 * The server refuses an active slot that overlaps another on a shared day;
 * its message shows under the start time.
 */
export default function WorkSchedule({ slots, settings }: { slots: SlotRow[]; settings: { alert_seconds: number; is_enabled: boolean } }) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: '/settings' },
        { title: t('schedule.settings.title'), href: '/settings/work-schedule' },
    ];

    const [general, setGeneral] = useState(settings);
    const [savingGeneral, setSavingGeneral] = useState(false);
    const [editing, setEditing] = useState<{ id: number | null } | null>(null);
    const [draft, setDraft] = useState<Draft>(blank);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const day = (n: number, short = true) => t(`schedule.${short ? 'day_short' : 'day'}.${n}` as TranslationKey);

    const openNew = () => {
        setDraft(blank());
        setErrors({});
        setEditing({ id: null });
    };

    const openEdit = (row: SlotRow) => {
        setDraft({
            starts_at: row.starts_at,
            ends_at: row.ends_at,
            title: row.title,
            subtitle: row.subtitle ?? '',
            kind: row.kind,
            weekdays: [...row.weekdays],
            is_active: row.is_active,
            sort_order: row.sort_order,
        });
        setErrors({});
        setEditing({ id: row.id });
    };

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((current) => ({ ...current, [key]: value }));

    const toggleDay = (n: number, on: boolean) =>
        set('weekdays', on ? [...new Set([...draft.weekdays, n])] : draft.weekdays.filter((value) => value !== n));

    const save = () => {
        if (!editing) {
            return;
        }

        const payload = { ...draft, is_active: draft.is_active ? 1 : 0 };
        const options = {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
            onError: (bag: Record<string, string>) => setErrors(bag),
            onFinish: () => setSaving(false),
        };

        setSaving(true);

        if (editing.id === null) {
            router.post('/settings/work-schedule', payload, options);
        } else {
            router.put(`/settings/work-schedule/${editing.id}`, payload, options);
        }
    };

    const saveGeneral = () => {
        setSavingGeneral(true);
        router.put(
            '/settings/work-schedule/settings',
            { alert_seconds: general.alert_seconds, is_enabled: general.is_enabled ? 1 : 0 },
            { preserveScroll: true, onFinish: () => setSavingGeneral(false) },
        );
    };

    const toggle = (row: SlotRow) => router.patch(`/settings/work-schedule/${row.id}/toggle`, {}, { preserveScroll: true });

    const remove = (row: SlotRow) => {
        if (window.confirm(t('schedule.settings.delete_confirm'))) {
            router.delete(`/settings/work-schedule/${row.id}`, { preserveScroll: true });
        }
    };

    const daysSummary = (weekdays: number[]) =>
        WEEK.filter((n) => weekdays.includes(n))
            .map((n) => day(n))
            .join(', ');

    const error = (key: string) => (errors[key] ? <p className="text-xs text-red-700 dark:text-red-300">{errors[key]}</p> : null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('schedule.settings.title')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <HeadingSmall title={t('schedule.settings.title')} description={t('schedule.settings.description')} />
                        <Button size="sm" variant="outline" asChild>
                            <Link href="/schedule">{t('schedule.settings.view_rhythm')}</Link>
                        </Button>
                    </div>

                    <section className="space-y-3 rounded-lg border p-4">
                        <h3 className="text-sm font-semibold">{t('schedule.settings.general_heading')}</h3>
                        <div className="flex flex-wrap items-end gap-6">
                            <div className="grid gap-1">
                                <Label htmlFor="schedule-seconds">{t('schedule.settings.alert_seconds')}</Label>
                                <Input
                                    id="schedule-seconds"
                                    type="number"
                                    min={3}
                                    max={30}
                                    className="w-36"
                                    value={general.alert_seconds}
                                    onChange={(event) => setGeneral({ ...general, alert_seconds: Number(event.target.value) })}
                                />
                            </div>
                            <div className="flex items-start gap-2 pb-2">
                                <Checkbox
                                    id="schedule-enabled"
                                    className="mt-0.5"
                                    checked={general.is_enabled}
                                    onCheckedChange={(value) => setGeneral({ ...general, is_enabled: value === true })}
                                    aria-describedby="schedule-enabled-hint"
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor="schedule-enabled">{t('schedule.settings.enabled')}</Label>
                                    <p id="schedule-enabled-hint" className="text-muted-foreground max-w-md text-xs">
                                        {t('schedule.settings.enabled_hint')}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <Button size="sm" onClick={saveGeneral} disabled={savingGeneral}>
                            {savingGeneral ? t('schedule.settings.saving') : t('schedule.settings.save_general')}
                        </Button>
                    </section>

                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-sm font-semibold">{t('schedule.settings.slots_heading')}</h3>
                        <Button size="sm" onClick={openNew}>
                            <Plus className="size-3.5" /> {t('schedule.settings.new')}
                        </Button>
                    </div>

                    {slots.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('schedule.settings.none')}</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {slots.map((row) => {
                                const style = KIND_STYLE[row.kind] ?? KIND_STYLE.focus;
                                const Icon = style.icon;

                                return (
                                    <li
                                        key={row.id}
                                        className={cn(
                                            'flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between',
                                            !row.is_active && 'opacity-60',
                                        )}
                                    >
                                        <div className="flex min-w-0 items-start gap-3 text-sm">
                                            <span className={cn('grid size-8 shrink-0 place-items-center rounded-full', style.chip)}>
                                                <Icon className="size-4" aria-hidden />
                                            </span>
                                            <div className="min-w-0 space-y-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-medium tabular-nums">
                                                        {t('schedule.time_range', {
                                                            start: formatClock(row.starts_at),
                                                            end: formatClock(row.ends_at),
                                                        })}
                                                    </p>
                                                    <p className="font-medium">{row.title}</p>
                                                    <Badge variant="outline">{t(`schedule.kind.${row.kind}`)}</Badge>
                                                    <Badge variant={row.is_active ? 'default' : 'outline'}>
                                                        {row.is_active ? t('schedule.settings.active') : t('schedule.settings.paused')}
                                                    </Badge>
                                                </div>
                                                {row.subtitle && <p className="text-muted-foreground text-xs">{row.subtitle}</p>}
                                                <p className="text-muted-foreground text-xs">{daysSummary(row.weekdays)}</p>
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 flex-wrap items-center gap-1">
                                            <Button type="button" size="sm" variant="ghost" onClick={() => openEdit(row)}>
                                                {t('schedule.settings.edit')}
                                            </Button>
                                            <Button type="button" size="sm" variant="ghost" onClick={() => toggle(row)}>
                                                {row.is_active ? t('schedule.settings.pause') : t('schedule.settings.resume')}
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="text-red-700 dark:text-red-300"
                                                onClick={() => remove(row)}
                                            >
                                                <Trash2 className="size-3.5" /> {t('schedule.settings.delete')}
                                            </Button>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>{editing?.id === null ? t('schedule.settings.new') : t('schedule.settings.edit_title')}</DialogTitle>
                            <DialogDescription>{t('schedule.settings.form_hint')}</DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4">
                            <div className="flex flex-wrap items-start gap-4">
                                <div className="grid gap-1">
                                    <Label htmlFor="slot-start">{t('schedule.field.starts_at')}</Label>
                                    <Input
                                        id="slot-start"
                                        type="time"
                                        className="w-auto"
                                        value={draft.starts_at}
                                        onChange={(event) => set('starts_at', event.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="slot-end">{t('schedule.field.ends_at')}</Label>
                                    <Input
                                        id="slot-end"
                                        type="time"
                                        className="w-auto"
                                        value={draft.ends_at}
                                        onChange={(event) => set('ends_at', event.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="slot-kind">{t('schedule.field.kind')}</Label>
                                    <select
                                        id="slot-kind"
                                        className="border-input bg-background h-9 w-auto rounded-md border px-3 text-sm"
                                        value={draft.kind}
                                        onChange={(event) => set('kind', event.target.value as ScheduleKind)}
                                    >
                                        {KINDS.map((kind) => (
                                            <option key={kind} value={kind}>
                                                {t(`schedule.kind.${kind}`)}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                            {error('starts_at')}
                            {error('ends_at')}

                            <div className="grid gap-1">
                                <Label htmlFor="slot-title">{t('schedule.field.title')}</Label>
                                <Input
                                    id="slot-title"
                                    maxLength={80}
                                    className="max-w-sm"
                                    value={draft.title}
                                    onChange={(event) => set('title', event.target.value)}
                                />
                                {error('title')}
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="slot-subtitle">{t('schedule.field.subtitle')}</Label>
                                <Input
                                    id="slot-subtitle"
                                    maxLength={160}
                                    className="max-w-md"
                                    value={draft.subtitle}
                                    onChange={(event) => set('subtitle', event.target.value)}
                                />
                                {error('subtitle')}
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-1 text-sm font-medium">{t('schedule.field.weekdays')}</legend>
                                <div className="flex flex-wrap gap-3">
                                    {WEEK.map((n) => (
                                        <div key={n} className="flex items-center gap-1.5">
                                            <Checkbox
                                                id={`slot-day-${n}`}
                                                checked={draft.weekdays.includes(n)}
                                                onCheckedChange={(value) => toggleDay(n, value === true)}
                                            />
                                            <Label htmlFor={`slot-day-${n}`} title={day(n, false)}>
                                                {day(n)}
                                            </Label>
                                        </div>
                                    ))}
                                </div>
                                {error('weekdays')}
                            </fieldset>

                            <div className="flex flex-wrap items-end gap-4">
                                <div className="grid gap-1">
                                    <Label htmlFor="slot-order">{t('schedule.field.sort_order')}</Label>
                                    <Input
                                        id="slot-order"
                                        type="number"
                                        min={0}
                                        max={1000}
                                        className="w-36"
                                        value={draft.sort_order}
                                        onChange={(event) => set('sort_order', Number(event.target.value))}
                                    />
                                </div>
                                <div className="flex items-center gap-2 pb-2">
                                    <Checkbox
                                        id="slot-active"
                                        checked={draft.is_active}
                                        onCheckedChange={(value) => set('is_active', value === true)}
                                    />
                                    <Label htmlFor="slot-active">{t('schedule.field.active')}</Label>
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                {t('schedule.settings.cancel')}
                            </Button>
                            <Button type="button" onClick={save} disabled={saving}>
                                {saving ? t('schedule.settings.saving') : t('schedule.settings.save')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
