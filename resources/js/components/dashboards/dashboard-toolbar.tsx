import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { dashboardSound } from '@/lib/dashboard-sound';
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
    const live = status === 'connected';
    const label = live
        ? 'Live'
        : status === 'connecting' || status === 'reconnecting'
          ? 'Connecting'
          : status === 'off'
            ? 'Refreshing on a timer'
            : 'Offline, refreshing on a timer';

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
    const [from, setFrom] = useState(range.from);
    const [to, setTo] = useState(range.to);

    const go = (query: Record<string, string>) => router.get(path, { ...query, ...keep }, { preserveScroll: true, preserveState: false });

    return (
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label="Date range">
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
                        {preset.label}
                    </Button>
                ))}
            <Popover>
                <PopoverTrigger asChild>
                    <Button size="sm" variant={range.preset === 'custom' ? 'default' : 'ghost'} className="h-7 rounded-full px-2.5 text-xs">
                        {range.preset === 'custom' ? range.label : 'Custom'}
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
                                <Label htmlFor="range-from">From</Label>
                                <Input id="range-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} required />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="range-to">To</Label>
                                <Input id="range-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} required />
                            </div>
                        </div>
                        <p className="text-muted-foreground text-xs">Dhaka dates, up to 366 days. Compared with the same length just before.</p>
                        <Button type="submit" size="sm" className="w-full">
                            Show
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
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label="Brand">
            <Tags className="text-muted-foreground size-4" aria-hidden />
            {[{ id: null as number | null, name: 'All brands' }, ...brands].map((brand, index) => (
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

const CLASSES: { key: SoundClass; label: string; hint: string }[] = [
    { key: 'success', label: 'Good news', hint: 'Orders, payments, approvals given' },
    { key: 'attention', label: 'Needs attention', hint: 'Waiting for you, low stock' },
    { key: 'error', label: 'Problems', hint: 'Failures and sync errors' },
];

/** Per-person sound settings, saved server-side (users.preferences). */
export function SoundSettingsPopover({ settings, onChange }: { settings: SoundSettings; onChange: (next: SoundSettings) => void }) {
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
                <Button size="sm" variant="outline" className="h-8 gap-1.5" aria-label="Sound settings">
                    {settings.enabled ? <Volume2 className="size-4" /> : <VolumeX className="size-4" />}
                    <span className="hidden sm:inline">Sound {settings.enabled ? 'on' : 'off'}</span>
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80" align="end">
                <div className="space-y-4 text-sm">
                    <label className="flex items-center justify-between gap-2">
                        <span className="font-medium">Play sounds on this dashboard</span>
                        <Checkbox checked={draft.enabled} onCheckedChange={(v) => save({ ...draft, enabled: v === true })} />
                    </label>
                    <div className="space-y-1">
                        <Label htmlFor="sound-volume">Volume</Label>
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
                        <legend className="mb-1 font-medium">Which sounds</legend>
                        {CLASSES.map((c) => (
                            <div key={c.key} className="flex items-center gap-2">
                                <Checkbox
                                    id={`sound-${c.key}`}
                                    checked={draft.categories[c.key]}
                                    onCheckedChange={(v) => save({ ...draft, categories: { ...draft.categories, [c.key]: v === true } })}
                                />
                                <label htmlFor={`sound-${c.key}`} className="flex-1">
                                    {c.label}
                                    <span className="text-muted-foreground block text-xs">{c.hint}</span>
                                </label>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="h-7 text-xs"
                                    onClick={() => dashboardSound.preview(c.key, draft.volume)}
                                >
                                    Test
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
                            <span className="font-medium">Quiet hours</span>
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                            <Input
                                type="time"
                                aria-label="Quiet from"
                                value={draft.quiet_hours.from}
                                onChange={(e) => setDraft({ ...draft, quiet_hours: { ...draft.quiet_hours, from: e.target.value } })}
                                onBlur={() => save(draft)}
                            />
                            <Input
                                type="time"
                                aria-label="Quiet until"
                                value={draft.quiet_hours.to}
                                onChange={(e) => setDraft({ ...draft, quiet_hours: { ...draft.quiet_hours, to: e.target.value } })}
                                onBlur={() => save(draft)}
                            />
                        </div>
                    </fieldset>
                    <p className="text-muted-foreground text-xs">
                        Never on opening the page or for your own actions. At most one sound every 3 seconds; a burst plays once.
                        {!dashboardSound.isUnlocked && ' Click anywhere once so the browser allows sound.'}
                        {saving && ' Saving.'}
                    </p>
                </div>
            </PopoverContent>
        </Popover>
    );
}
