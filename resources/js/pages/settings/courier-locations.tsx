import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';

type ProviderOption = { code: string; name: string; accounts: { id: number; label: string }[] };
type Candidate = { id: string; name: string };

type MapRow = {
    id: number;
    district: string;
    thana: string;
    area: string | null;
    city_id: string | null;
    city_name: string | null;
    zone_id: string | null;
    zone_name: string | null;
    area_id: string | null;
    area_name: string | null;
    status: 'applied' | 'suggested';
    source: 'auto_exact' | 'auto_suggested' | 'manual' | 'api_suggestion';
    confidence: 'high' | 'medium' | 'low';
    candidates: Candidate[];
    note: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
};

type Band = { id: number; code: string; name: string; districts: string[]; thanas: string[]; is_default: boolean };

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type PageProps = {
    providers: ProviderOption[];
    provider: string;
    filter: 'review' | 'unmapped' | 'mapped' | 'all';
    search: string;
    stats: {
        synced: { city: number; zone: number; area: number };
        thanas: number;
        mapped: number;
        review: number;
        unmapped: number;
        unreviewed_high: number;
        area_maps: number;
    };
    lastSync: {
        id: number;
        status: 'running' | 'done' | 'failed';
        cities: number;
        zones: number;
        areas: number;
        calls: number;
        last_error: string | null;
        started_at: string | null;
        finished_at: string | null;
    } | null;
    maps: Paginated<MapRow>;
    cities: Candidate[];
    bands: Band[];
    districts: string[];
    errors: Record<string, string | undefined>;
};

type LocationOption = { id: string; name: string; home_delivery: boolean | null };
type Suggestion = { city_id: string; city_name: string; zone_id: string; zone_name: string; area_id: string; area_name: string };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Couriers', href: '/settings/couriers' },
    { title: 'Locations', href: '/settings/couriers/locations' },
];

const FILTERS: { key: PageProps['filter']; label: string }[] = [
    { key: 'review', label: 'Needs review' },
    { key: 'unmapped', label: 'No match' },
    { key: 'mapped', label: 'Mapped' },
    { key: 'all', label: 'All' },
];

const SOURCE_LABELS: Record<MapRow['source'], string> = {
    auto_exact: 'Same name',
    auto_suggested: 'Suggested',
    manual: 'Set by hand',
    api_suggestion: 'Courier suggestion',
};

async function getJson<T>(url: string): Promise<T | null> {
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });

        return response.ok ? ((await response.json()) as T) : null;
    } catch {
        return null;
    }
}

