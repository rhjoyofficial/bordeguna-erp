import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { dashboardSound } from '@/lib/dashboard-sound';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { CalendarRange, Tags, Volume2, VolumeX } from 'lucide-react';
import { useState } from 'react';
import { brandColour } from './palette';
import type { RangeState, SoundClass, SoundSettings } from './types';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function sendJson(method: 'PUT' | 'POST', url: string, body: unknown): Promise<Response> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify(body),
    });
}

/** The live/offline dot. Live = socket connected; otherwise each widget polls. */
export function LiveIndicator({ status }: { status: string }) {
    const { t } = useTranslation();
    const live = status === 'connected';
    const label = live
        ? t('dash.live')
        : status === 'connecting' || status === 'reconnecting'
          ? t('dash.connecting')
          : status === 'off'
            ? t('dash.timer')
            : t('dash.offline_timer');

    return (
        <span className="text-muted-foreground flex items-center gap-1.5 text-xs" title={label} aria-live="polite">
            <span className="relative flex size-2.5">
                {live && (
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60 motion-reduce:hidden" />
                )}
                <span
                    className={cn(
                        'relative inline-flex size-2.5 rounded-full',
                        live ? 'bg-emerald-500' : status === 'off' ? 'bg-slate-400' : 'bg-amber-500',
                    )}
                />
            </span>
            {label}
        </span>
    );
}

/**
 * The dashboard's one date range, kept in the URL (ruling 8). A change reloads
 * the page props with the new range; the widgets that ignore the range say so
 * on their card.
 */
export function RangeBar({
    range,
    presets,
    path,
    keep = {},
}: {
    range: RangeState;
    presets: { value: string; label: string }[];
    path: string;
    /** Other dashboard filters (the brand) that a range change keeps. */
    keep?: Record<string, string>;
}) {
    const { t, tr } = useTranslation();
    const [from, setFrom] = useState(range.from);
    const [to, setTo] = useState(range.to);

    const go = (query: Record<string, string>) => router.get(path, { ...query, ...keep }, { preserveScroll: true, preserveState: false });

    return (
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label={t('dash.date_range')}>
            <CalendarRange className="text-muted-foreground size-4" aria-hidden />
            {presets
                .filter((p) => p.value !== 'custom')
                .map((preset) => (
                    <Button
                        key={preset.value}
                        size="sm"
                        variant={range.preset === preset.value ? 'default' : 'ghost'}
                        className="h-7 rounded-full px-2.5 text-xs"
                        onClick={() => go({ range: preset.value })}
                        aria-pressed={range.preset === preset.value}
                    >
                        {tr(`dash.preset.${preset.value}`, preset.label)}
                    </Button>
                ))}
            <Popover>
                <PopoverTrigger asChild>
                    <Button size="sm" variant={range.preset === 'custom' ? 'default' : 'ghost'} className="h-7 rounded-full px-2.5 text-xs">
                        {range.preset === 'custom' ? range.label : t('dash.preset.custom')}
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-72" align="end">
                    <form
                        className="space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ range: 'custom', from, to });
                        }}
                    >
                        <div className="grid grid-cols-2 gap-2">
                            <div className="space-y-1">
                                <Label htmlFor="range-from">{t('dash.from')}</Label>
                                <Input id="range-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} required />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="range-to">{t('dash.to')}</Label>
                                <Input id="range-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} required />
                            </div>
                        </div>
                        <p className="text-muted-foreground text-xs">{t('dash.range_note')}</p>
                        <Button type="submit" size="sm" className="w-full">
                            {t('dash.show')}
                        </Button>
                    </form>
                </PopoverContent>
            </Popover>
        </div>
    );
}

/**
 * The Brand switcher beside the range (Joy, 11 Oct 2026): All brands or one
 * brand, kept in the URL with the range. Orders, parcels and what was sold
 * follow it; the ledger and people stay the whole business and say so.
 */
export function BrandBar({
    brands,
    selected,
    path,
    rangeQuery,
}: {
    brands: { id: number; name: string }[];
    selected: number | null;
    path: string;
    rangeQuery: Record<string, string>;
}) {
    const { t } = useTranslation();

    if (brands.length === 0) {
        return null;
    }

    const go = (brand: number | null) => {
        const query: Record<string, string> = { ...rangeQuery };
        delete query.brand;

        if (brand !== null) {
            query.brand = String(brand);
        }

        router.get(path, query, { preserveScroll: true, preserveState: false });
    };

    return (
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label={t('orders.brand')}>
            <Tags className="text-muted-foreground size-4" aria-hidden />
            {[{ id: null as number | null, name: t('orders.all_brands') }, ...brands].map((brand, index) => (
                <Button
                    key={brand.id ?? 'all'}
                    size="sm"
                    variant={selected === brand.id ? 'default' : 'ghost'}
                    className="h-7 gap-1.5 rounded-full px-2.5 text-xs"
                    onClick={() => go(brand.id)}
                    aria-pressed={selected === brand.id}
                >
                    {/* The brand's own colour, the same wherever the brand appears; the name carries the meaning. */}
                    {brand.id !== null && (
                        <span
                            className="size-2.5 rounded-full ring-2 ring-white/70 dark:ring-black/30"
                            style={{ background: brandColour(brand.name, index - 1) }}
                            aria-hidden
                        />
                    )}
                    {brand.name}
                </Button>
            ))}
        </div>
    );
}

