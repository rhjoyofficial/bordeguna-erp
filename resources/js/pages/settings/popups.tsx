import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type PopupLine } from '@/types';

interface PopupRow {
    id: number;
    key: string | null;
    title: string;
    lines: PopupLine[];
    style: 'calm' | 'bismillah' | 'notice' | 'warning';
    on_login: boolean;
    idle_minutes: number | null;
    audience_roles: string[];
    frequency: 'every' | 'daily' | 'once';
    starts_on: string | null;
    ends_on: string | null;
    sound_mode: 'default' | 'none' | 'custom';
    sound_id: number | null;
    close_after_seconds: number | null;
    priority: number;
    is_active: boolean;
    media_type: 'none' | 'video' | 'youtube';
    media_url: string | null;
    media_size: number | null;
    youtube_id: string | null;
    views: number;
}

interface LibrarySound {
    id: number;
    name: string;
    url: string;
}

type Draft = Omit<PopupRow, 'id' | 'key' | 'media_url' | 'media_size' | 'youtube_id' | 'views'> & { youtube: string };

const STYLES = ['bismillah', 'calm', 'notice', 'warning'] as const;
const KINDS = ['arabic', 'heading', 'body', 'note'] as const;
const FREQUENCIES = ['every', 'daily', 'once'] as const;
const SOUND_MODES = ['default', 'none', 'custom'] as const;
const MEDIA = ['none', 'youtube', 'video'] as const;

const blank = (): Draft => ({
    title: '',
    lines: [{ kind: 'heading', text: '' }],
    style: 'notice',
    on_login: true,
    idle_minutes: null,
    audience_roles: [],
    frequency: 'every',
    starts_on: null,
    ends_on: null,
    sound_mode: 'default',
    sound_id: null,
    close_after_seconds: 6,
    priority: 50,
    is_active: true,
    media_type: 'none',
    youtube: '',
});

const fromRow = (row: PopupRow): Draft => ({
    title: row.title,
    lines: row.lines.map((line) => ({ ...line })),
    style: row.style,
    on_login: row.on_login,
    idle_minutes: row.idle_minutes,
    audience_roles: [...row.audience_roles],
    frequency: row.frequency,
    starts_on: row.starts_on,
    ends_on: row.ends_on,
    sound_mode: row.sound_mode,
    sound_id: row.sound_id,
    close_after_seconds: row.close_after_seconds,
    priority: row.priority,
    is_active: row.is_active,
    media_type: row.media_type,
    youtube: row.youtube_id ?? '',
});

/**
 * Settings > Popups (Owner, system.popups.manage, 14 Oct 2026): the popup
 * engine's rows, with the built-in Bismillah among them (editable, pausable,
 * never deleted). Group-wide.
 */
