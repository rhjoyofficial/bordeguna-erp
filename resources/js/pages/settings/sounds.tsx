import { Head, router } from '@inertiajs/react';
import { Play, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { loadSample, markAudioUnlocked, playSample } from '@/lib/audio-unlock';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';

interface LibrarySound {
    id: number;
    name: string;
    mime_type: string;
    size: number;
    duration_ms: number | null;
    url: string;
    used_by: { slots: string[]; popups: string[] };
    created_at: string | null;
}

type Slot = 'success' | 'attention' | 'error' | 'popup' | 'schedule';

const SLOTS: Slot[] = ['success', 'attention', 'error', 'popup', 'schedule'];

/**
 * Settings > Sounds (Owner, system.popups.manage, 14 Oct 2026): the sound
 * library and which library sound each event plays. Group-wide.
 *
 * The duration is read in this browser at upload (an Audio element) and sent
 * along for display; the server trusts the bytes, not this number.
 */
export default function Sounds({
    sounds,
    slots,
    builtIn,
}: {
    sounds: LibrarySound[];
    slots: Partial<Record<Slot, number | null>>;
    builtIn: Record<'success' | 'attention' | 'error' | 'schedule', string>;
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('sounds.title'), href: '/settings/sounds' }];

    const [draft, setDraft] = useState<Partial<Record<Slot, number | null>>>(() => ({ ...slots }));
    const [savingSlots, setSavingSlots] = useState(false);
    const [uploadName, setUploadName] = useState('');
    const [uploading, setUploading] = useState(false);
    const uploadInput = useRef<HTMLInputElement>(null);
    const [renaming, setRenaming] = useState<{ id: number; name: string } | null>(null);
    const replaceInputs = useRef(new Map<number, HTMLInputElement | null>());

    const byId = new Map(sounds.map((sound) => [sound.id, sound]));

    const play = (url: string | null) => {
        if (url === null) {
            return;
        }

        markAudioUnlocked();
        void loadSample(url).then(() => playSample(url, 0.5, 10000));
    };

    const slotUrl = (slot: Slot): string | null => {
        const chosen = draft[slot];

        if (typeof chosen === 'number') {
            return byId.get(chosen)?.url ?? null;
        }

        return slot === 'popup' ? null : builtIn[slot];
    };

    const saveSlots = () => {
        setSavingSlots(true);
        router.put('/settings/sounds/slots', { slots: draft }, { preserveScroll: true, onFinish: () => setSavingSlots(false) });
    };

    /** The length of an audio file, read by the browser; null when it cannot tell. */
    const duration = (file: File): Promise<number | null> =>
        new Promise((resolve) => {
            const audio = document.createElement('audio');
            const url = URL.createObjectURL(file);
            const done = (value: number | null) => {
                URL.revokeObjectURL(url);
                resolve(value);
            };
            audio.preload = 'metadata';
            audio.onloadedmetadata = () => done(Number.isFinite(audio.duration) ? Math.round(audio.duration * 1000) : null);
            audio.onerror = () => done(null);
            audio.src = url;
        });

    const upload = async () => {
        const file = uploadInput.current?.files?.[0];

        if (!file) {
            return;
        }

        setUploading(true);
        const ms = await duration(file);
        router.post(
            '/settings/sounds',
            { name: uploadName, file, duration_ms: ms },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    setUploadName('');
                    if (uploadInput.current) {
                        uploadInput.current.value = '';
                    }
                },
                onFinish: () => setUploading(false),
            },
        );
    };

    const rename = (sound: LibrarySound) => {
        if (!renaming || renaming.id !== sound.id) {
            return;
        }

        router.post(`/settings/sounds/${sound.id}`, { name: renaming.name }, { preserveScroll: true, onSuccess: () => setRenaming(null) });
    };

    const replace = async (sound: LibrarySound) => {
        const file = replaceInputs.current.get(sound.id)?.files?.[0];

        if (!file) {
            return;
        }

        const ms = await duration(file);
        router.post(`/settings/sounds/${sound.id}`, { name: sound.name, file, duration_ms: ms }, { forceFormData: true, preserveScroll: true });
    };

    const remove = (sound: LibrarySound) => {
        if (window.confirm(t('sounds.delete_confirm'))) {
            router.delete(`/settings/sounds/${sound.id}`, { preserveScroll: true });
        }
    };

    const inUse = (sound: LibrarySound) => sound.used_by.slots.length > 0 || sound.used_by.popups.length > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('sounds.title')} />
            <SettingsLayout>
                <div className="space-y-8">
                    <HeadingSmall title={t('sounds.title')} description={t('sounds.description')} />

                    <section className="space-y-3">
                        <HeadingSmall title={t('sounds.events_heading')} description={t('sounds.events_hint')} />
                        <ul className="divide-y rounded-lg border">
                            {SLOTS.map((slot) => (
                                <li key={slot} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
                                    <Label htmlFor={`slot-${slot}`} className="sm:w-72">
                                        {t(`sounds.event.${slot}`)}
                                    </Label>
                                    <div className="flex items-center gap-2">
                                        <select
                                            id={`slot-${slot}`}
                                            className="border-input bg-background h-9 w-56 rounded-md border px-2 text-sm"
                                            value={draft[slot] ?? ''}
                                            onChange={(event) =>
                                                setDraft({ ...draft, [slot]: event.target.value === '' ? null : Number(event.target.value) })
                                            }
                                        >
                                            <option value="">{slot === 'popup' ? t('sounds.silence') : t('sounds.built_in')}</option>
                                            {sounds.map((sound) => (
                                                <option key={sound.id} value={sound.id}>
                                                    {sound.name}
                                                </option>
                                            ))}
                                        </select>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            disabled={slotUrl(slot) === null}
                                            onClick={() => play(slotUrl(slot))}
                                        >
                                            <Play className="size-3.5" /> {t('sounds.test')}
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <Button size="sm" onClick={saveSlots} disabled={savingSlots}>
                            {savingSlots ? t('sounds.saving') : t('sounds.save_events')}
                        </Button>
                    </section>

                    <section className="space-y-3">
                        <HeadingSmall title={t('sounds.library_heading')} description={t('sounds.library_hint')} />

                        <div className="flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:items-end">
                            <div className="grid gap-1">
                                <Label htmlFor="sound-name">{t('sounds.name')}</Label>
                                <Input
                                    id="sound-name"
                                    className="w-56"
                                    maxLength={80}
                                    value={uploadName}
                                    onChange={(event) => setUploadName(event.target.value)}
                                />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="sound-file">{t('sounds.file')}</Label>
                                <Input id="sound-file" type="file" accept=".mp3,.ogg,.wav,.m4a,audio/*" className="w-72" ref={uploadInput} />
                            </div>
                            <Button size="sm" onClick={() => void upload()} disabled={uploading}>
                                {uploading ? t('sounds.saving') : t('sounds.add')}
                            </Button>
                        </div>

                        {sounds.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{t('sounds.none')}</p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {sounds.map((sound) => (
                                    <li key={sound.id} className="space-y-2 p-3">
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="min-w-0 text-sm">
                                                {renaming?.id === sound.id ? (
                                                    <form
                                                        className="flex items-center gap-2"
                                                        onSubmit={(event) => {
                                                            event.preventDefault();
                                                            rename(sound);
                                                        }}
                                                    >
                                                        <Input
                                                            className="w-56"
                                                            maxLength={80}
                                                            autoFocus
                                                            value={renaming.name}
                                                            onChange={(event) => setRenaming({ id: sound.id, name: event.target.value })}
                                                        />
                                                        <Button type="submit" size="sm">
                                                            {t('sounds.rename')}
                                                        </Button>
                                                        <Button type="button" size="sm" variant="ghost" onClick={() => setRenaming(null)}>
                                                            {t('popups.cancel')}
                                                        </Button>
                                                    </form>
                                                ) : (
                                                    <p className="font-medium">{sound.name}</p>
                                                )}
                                                <p className="text-muted-foreground text-xs">
                                                    {sound.mime_type} · {Math.round(sound.size / 1024)} KB
                                                    {sound.duration_ms !== null && (
                                                        <> · {t('sounds.duration', { seconds: (sound.duration_ms / 1000).toFixed(1) })}</>
                                                    )}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    {inUse(sound)
                                                        ? `${t('sounds.used_by')}: ${[...sound.used_by.slots.map((slot) => t(`sounds.event.${slot as Slot}`)), ...sound.used_by.popups].join(', ')}`
                                                        : t('sounds.unused')}
                                                </p>
                                            </div>
                                            <div className="flex flex-wrap items-center gap-1">
                                                <Button type="button" size="sm" variant="ghost" onClick={() => play(sound.url)}>
                                                    <Play className="size-3.5" /> {t('sounds.test')}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => setRenaming({ id: sound.id, name: sound.name })}
                                                >
                                                    {t('sounds.rename')}
                                                </Button>
                                                <label
                                                    className="hover:bg-accent inline-flex cursor-pointer items-center rounded-md px-2 py-1 text-xs font-medium"
                                                    title={t('sounds.replace_hint')}
                                                >
                                                    {t('sounds.replace')}
                                                    <input
                                                        type="file"
                                                        accept=".mp3,.ogg,.wav,.m4a,audio/*"
                                                        className="sr-only"
                                                        ref={(element) => {
                                                            replaceInputs.current.set(sound.id, element);
                                                        }}
                                                        onChange={() => void replace(sound)}
                                                    />
                                                </label>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-red-700 dark:text-red-300"
                                                    disabled={inUse(sound)}
                                                    title={inUse(sound) ? t('sounds.in_use_no_delete') : undefined}
                                                    onClick={() => remove(sound)}
                                                >
                                                    <Trash2 className="size-3.5" /> {t('sounds.delete')}
                                                </Button>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
