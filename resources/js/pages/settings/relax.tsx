import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ExternalLink, Plus, Trash2 } from 'lucide-react';
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
import { type BreadcrumbItem } from '@/types';

type Kind = 'audio' | 'video' | 'youtube';

interface RelaxRow {
    id: number;
    title: string;
    description: string | null;
    kind: Kind;
    media_url: string | null;
    media_size: number | null;
    youtube_id: string | null;
    sort_order: number;
    is_active: boolean;
}

interface Draft {
    title: string;
    description: string;
    kind: Kind;
    youtube: string;
    sort_order: number;
    is_active: boolean;
}

const KINDS: Kind[] = ['audio', 'video', 'youtube'];

/** What the file picker offers; the server still judges the bytes. */
const ACCEPT: Record<'audio' | 'video', string> = {
    audio: '.mp3,.ogg,.m4a,.wav,audio/mpeg,audio/ogg,audio/mp4,audio/wav',
    video: '.mp4,.webm,video/mp4,video/webm',
};

/**
 * Settings > Relax zone (Owner and Manager, relax.manage, 14 Oct 2026): what
 * the Relax zone page offers. Group-wide. Order by the sort number, or one
 * place at a time with the arrows.
 */
export default function RelaxSettings({ items }: { items: RelaxRow[] }) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('relax.title'), href: '/settings/relax' }];

    const [editing, setEditing] = useState<{ id: number | null; kind: Kind | null; hasFile: boolean } | null>(null);
    const [draft, setDraft] = useState<Draft>({ title: '', description: '', kind: 'audio', youtube: '', sort_order: 0, is_active: true });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    const openNew = () => {
        const next = items.reduce((max, row) => Math.max(max, row.sort_order), 0) + 10;
        setDraft({ title: '', description: '', kind: 'audio', youtube: '', sort_order: next, is_active: true });
        setErrors({});
        setEditing({ id: null, kind: null, hasFile: false });
    };

    const openEdit = (row: RelaxRow) => {
        setDraft({
            title: row.title,
            description: row.description ?? '',
            kind: row.kind,
            youtube: row.youtube_id ?? '',
            sort_order: row.sort_order,
            is_active: row.is_active,
        });
        setErrors({});
        setEditing({ id: row.id, kind: row.kind, hasFile: row.media_url !== null });
    };

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((current) => ({ ...current, [key]: value }));

    const save = () => {
        if (!editing) {
            return;
        }

        const file = draft.kind !== 'youtube' ? (fileInput.current?.files?.[0] ?? null) : null;
        const payload: Record<string, FormDataConvertible> = {
            ...draft,
            is_active: draft.is_active ? 1 : 0,
        };

        if (file) {
            payload.file = file;
        }

        setSaving(true);
        router.post(editing.id === null ? '/settings/relax' : `/settings/relax/${editing.id}`, payload, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setEditing(null),
            onError: (bag) => setErrors(bag as Record<string, string>),
            onFinish: () => setSaving(false),
        });
    };

    const toggle = (row: RelaxRow) => router.patch(`/settings/relax/${row.id}/toggle`, {}, { preserveScroll: true });

    const move = (row: RelaxRow, direction: 'up' | 'down') => router.patch(`/settings/relax/${row.id}/move`, { direction }, { preserveScroll: true });

    const remove = (row: RelaxRow) => {
        if (window.confirm(t('relax.settings.delete_confirm'))) {
            router.delete(`/settings/relax/${row.id}`, { preserveScroll: true });
        }
    };

    const error = (key: string) => (errors[key] ? <p className="text-xs text-red-700 dark:text-red-300">{errors[key]}</p> : null);

    // The current file stays only while the kind is unchanged: an audio file never becomes a video.
    const keepsFile = editing !== null && editing.hasFile && editing.kind === draft.kind;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('relax.title')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-3">
                        <HeadingSmall title={t('relax.title')} description={t('relax.settings.description')} />
                        <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
                            <Button size="sm" variant="outline" asChild>
                                <Link href="/relax">
                                    <ExternalLink className="size-3.5" /> {t('relax.settings.open_page')}
                                </Link>
                            </Button>
                            <Button size="sm" onClick={openNew}>
                                <Plus className="size-3.5" /> {t('relax.settings.new')}
                            </Button>
                        </div>
                    </div>

                    {items.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('relax.settings.none')}</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {items.map((row, index) => (
                                <li key={row.id} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0 space-y-1 text-sm">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-medium">{row.title}</p>
                                            <Badge variant={row.is_active ? 'default' : 'outline'}>
                                                {row.is_active ? t('relax.settings.active') : t('relax.settings.paused')}
                                            </Badge>
                                            <Badge variant="outline">{t(`relax.kind.${row.kind}`)}</Badge>
                                        </div>
                                        {row.description && <p className="text-muted-foreground truncate text-xs">{row.description}</p>}
                                        <p className="text-muted-foreground text-xs">
                                            #{row.sort_order}
                                            {row.kind === 'youtube' && row.youtube_id ? ` · ${row.youtube_id}` : null}
                                            {row.kind !== 'youtube'
                                                ? ` · ${
                                                      row.media_size !== null
                                                          ? t('relax.settings.size', { size: (row.media_size / 1048576).toFixed(1) })
                                                          : t('relax.settings.no_file')
                                                  }`
                                                : null}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 flex-wrap items-center gap-1">
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label={t('relax.settings.move_up')}
                                            title={t('relax.settings.move_up')}
                                            disabled={index === 0}
                                            onClick={() => move(row, 'up')}
                                        >
                                            <ArrowUp className="size-3.5" />
                                        </Button>
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label={t('relax.settings.move_down')}
                                            title={t('relax.settings.move_down')}
                                            disabled={index === items.length - 1}
                                            onClick={() => move(row, 'down')}
                                        >
                                            <ArrowDown className="size-3.5" />
                                        </Button>
                                        <Button type="button" size="sm" variant="ghost" onClick={() => openEdit(row)}>
                                            {t('relax.settings.edit')}
                                        </Button>
                                        <Button type="button" size="sm" variant="ghost" onClick={() => toggle(row)}>
                                            {row.is_active ? t('relax.settings.pause') : t('relax.settings.resume')}
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            className="text-red-700 dark:text-red-300"
                                            onClick={() => remove(row)}
                                        >
                                            <Trash2 className="size-3.5" /> {t('relax.settings.delete')}
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>{editing?.id === null ? t('relax.settings.new') : t('relax.settings.edit_title')}</DialogTitle>
                            <DialogDescription>{t('relax.settings.form_hint')}</DialogDescription>
                        </DialogHeader>

                        <div className="space-y-5">
                            <div className="grid gap-1">
                                <Label htmlFor="relax-title">{t('relax.field.title')}</Label>
                                <Input
                                    id="relax-title"
                                    className="w-80 max-w-full"
                                    maxLength={120}
                                    value={draft.title}
                                    onChange={(event) => set('title', event.target.value)}
                                />
                                {error('title')}
                            </div>

                            <div className="grid gap-1">
                                <Label htmlFor="relax-description">{t('relax.field.description')}</Label>
                                <textarea
                                    id="relax-description"
                                    className="border-input bg-background min-h-9 w-full rounded-md border px-2 py-1.5 text-sm"
                                    rows={3}
                                    maxLength={500}
                                    value={draft.description}
                                    onChange={(event) => set('description', event.target.value)}
                                />
                                {error('description')}
                            </div>

                            <div className="grid gap-1">
                                <Label htmlFor="relax-kind">{t('relax.field.kind')}</Label>
                                <select
                                    id="relax-kind"
                                    className="border-input bg-background h-9 w-80 max-w-full rounded-md border px-2 text-sm"
                                    value={draft.kind}
                                    onChange={(event) => set('kind', event.target.value as Kind)}
                                >
                                    {KINDS.map((kind) => (
                                        <option key={kind} value={kind}>
                                            {t(`relax.field.kind.${kind}`)}
                                        </option>
                                    ))}
                                </select>
                                {error('kind')}
                            </div>

                            {draft.kind === 'youtube' ? (
                                <div className="grid gap-1">
                                    <Label htmlFor="relax-youtube">{t('relax.field.youtube')}</Label>
                                    <Input
                                        id="relax-youtube"
                                        className="w-96 max-w-full"
                                        maxLength={200}
                                        value={draft.youtube}
                                        onChange={(event) => set('youtube', event.target.value)}
                                    />
                                    {error('youtube')}
                                </div>
                            ) : (
                                <div className="grid gap-1">
                                    <Label htmlFor="relax-file">{t('relax.field.file')}</Label>
                                    <Input
                                        key={draft.kind}
                                        id="relax-file"
                                        type="file"
                                        accept={ACCEPT[draft.kind]}
                                        className="w-80 max-w-full"
                                        ref={fileInput}
                                    />
                                    {keepsFile && <p className="text-muted-foreground text-xs">{t('relax.settings.file_kept')}</p>}
                                    {error('file')}
                                </div>
                            )}

                            <div className="flex flex-wrap items-end gap-4">
                                <div className="grid gap-1">
                                    <Label htmlFor="relax-sort">{t('relax.field.sort_order')}</Label>
                                    <Input
                                        id="relax-sort"
                                        type="number"
                                        min={0}
                                        max={100000}
                                        className="w-36"
                                        value={draft.sort_order}
                                        onChange={(event) => set('sort_order', Number(event.target.value))}
                                    />
                                    {error('sort_order')}
                                </div>
                                <div className="flex items-center gap-2 pb-2">
                                    <Checkbox
                                        id="relax-active"
                                        checked={draft.is_active}
                                        onCheckedChange={(value) => set('is_active', value === true)}
                                    />
                                    <Label htmlFor="relax-active">{t('relax.field.active')}</Label>
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                {t('relax.settings.cancel')}
                            </Button>
                            <Button type="button" onClick={save} disabled={saving}>
                                {saving ? t('relax.settings.saving') : t('relax.settings.save')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