const CLASSES: { key: SoundClass; label: TranslationKey; hint: TranslationKey }[] = [
    { key: 'success', label: 'dash.sound.success', hint: 'dash.sound.success_hint' },
    { key: 'attention', label: 'dash.sound.attention', hint: 'dash.sound.attention_hint' },
    { key: 'error', label: 'dash.sound.error', hint: 'dash.sound.error_hint' },
];

/** Per-person sound settings, saved server-side (users.preferences). */
export function SoundSettingsPopover({ settings, onChange }: { settings: SoundSettings; onChange: (next: SoundSettings) => void }) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState(settings);
    const [saving, setSaving] = useState(false);

    const save = async (next: SoundSettings) => {
        setDraft(next);
        setSaving(true);

        try {
            const response = await sendJson('PUT', '/dashboard/sound', next);

            if (response.ok) {
                const body = (await response.json()) as { sound: SoundSettings };
                onChange(body.sound);
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button size="sm" variant="outline" className="h-8 gap-1.5" aria-label={t('dash.sound_settings')}>
                    {settings.enabled ? <Volume2 className="size-4" /> : <VolumeX className="size-4" />}
                    <span className="hidden sm:inline">{settings.enabled ? t('dash.sound_on') : t('dash.sound_off')}</span>
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80" align="end">
                <div className="space-y-4 text-sm">
                    <label className="flex items-center justify-between gap-2">
                        <span className="font-medium">{t('dash.sound_enable')}</span>
                        <Checkbox checked={draft.enabled} onCheckedChange={(v) => save({ ...draft, enabled: v === true })} />
                    </label>
                    <div className="space-y-1">
                        <Label htmlFor="sound-volume">{t('dash.volume')}</Label>
                        <input
                            id="sound-volume"
                            type="range"
                            min={0}
                            max={1}
                            step={0.05}
                            value={draft.volume}
                            onChange={(e) => setDraft({ ...draft, volume: Number(e.target.value) })}
                            onPointerUp={() => save(draft)}
                            onKeyUp={() => save(draft)}
                            className="accent-primary w-full"
                        />
                    </div>
                    <fieldset className="space-y-2">
                        <legend className="mb-1 font-medium">{t('dash.which_sounds')}</legend>
                        {CLASSES.map((c) => (
                            <div key={c.key} className="flex items-center gap-2">
                                <Checkbox
                                    id={`sound-${c.key}`}
                                    checked={draft.categories[c.key]}
                                    onCheckedChange={(v) => save({ ...draft, categories: { ...draft.categories, [c.key]: v === true } })}
                                />
                                <label htmlFor={`sound-${c.key}`} className="flex-1">
                                    {t(c.label)}
                                    <span className="text-muted-foreground block text-xs">{t(c.hint)}</span>
                                </label>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="h-7 text-xs"
                                    onClick={() => dashboardSound.preview(c.key, draft.volume)}
                                >
                                    {t('dash.sound_test')}
                                </Button>
                            </div>
                        ))}
                    </fieldset>
                    <fieldset className="space-y-2">
                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={draft.quiet_hours.enabled}
                                onCheckedChange={(v) => save({ ...draft, quiet_hours: { ...draft.quiet_hours, enabled: v === true } })}
                            />
                            <span className="font-medium">{t('dash.quiet_hours')}</span>
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                            <Input
                                type="time"
                                aria-label={t('dash.quiet_from')}
                                value={draft.quiet_hours.from}
                                onChange={(e) => setDraft({ ...draft, quiet_hours: { ...draft.quiet_hours, from: e.target.value } })}
                                onBlur={() => save(draft)}
                            />
                            <Input
                                type="time"
                                aria-label={t('dash.quiet_until')}
                                value={draft.quiet_hours.to}
                                onChange={(e) => setDraft({ ...draft, quiet_hours: { ...draft.quiet_hours, to: e.target.value } })}
                                onBlur={() => save(draft)}
                            />
                        </div>
                    </fieldset>
                    <p className="text-muted-foreground text-xs">
                        {t('dash.sound_note')}
                        {!dashboardSound.isUnlocked && ` ${t('dash.sound_unlock')}`}
                        {saving && ` ${t('dash.saving')}`}
                    </p>
                </div>
            </PopoverContent>
        </Popover>
    );
}