/** The zones of a city, or the areas of a zone, from the synced courier list. */
function useLocations(provider: string, level: 'zone' | 'area', parent: string | null) {
    const [items, setItems] = useState<LocationOption[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!parent) {
            setItems([]);

            return;
        }

        let cancelled = false;
        setLoading(true);
        getJson<{ items: LocationOption[] }>(route('couriers.settings.locations.options', { provider, level, parent })).then((data) => {
            if (!cancelled) {
                setItems(data?.items ?? []);
                setLoading(false);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [provider, level, parent]);

    return { items, loading };
}

function Stat({ label, value, hint, tone }: { label: string; value: string | number; hint?: string; tone?: 'good' | 'warn' | 'bad' }) {
    return (
        <div className="border-border rounded-md border p-3">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p
                className={cn(
                    'text-xl font-semibold tabular-nums',
                    tone === 'good' && 'text-emerald-700 dark:text-emerald-300',
                    tone === 'warn' && 'text-amber-700 dark:text-amber-300',
                    tone === 'bad' && 'text-destructive',
                )}
            >
                {value}
            </p>
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
        </div>
    );
}

function ConfidenceBadge({ row }: { row: MapRow }) {
    const applied = row.status === 'applied';

    return (
        <div className="flex flex-wrap gap-1 text-xs">
            <span
                className={cn(
                    'rounded-full px-2 py-0.5',
                    applied
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                        : row.zone_id || row.candidates.length > 0
                          ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                          : 'bg-muted text-muted-foreground',
                )}
            >
                {applied ? 'In use' : row.zone_id || row.candidates.length > 0 ? 'Waiting for review' : 'No match'}
            </span>
            <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5">
                {SOURCE_LABELS[row.source]}, {row.confidence}
            </span>
        </div>
    );
}

/**
 * Pick the courier's city, zone and (optional) area for one place. Everything
 * offered comes from the synced list; the server checks it again on save.
 */
function MapEditor({ provider, row, cities, onDone }: { provider: string; row: MapRow; cities: Candidate[]; onDone: () => void }) {
    const { errors } = usePage<PageProps>().props;
    const [cityId, setCityId] = useState(row.city_id ?? '');
    const [zoneId, setZoneId] = useState(row.zone_id ?? '');
    const [areaId, setAreaId] = useState(row.area_id ?? '');
    const [source, setSource] = useState<'manual' | 'api_suggestion'>('manual');
    const [placeArea, setPlaceArea] = useState(row.area ?? '');
    const [placeAreas, setPlaceAreas] = useState<string[]>([]);
    const [suggestions, setSuggestions] = useState<Suggestion[] | null>(null);
    const [suggestError, setSuggestError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const zones = useLocations(provider, 'zone', cityId || null);
    const areas = useLocations(provider, 'area', zoneId || null);

    useEffect(() => {
        getJson<{ data: string[] }>(route('places.areas', { district: row.district, thana: row.thana })).then((data) =>
            setPlaceAreas(data?.data ?? []),
        );
    }, [row.district, row.thana]);

    const askCarryBee = () => {
        setSuggestError(null);
        getJson<{ items: Suggestion[]; error: string | null }>(route('couriers.settings.locations.suggest', { q: placeArea || row.thana })).then(
            (data) => {
                setSuggestions(data?.items ?? []);
                setSuggestError(data === null ? 'CarryBee did not answer.' : data.error);
            },
        );
    };

    const applySuggestion = (s: Suggestion) => {
        setCityId(s.city_id);
        setZoneId(s.zone_id);
        setAreaId(s.area_id);
        setSource('api_suggestion');
    };

    const save = () => {
        setSaving(true);
        router.put(
            route('couriers.settings.locations.save'),
            {
                provider,
                district: row.district,
                thana: row.thana,
                area: placeArea || null,
                city_id: cityId,
                zone_id: zoneId,
                area_id: areaId || null,
                source,
            },
            { preserveScroll: true, onSuccess: () => onDone(), onFinish: () => setSaving(false) },
        );
    };

    const selectedArea = areas.items.find((a) => a.id === areaId);

    return (
        <div className="bg-muted/30 space-y-3 rounded-md p-3">
            {row.candidates.length > 0 && (
                <div className="space-y-1">
                    <p className="text-xs font-medium">Zones whose name matches</p>
                    <div className="flex flex-wrap gap-1">
                        {row.candidates.map((c) => (
                            <Button
                                key={c.id}
                                type="button"
                                size="sm"
                                variant={zoneId === c.id ? 'default' : 'outline'}
                                onClick={() => {
                                    if (row.city_id) setCityId(row.city_id);
                                    setZoneId(c.id);
                                    setAreaId('');
                                    setSource('manual');
                                }}
                            >
                                {c.name}
                            </Button>
                        ))}
                    </div>
                </div>
            )}

            <div className="grid gap-3 md:grid-cols-4">
                <div className="space-y-1">
                    <label className="text-xs font-medium" htmlFor={`place-area-${row.id}`}>
                        Area of {row.thana} (optional)
                    </label>
                    <SearchSelect
                        id={`place-area-${row.id}`}
                        value={placeArea}
                        onChange={(e) => setPlaceArea(e.target.value)}
                        disabled={row.area !== null}
                    >
                        <option value="">The whole thana</option>
                        {placeAreas.map((a) => (
                            <option key={a} value={a}>
                                {a}
                            </option>
                        ))}
                    </SearchSelect>
                </div>
                <div className="space-y-1">
                    <label className="text-xs font-medium" htmlFor={`city-${row.id}`}>
                        Courier city
                    </label>
                    <SearchSelect
                        id={`city-${row.id}`}
                        value={cityId}
                        invalid={!!errors.city_id}
                        onChange={(e) => {
                            setCityId(e.target.value);
                            setZoneId('');
                            setAreaId('');
                        }}
                    >
                        <option value="">Choose a city</option>
                        {cities.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </SearchSelect>
                    <InputError message={errors.city_id} />
                </div>
                <div className="space-y-1">
                    <label className="text-xs font-medium" htmlFor={`zone-${row.id}`}>
                        Courier zone
                    </label>
                    <SearchSelect
                        id={`zone-${row.id}`}
                        value={zoneId}
                        disabled={!cityId || zones.loading}
                        invalid={!!errors.zone_id}
                        emptyText="No zone by that name"
                        onChange={(e) => {
                            setZoneId(e.target.value);
                            setAreaId('');
                        }}
                    >
                        <option value="">{zones.loading ? 'Loading...' : 'Choose a zone'}</option>
                        {zones.items.map((z) => (
                            <option key={z.id} value={z.id}>
                                {z.name}
                            </option>
                        ))}
                    </SearchSelect>
                    <InputError message={errors.zone_id} />
                </div>
                <div className="space-y-1">
                    <label className="text-xs font-medium" htmlFor={`area-${row.id}`}>
                        Courier area (optional)
                    </label>
                    <SearchSelect
                        id={`area-${row.id}`}
                        value={areaId}
                        disabled={!zoneId || areas.loading}
                        invalid={!!errors.area_id}
                        onChange={(e) => setAreaId(e.target.value)}
                    >
                        <option value="">{areas.loading ? 'Loading...' : 'No area'}</option>
                        {areas.items.map((a) => (
                            <option key={a.id} value={a.id} data-hint={a.home_delivery === false ? 'No home delivery here' : undefined}>
                                {a.name}
                            </option>
                        ))}
                    </SearchSelect>
                    {selectedArea?.home_delivery === false && (
                        <p className="text-destructive text-xs">The courier does not deliver to homes in this area; booking will refuse it.</p>
                    )}
                    <InputError message={errors.area_id} />
                </div>
            </div>

            {provider === 'carrybee' && (
                <div className="space-y-1">
                    <Button type="button" size="sm" variant="outline" onClick={askCarryBee}>
                        Ask CarryBee about "{placeArea || row.thana}"
                    </Button>
                    {suggestError && <p className="text-muted-foreground text-xs">{suggestError}</p>}
                    {suggestions !== null && suggestions.length === 0 && !suggestError && (
                        <p className="text-muted-foreground text-xs">CarryBee has no suggestion for that name.</p>
                    )}
                    {suggestions !== null && suggestions.length > 0 && (
                        <div className="space-y-1">
                            <p className="text-muted-foreground text-xs">A hint from CarryBee, not an answer. Check the district before using one.</p>
                            <div className="flex flex-wrap gap-1">
                                {suggestions.slice(0, 10).map((s) => (
                                    <Button
                                        key={`${s.zone_id}-${s.area_id}`}
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        className="border-border border"
                                        onClick={() => applySuggestion(s)}
                                    >
                                        {s.area_name ? `${s.area_name}, ` : ''}
                                        {s.zone_name}, {s.city_name}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            )}

            <InputError message={errors.thana ?? errors.area ?? errors.provider} />

            <div className="flex gap-2">
                <Button type="button" size="sm" disabled={!cityId || !zoneId || saving} onClick={save}>
                    {saving ? 'Saving...' : 'Save and use'}
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}

/** One courier band: its name and the districts and thanas it covers. */
function BandEditor({ band, provider, districts }: { band: Band | null; provider: string; districts: string[] }) {
    const { errors } = usePage<PageProps>().props;
    const [name, setName] = useState(band?.name ?? '');
    const [chosenDistricts, setChosenDistricts] = useState<string[]>(band?.districts ?? []);
    const [chosenThanas, setChosenThanas] = useState<string[]>(band?.thanas ?? []);
    const [pickDistrict, setPickDistrict] = useState('');
    const [thanaDistrict, setThanaDistrict] = useState('');
    const [thanaOptions, setThanaOptions] = useState<string[]>([]);
    const [pickThana, setPickThana] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!thanaDistrict) {
            setThanaOptions([]);

            return;
        }

        getJson<{ data: string[] }>(route('places.thanas', { district: thanaDistrict })).then((data) => setThanaOptions(data?.data ?? []));
    }, [thanaDistrict]);

    const save = () => {
        setSaving(true);
        const payload = { provider, name, districts: chosenDistricts, thanas: chosenThanas };
        const options = { preserveScroll: true, onFinish: () => setSaving(false) };

        if (band) {
            router.put(route('couriers.settings.locations.bands.update', band.id), payload, options);
        } else {
            router.post(route('couriers.settings.locations.bands.store'), payload, {
                ...options,
                onSuccess: () => {
                    setName('');
                    setChosenDistricts([]);
                    setChosenThanas([]);
                },
            });
        }
    };

    const chip = (label: string, onRemove: () => void) => (
        <span key={label} className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs">
            {label}
            <button type="button" className="text-muted-foreground hover:text-foreground" aria-label={`Remove ${label}`} onClick={onRemove}>
                x
            </button>
        </span>
    );

    return (
        <div className="border-border space-y-3 rounded-md border p-3">
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-48 flex-1 space-y-1">
                    <label className="text-xs font-medium" htmlFor={`band-name-${band?.id ?? 'new'}`}>
                        {band ? 'Band name' : 'New band name'}
                    </label>
                    <Input id={`band-name-${band?.id ?? 'new'}`} value={name} maxLength={120} onChange={(e) => setName(e.target.value)} />
                </div>
                {band?.is_default && <p className="text-muted-foreground text-xs">Everywhere no other band names. It keeps no rules of its own.</p>}
            </div>

            {!band?.is_default && (
                <div className="grid gap-3 md:grid-cols-2">
                    <div className="space-y-2">
                        <p className="text-xs font-medium">Whole districts</p>
                        <div className="flex flex-wrap gap-1">
                            {chosenDistricts.length === 0 && <span className="text-muted-foreground text-xs">None</span>}
                            {chosenDistricts.map((d) => chip(d, () => setChosenDistricts(chosenDistricts.filter((x) => x !== d))))}
                        </div>
                        <div className="flex gap-2">
                            <SearchSelect value={pickDistrict} onChange={(e) => setPickDistrict(e.target.value)}>
                                <option value="">Add a district</option>
                                {districts.map((d) => (
                                    <option key={d} value={d}>
                                        {d}
                                    </option>
                                ))}
                            </SearchSelect>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={!pickDistrict}
                                onClick={() => {
                                    if (!chosenDistricts.includes(pickDistrict)) setChosenDistricts([...chosenDistricts, pickDistrict]);
                                    setPickDistrict('');
                                }}
                            >
                                Add
                            </Button>
                        </div>
                    </div>
                    <div className="space-y-2">
                        <p className="text-xs font-medium">Single thanas (beat a district rule)</p>
                        <div className="flex flex-wrap gap-1">
                            {chosenThanas.length === 0 && <span className="text-muted-foreground text-xs">None</span>}
                            {chosenThanas.map((t) => chip(t, () => setChosenThanas(chosenThanas.filter((x) => x !== t))))}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <SearchSelect
                                value={thanaDistrict}
                                onChange={(e) => {
                                    setThanaDistrict(e.target.value);
                                    setPickThana('');
                                }}
                            >
                                <option value="">District</option>
                                {districts.map((d) => (
                                    <option key={d} value={d}>
                                        {d}
                                    </option>
                                ))}
                            </SearchSelect>
                            <SearchSelect value={pickThana} disabled={!thanaDistrict} onChange={(e) => setPickThana(e.target.value)}>
                                <option value="">Thana</option>
                                {thanaOptions.map((t) => (
                                    <option key={t} value={t}>
                                        {t}
                                    </option>
                                ))}
                            </SearchSelect>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={!pickThana}
                                onClick={() => {
                                    const entry = `${thanaDistrict}/${pickThana}`;
                                    if (!chosenThanas.includes(entry)) setChosenThanas([...chosenThanas, entry]);
                                    setPickThana('');
                                }}
                            >
                                Add
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            <InputError
                message={errors.name ?? errors.band ?? Object.entries(errors).find(([k]) => k.startsWith('districts') || k.startsWith('thanas'))?.[1]}
            />

            <div className="flex gap-2">
                <Button type="button" size="sm" disabled={!name.trim() || saving} onClick={save}>
                    {band ? 'Save band' : 'Add band'}
                </Button>
                {band && !band.is_default && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            if (window.confirm(`Remove the band ${band.name}?`))
                                router.delete(route('couriers.settings.locations.bands.destroy', band.id), { preserveScroll: true });
                        }}
                    >
                        Remove
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function CourierLocations() {
    const { providers, provider, filter, search, stats, lastSync, maps, cities, bands, districts, errors } = usePage<PageProps>().props;
    const [editingId, setEditingId] = useState<number | null>(null);
    const [query, setQuery] = useState(search);
    const [busy, setBusy] = useState<string | null>(null);

    const current = providers.find((p) => p.code === provider);
    const running = lastSync?.status === 'running';

    // While a sync runs in the background, refresh the counts every few seconds.
    useEffect(() => {
        if (!running) return;

        const timer = window.setInterval(() => router.reload({ only: ['stats', 'lastSync', 'cities'] }), 5000);

        return () => window.clearInterval(timer);
    }, [running]);

    const go = (params: Record<string, string>) =>
        router.get(
            route('couriers.settings.locations.index'),
            { provider, filter, q: query, ...params },
            { preserveState: true, preserveScroll: true },
        );

    const post = (name: string, key: string) => {
        setBusy(key);
        router.post(route(name), { provider }, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    if (!current) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Courier locations" />
                <SettingsLayout>
                    <HeadingSmall title="Courier locations" description="No connected courier can list its locations yet." />
                </SettingsLayout>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Courier locations" />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <HeadingSmall
                            title="Courier locations"
                            description="Which of each courier's zones every thana is. A parcel is only ever booked to a zone shown here as In use: an equal name, or one a person approved. Nothing is guessed from the address text."
                        />
                        <Link href={route('couriers.settings.index')} className="text-sm underline underline-offset-4">
                            Courier accounts
                        </Link>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {providers.map((p) => (
                            <Button
                                key={p.code}
                                size="sm"
                                variant={p.code === provider ? 'default' : 'outline'}
                                onClick={() => router.get(route('couriers.settings.locations.index'), { provider: p.code })}
                            >
                                {p.name}
                            </Button>
                        ))}
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Stat
                            label="Synced from the courier"
                            value={stats.synced.zone}
                            hint={`${stats.synced.city} cities, ${stats.synced.zone} zones, ${stats.synced.area} areas`}
                        />
                        <Stat
                            label="Thanas in use"
                            value={`${stats.mapped} / ${stats.thanas}`}
                            hint={stats.area_maps > 0 ? `plus ${stats.area_maps} area maps` : undefined}
                            tone="good"
                        />
                        <Stat label="Waiting for review" value={stats.review} tone={stats.review > 0 ? 'warn' : undefined} />
                        <Stat label="No match yet" value={stats.unmapped} tone={stats.unmapped > 0 ? 'bad' : undefined} hint="Pick these by hand" />
                    </div>

                    <div className="border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                        <div className="text-sm">
                            {lastSync === null ? (
                                <p className="text-muted-foreground">
                                    Never synced. Sync reads the courier's city, zone and area lists; it changes nothing at the courier.
                                </p>
                            ) : lastSync.status === 'running' ? (
                                <p>
                                    Syncing since {lastSync.started_at}: {lastSync.cities} cities, {lastSync.zones} zones, {lastSync.areas} areas so
                                    far ({lastSync.calls} calls).
                                </p>
                            ) : lastSync.status === 'failed' ? (
                                <p className="text-destructive">
                                    The last sync stopped: {lastSync.last_error} Press Sync now to carry on from where it stopped.
                                </p>
                            ) : (
                                <p className="text-muted-foreground">
                                    Last synced {lastSync.finished_at}: {lastSync.cities} cities, {lastSync.zones} zones, {lastSync.areas} areas.
                                </p>
                            )}
                            {current.accounts.length === 0 && (
                                <p className="text-muted-foreground text-xs">
                                    Needs an active {current.name} account. Add, test and activate one on Courier accounts.
                                </p>
                            )}
                            <InputError message={errors.sync ?? errors.provider ?? errors.map} />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                disabled={current.accounts.length === 0 || running || busy !== null}
                                onClick={() => post('couriers.settings.locations.sync', 'sync')}
                            >
                                {busy === 'sync' ? 'Starting...' : running ? 'Syncing...' : 'Sync now'}
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={stats.synced.zone === 0 || busy !== null}
                                onClick={() => post('couriers.settings.locations.match', 'match')}
                            >
                                Match again
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={stats.unreviewed_high === 0 || busy !== null}
                                title="Records that a person agreed with every equal-name match nobody has looked at yet."
                                onClick={() => post('couriers.settings.locations.approve-high', 'high')}
                            >
                                Approve all high ({stats.unreviewed_high})
                            </Button>
                        </div>
                    </div>

                    <div className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex flex-wrap gap-1">
                                {FILTERS.map((f) => (
                                    <Button
                                        key={f.key}
                                        size="sm"
                                        variant={f.key === filter ? 'secondary' : 'ghost'}
                                        onClick={() => go({ filter: f.key })}
                                    >
                                        {f.label}
                                    </Button>
                                ))}
                            </div>
                            <form
                                className="flex gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    go({ q: query });
                                }}
                            >
                                <Input
                                    value={query}
                                    placeholder="District, thana or zone"
                                    aria-label="Search places"
                                    onChange={(e) => setQuery(e.target.value)}
                                    className="w-56"
                                />
                                <Button type="submit" size="sm" variant="outline">
                                    Search
                                </Button>
                            </form>
                        </div>

                        {maps.data.length === 0 ? (
                            <div className="border-border flex flex-col items-start gap-2 rounded-md border border-dashed p-6">
                                <MapPin className="text-muted-foreground size-6" />
                                <p className="font-medium">{stats.synced.zone === 0 ? 'Nothing synced yet' : 'Nothing here'}</p>
                                <p className="text-muted-foreground text-sm">
                                    {stats.synced.zone === 0
                                        ? 'Press Sync now. When the lists arrive, every thana gets a proposed zone: equal names are put in use, everything else waits here for you.'
                                        : 'No place matches this view.'}
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground text-left text-xs">
                                        <tr className="border-border border-b">
                                            <th className="py-2 pr-3 font-medium">Place</th>
                                            <th className="py-2 pr-3 font-medium">{current.name} city / zone / area</th>
                                            <th className="py-2 pr-3 font-medium">State</th>
                                            <th className="py-2 pr-3 font-medium">Why</th>
                                            <th className="py-2 font-medium" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {maps.data.map((row) => (
                                            <Fragment key={row.id}>
                                                <tr className="border-border border-b align-top">
                                                    <td className="py-2 pr-3">
                                                        <p className="font-medium">{row.thana}</p>
                                                        <p className="text-muted-foreground text-xs">
                                                            {row.area ? `${row.area}, ` : ''}
                                                            {row.district}
                                                        </p>
                                                    </td>
                                                    <td className="py-2 pr-3">
                                                        {row.zone_name ? (
                                                            <>
                                                                <p>{row.zone_name}</p>
                                                                <p className="text-muted-foreground text-xs">
                                                                    {row.city_name}
                                                                    {row.area_name ? `, area ${row.area_name}` : ''}
                                                                </p>
                                                            </>
                                                        ) : (
                                                            <p className="text-muted-foreground">
                                                                {row.city_name ? `${row.city_name}, zone not picked` : 'No city'}
                                                                {row.candidates.length > 0 ? ` (${row.candidates.length} possible)` : ''}
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="py-2 pr-3">
                                                        <ConfidenceBadge row={row} />
                                                        {row.reviewed_by && (
                                                            <p className="text-muted-foreground mt-1 text-xs">
                                                                {row.reviewed_by}, {row.reviewed_at}
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground py-2 pr-3 text-xs">{row.note}</td>
                                                    <td className="py-2">
                                                        <div className="flex justify-end gap-1">
                                                            {row.status === 'suggested' && row.zone_id && (
                                                                <Button
                                                                    size="sm"
                                                                    disabled={busy !== null}
                                                                    onClick={() => {
                                                                        setBusy(`approve-${row.id}`);
                                                                        router.post(
                                                                            route('couriers.settings.locations.approve', row.id),
                                                                            {},
                                                                            { preserveScroll: true, onFinish: () => setBusy(null) },
                                                                        );
                                                                    }}
                                                                >
                                                                    Approve
                                                                </Button>
                                                            )}
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => setEditingId(editingId === row.id ? null : row.id)}
                                                            >
                                                                {editingId === row.id ? 'Close' : row.zone_id ? 'Change' : 'Pick'}
                                                            </Button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                {editingId === row.id && (
                                                    <tr className="border-border border-b">
                                                        <td colSpan={5} className="py-2">
                                                            <MapEditor
                                                                provider={provider}
                                                                row={row}
                                                                cities={cities}
                                                                onDone={() => setEditingId(null)}
                                                            />
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {maps.last_page > 1 && (
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Page {maps.current_page} of {maps.last_page}, {maps.total} places
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={!maps.prev_page_url}
                                        onClick={() => maps.prev_page_url && router.get(maps.prev_page_url, {}, { preserveState: true })}
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={!maps.next_page_url}
                                        onClick={() => maps.next_page_url && router.get(maps.next_page_url, {}, { preserveState: true })}
                                    >
                                        Next
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="space-y-3">
                        <HeadingSmall
                            title={`${current.name} price bands`}
                            description="The courier's own bands, used later to estimate its fee. A single thana listed on a band beats a whole-district rule; the last band covers everywhere else."
                        />
                        <div className="space-y-3">
                            {bands.map((b) => (
                                <BandEditor
                                    key={`${b.id}-${b.name}-${b.districts.join()}-${b.thanas.join()}`}
                                    band={b}
                                    provider={provider}
                                    districts={districts}
                                />
                            ))}
                            <BandEditor band={null} provider={provider} districts={districts} />
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