export default function Popups({
    popups,
    sounds,
    roles,
}: {
    popups: PopupRow[];
    sounds: LibrarySound[];
    roles: string[];
    popupSound: string | null;
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('popups.title'), href: '/settings/popups' }];

    const [editing, setEditing] = useState<{ id: number | null; builtIn: boolean; hasVideo: boolean } | null>(null);
    const [draft, setDraft] = useState<Draft>(blank);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const videoInput = useRef<HTMLInputElement>(null);

    const openNew = () => {
        setDraft(blank());
        setErrors({});
        setEditing({ id: null, builtIn: false, hasVideo: false });
    };

    const openEdit = (row: PopupRow) => {
        setDraft(fromRow(row));
        setErrors({});
        setEditing({ id: row.id, builtIn: row.key !== null, hasVideo: row.media_type === 'video' && row.media_url !== null });
    };

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((current) => ({ ...current, [key]: value }));

    const save = () => {
        if (!editing) {
            return;
        }

        const video = videoInput.current?.files?.[0] ?? null;
        const payload: Record<string, FormDataConvertible> = {
            ...draft,
            lines: draft.lines.map((line) => ({ kind: line.kind, text: line.text })),
            on_login: draft.on_login ? 1 : 0,
            is_active: draft.is_active ? 1 : 0,
            idle_minutes: draft.idle_minutes ?? '',
            close_after_seconds: draft.close_after_seconds ?? '',
            starts_on: draft.starts_on ?? '',
            ends_on: draft.ends_on ?? '',
            sound_id: draft.sound_id ?? '',
        };

        if (video) {
            payload.video = video;
        }

        setSaving(true);
        router.post(editing.id === null ? '/settings/popups' : `/settings/popups/${editing.id}`, payload, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setEditing(null),
            onError: (bag) => setErrors(bag as Record<string, string>),
            onFinish: () => setSaving(false),
        });
    };

    const toggle = (row: PopupRow) => router.patch(`/settings/popups/${row.id}/toggle`, {}, { preserveScroll: true });

    const remove = (row: PopupRow) => {
        if (window.confirm(t('popups.delete_confirm'))) {
            router.delete(`/settings/popups/${row.id}`, { preserveScroll: true });
        }
    };

    const summary = (row: PopupRow): string => {
        const when = [
            row.on_login ? t('popups.summary.login') : null,
            row.idle_minutes ? t('popups.summary.idle', { minutes: row.idle_minutes }) : null,
        ].filter(Boolean);
        const who = row.audience_roles.length === 0 ? t('popups.summary.everyone') : row.audience_roles.join(', ');
        const window = [
            row.starts_on ? t('popups.summary.from', { date: row.starts_on }) : null,
            row.ends_on ? t('popups.summary.until', { date: row.ends_on }) : null,
        ].filter(Boolean);

        return [when.join(' + '), who, t(`popups.frequency.${row.frequency}`), ...window].join(' · ');
    };

    const error = (key: string) => (errors[key] ? <p className="text-xs text-red-700 dark:text-red-300">{errors[key]}</p> : null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('popups.title')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-3">
                        <HeadingSmall title={t('popups.title')} description={t('popups.description')} />
                        <Button size="sm" onClick={openNew}>
                            <Plus className="size-3.5" /> {t('popups.new')}
                        </Button>
                    </div>

                    {popups.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('popups.none')}</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {popups.map((row) => (
                                <li key={row.id} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0 space-y-1 text-sm">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-medium">{row.title}</p>
                                            {row.key !== null && (
                                                <Badge variant="secondary" title={t('popups.built_in_hint')}>
                                                    {t('popups.built_in')}
                                                </Badge>
                                            )}
                                            <Badge variant={row.is_active ? 'default' : 'outline'}>
                                                {row.is_active ? t('popups.active') : t('popups.paused')}
                                            </Badge>
                                            {row.media_type !== 'none' && (
                                                <Badge variant="outline">{t(`popups.media.${row.media_type}`).split(' (')[0]}</Badge>
                                            )}
                                        </div>
                                        <p className="text-muted-foreground text-xs">{summary(row)}</p>
                                        <p className="text-muted-foreground truncate text-xs" lang="bn">
                                            {row.lines.map((line) => line.text).join(' / ')}
                                        </p>
                                        {row.frequency !== 'every' && (
                                            <p className="text-muted-foreground text-xs">{t('popups.shown_times', { count: row.views })}</p>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 flex-wrap items-center gap-1">
                                        <Button type="button" size="sm" variant="ghost" onClick={() => openEdit(row)}>
                                            {t('popups.edit')}
                                        </Button>
                                        <Button type="button" size="sm" variant="ghost" onClick={() => toggle(row)}>
                                            {row.is_active ? t('popups.pause') : t('popups.resume')}
                                        </Button>
                                        {row.key === null && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="text-red-700 dark:text-red-300"
                                                onClick={() => remove(row)}
                                            >
                                                <Trash2 className="size-3.5" /> {t('popups.delete')}
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{editing?.id === null ? t('popups.new') : t('popups.edit')}</DialogTitle>
                            <DialogDescription>{editing?.builtIn ? t('popups.built_in_hint') : t('popups.lines_hint')}</DialogDescription>
                        </DialogHeader>

                        <div className="space-y-5">
                            <div className="grid gap-1">
                                <Label htmlFor="popup-title">{t('popups.field.title')}</Label>
                                <Input
                                    id="popup-title"
                                    className="w-80"
                                    maxLength={120}
                                    value={draft.title}
                                    onChange={(event) => set('title', event.target.value)}
                                />
                                {error('title')}
                            </div>

                            <div className="space-y-2">
                                <Label>{t('popups.field.lines')}</Label>
                                {draft.lines.map((line, index) => (
                                    <div key={index} className="flex items-start gap-2">
                                        <select
                                            className="border-input bg-background h-9 w-32 shrink-0 rounded-md border px-2 text-sm"
                                            value={line.kind}
                                            onChange={(event) =>
                                                set(
                                                    'lines',
                                                    draft.lines.map((l, i) =>
                                                        i === index ? { ...l, kind: event.target.value as PopupLine['kind'] } : l,
                                                    ),
                                                )
                                            }
                                        >
                                            {KINDS.map((kind) => (
                                                <option key={kind} value={kind}>
                                                    {t(`popups.line.${kind}`)}
                                                </option>
                                            ))}
                                        </select>
                                        <textarea
                                            className="border-input bg-background min-h-9 w-full rounded-md border px-2 py-1.5 text-sm"
                                            rows={line.kind === 'body' ? 3 : 1}
                                            maxLength={500}
                                            dir={line.kind === 'arabic' ? 'rtl' : undefined}
                                            value={line.text}
                                            onChange={(event) =>
                                                set(
                                                    'lines',
                                                    draft.lines.map((l, i) => (i === index ? { ...l, text: event.target.value } : l)),
                                                )
                                            }
                                        />
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label={t('popups.remove_line')}
                                            disabled={draft.lines.length === 1}
                                            onClick={() =>
                                                set(
                                                    'lines',
                                                    draft.lines.filter((_, i) => i !== index),
                                                )
                                            }
                                        >
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    </div>
                                ))}
                                {error('lines')}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={draft.lines.length >= 8}
                                    onClick={() => set('lines', [...draft.lines, { kind: 'body', text: '' }])}
                                >
                                    <Plus className="size-3.5" /> {t('popups.add_line')}
                                </Button>
                            </div>

                            <div className="grid gap-1">
                                <Label htmlFor="popup-style">{t('popups.field.style')}</Label>
                                <select
                                    id="popup-style"
                                    className="border-input bg-background h-9 w-64 rounded-md border px-2 text-sm"
                                    value={draft.style}
                                    onChange={(event) => set('style', event.target.value as Draft['style'])}
                                >
                                    {STYLES.map((style) => (
                                        <option key={style} value={style}>
                                            {t(`popups.style.${style}`)}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">{t('popups.when_heading')}</legend>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="popup-login"
                                        checked={draft.on_login}
                                        onCheckedChange={(value) => set('on_login', value === true)}
                                    />
                                    <Label htmlFor="popup-login">{t('popups.field.on_login')}</Label>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="popup-idle">{t('popups.field.idle')}</Label>
                                    <Input
                                        id="popup-idle"
                                        type="number"
                                        min={5}
                                        max={1440}
                                        className="w-36"
                                        value={draft.idle_minutes ?? ''}
                                        onChange={(event) => set('idle_minutes', event.target.value === '' ? null : Number(event.target.value))}
                                    />
                                    <p className="text-muted-foreground text-xs">{t('popups.idle_hint')}</p>
                                    {error('on_login')}
                                    {error('idle_minutes')}
                                </div>
                            </fieldset>

                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">{t('popups.who_heading')}</legend>
                                <p className="text-muted-foreground text-xs">{t('popups.audience_hint')}</p>
                                <div className="flex flex-wrap gap-3">
                                    {roles.map((role) => (
                                        <label key={role} className="flex items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={draft.audience_roles.includes(role)}
                                                onCheckedChange={(value) =>
                                                    set(
                                                        'audience_roles',
                                                        value === true
                                                            ? [...draft.audience_roles, role]
                                                            : draft.audience_roles.filter((r) => r !== role),
                                                    )
                                                }
                                            />
                                            {role}
                                        </label>
                                    ))}
                                </div>
                                <div className="flex flex-wrap items-end gap-3">
                                    <div className="grid gap-1">
                                        <Label htmlFor="popup-frequency">{t('popups.field.frequency')}</Label>
                                        <select
                                            id="popup-frequency"
                                            className="border-input bg-background h-9 w-56 rounded-md border px-2 text-sm"
                                            value={draft.frequency}
                                            onChange={(event) => set('frequency', event.target.value as Draft['frequency'])}
                                        >
                                            {FREQUENCIES.map((frequency) => (
                                                <option key={frequency} value={frequency}>
                                                    {t(`popups.frequency.${frequency}`)}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="popup-starts">{t('popups.field.starts_on')}</Label>
                                        <Input
                                            id="popup-starts"
                                            type="date"
                                            className="w-auto"
                                            value={draft.starts_on ?? ''}
                                            onChange={(event) => set('starts_on', event.target.value || null)}
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="popup-ends">{t('popups.field.ends_on')}</Label>
                                        <Input
                                            id="popup-ends"
                                            type="date"
                                            className="w-auto"
                                            value={draft.ends_on ?? ''}
                                            onChange={(event) => set('ends_on', event.target.value || null)}
                                        />
                                    </div>
                                </div>
                                {error('ends_on')}
                            </fieldset>

                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">{t('popups.media_heading')}</legend>
                                <select
                                    className="border-input bg-background h-9 w-80 rounded-md border px-2 text-sm"
                                    value={draft.media_type}
                                    onChange={(event) => set('media_type', event.target.value as Draft['media_type'])}
                                >
                                    {MEDIA.map((media) => (
                                        <option key={media} value={media}>
                                            {t(`popups.media.${media}`)}
                                        </option>
                                    ))}
                                </select>
                                {draft.media_type === 'youtube' && (
                                    <div className="grid gap-1">
                                        <Label htmlFor="popup-youtube">{t('popups.field.youtube')}</Label>
                                        <Input
                                            id="popup-youtube"
                                            className="w-96"
                                            maxLength={200}
                                            value={draft.youtube}
                                            onChange={(event) => set('youtube', event.target.value)}
                                        />
                                        {error('youtube')}
                                    </div>
                                )}
                                {draft.media_type === 'video' && (
                                    <div className="grid gap-1">
                                        <Label htmlFor="popup-video">{t('popups.field.video')}</Label>
                                        <Input
                                            id="popup-video"
                                            type="file"
                                            accept=".mp4,.webm,video/mp4,video/webm"
                                            className="w-80"
                                            ref={videoInput}
                                        />
                                        {editing?.hasVideo && <p className="text-muted-foreground text-xs">{t('popups.video_kept')}</p>}
                                        {error('video')}
                                    </div>
                                )}
                            </fieldset>

                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">{t('popups.sound_heading')}</legend>
                                {draft.media_type !== 'none' ? (
                                    <p className="text-muted-foreground text-xs">{t('popups.sound_media_hint')}</p>
                                ) : (
                                    <div className="flex flex-wrap items-end gap-3">
                                        <select
                                            className="border-input bg-background h-9 w-56 rounded-md border px-2 text-sm"
                                            value={draft.sound_mode}
                                            onChange={(event) => set('sound_mode', event.target.value as Draft['sound_mode'])}
                                        >
                                            {SOUND_MODES.map((mode) => (
                                                <option key={mode} value={mode}>
                                                    {t(`popups.sound.${mode}`)}
                                                </option>
                                            ))}
                                        </select>
                                        {draft.sound_mode === 'custom' && (
                                            <select
                                                className="border-input bg-background h-9 w-56 rounded-md border px-2 text-sm"
                                                value={draft.sound_id ?? ''}
                                                onChange={(event) => set('sound_id', event.target.value === '' ? null : Number(event.target.value))}
                                            >
                                                <option value=""></option>
                                                {sounds.map((sound) => (
                                                    <option key={sound.id} value={sound.id}>
                                                        {sound.name}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                        {draft.sound_mode === 'default' && (
                                            <p className="text-muted-foreground text-xs">{t('popups.sound_default_hint')}</p>
                                        )}
                                        {error('sound_id')}
                                    </div>
                                )}
                            </fieldset>

                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">{t('popups.closing_heading')}</legend>
                                {draft.media_type !== 'none' ? (
                                    <p className="text-muted-foreground text-xs">{t('popups.close_media_hint')}</p>
                                ) : (
                                    <div className="grid gap-1">
                                        <Label htmlFor="popup-close">{t('popups.field.close_after')}</Label>
                                        <Input
                                            id="popup-close"
                                            type="number"
                                            min={3}
                                            max={120}
                                            className="w-36"
                                            value={draft.close_after_seconds ?? ''}
                                            onChange={(event) =>
                                                set('close_after_seconds', event.target.value === '' ? null : Number(event.target.value))
                                            }
                                        />
                                        {error('close_after_seconds')}
                                    </div>
                                )}
                                <div className="flex flex-wrap items-end gap-4">
                                    <div className="grid gap-1">
                                        <Label htmlFor="popup-priority">{t('popups.field.priority')}</Label>
                                        <Input
                                            id="popup-priority"
                                            type="number"
                                            min={0}
                                            max={1000}
                                            className="w-36"
                                            value={draft.priority}
                                            onChange={(event) => set('priority', Number(event.target.value))}
                                        />
                                    </div>
                                    <div className="flex items-center gap-2 pb-2">
                                        <Checkbox
                                            id="popup-active"
                                            checked={draft.is_active}
                                            onCheckedChange={(value) => set('is_active', value === true)}
                                        />
                                        <Label htmlFor="popup-active">{t('popups.field.active')}</Label>
                                    </div>
                                </div>
                            </fieldset>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                {t('popups.cancel')}
                            </Button>
                            <Button type="button" onClick={save} disabled={saving}>
                                {saving ? t('sounds.saving') : t('popups.save')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
