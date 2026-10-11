import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useTranslation } from '@/lib/i18n';
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

const FILTERS: PageProps['filter'][] = ['review', 'unmapped', 'mapped', 'all'];

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
    const { t } = useTranslation();
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
                {applied
                    ? t('courier_locations.in_use')
                    : row.zone_id || row.candidates.length > 0
                      ? t('courier_locations.waiting_review')
                      : t('courier_locations.filter_unmapped')}
            </span>
            <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5">
                {t(`courier_locations.source_${row.source}`)}, {t(`courier_locations.confidence_${row.confidence}`)}
            </span>
        </div>
    );
}

/**
 * Pick the courier's city, zone and (optional) area for one place. Everything
 * offered comes from the synced list; the server checks it again on save.
 */
function MapEditor({ provider, row, cities, onDone }: { provider: string; row: MapRow; cities: Candidate[]; onDone: () => void }) {
    const { t } = useTranslation();
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
                setSuggestError(data === null ? t('courier_locations.carrybee_no_answer') : data.error);
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
                    <p className="text-xs font-medium">{t('courier_locations.zones_matching')}</p>
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
                        {t('courier_locations.area_of', { thana: row.thana })}
                    </label>
                    <SearchSelect
                        id={`place-area-${row.id}`}
                        value={placeArea}
                        onChange={(e) => setPlaceArea(e.target.value)}
                        disabled={row.area !== null}
                    >
                        <option value="">{t('courier_locations.whole_thana')}</option>
                        {placeAreas.map((a) => (
                            <option key={a} value={a}>
                                {a}
                            </option>
                        ))}
                    </SearchSelect>
                </div>
                <div className="space-y-1">
                    <label className="text-xs font-medium" htmlFor={`city-${row.id}`}>
                        {t('courier_locations.courier_city')}
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
                        <option value="">{t('courier_locations.choose_city')}</option>
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
                        {t('courier_locations.courier_zone')}
                    </label>
                    <SearchSelect
                        id={`zone-${row.id}`}
                        value={zoneId}
                        disabled={!cityId || zones.loading}
                        invalid={!!errors.zone_id}
                        emptyText={t('courier_locations.no_zone_by_name')}
                        onChange={(e) => {
                            setZoneId(e.target.value);
                            setAreaId('');
                        }}
                    >
                        <option value="">{zones.loading ? t('courier_locations.loading') : t('courier_locations.choose_zone')}</option>
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
                        {t('courier_locations.courier_area')}
                    </label>
                    <SearchSelect
                        id={`area-${row.id}`}
                        value={areaId}
                        disabled={!zoneId || areas.loading}
                        invalid={!!errors.area_id}
                        onChange={(e) => setAreaId(e.target.value)}
                    >
                        <option value="">{areas.loading ? t('courier_locations.loading') : t('courier_locations.no_area')}</option>
                        {areas.items.map((a) => (
                            <option
                                key={a.id}
                                value={a.id}
                                data-hint={a.home_delivery === false ? t('courier_locations.no_home_delivery') : undefined}
                            >
                                {a.name}
                            </option>
                        ))}
                    </SearchSelect>
                    {selectedArea?.home_delivery === false && (
                        <p className="text-destructive text-xs">{t('courier_locations.no_home_delivery_long')}</p>
                    )}
                    <InputError message={errors.area_id} />
                </div>
            </div>

            {provider === 'carrybee' && (
                <div className="space-y-1">
                    <Button type="button" size="sm" variant="outline" onClick={askCarryBee}>
                        {t('courier_locations.ask_carrybee', { name: placeArea || row.thana })}
                    </Button>
                    {suggestError && <p className="text-muted-foreground text-xs">{suggestError}</p>}
                    {suggestions !== null && suggestions.length === 0 && !suggestError && (
                        <p className="text-muted-foreground text-xs">{t('courier_locations.carrybee_no_suggestion')}</p>
                    )}
                    {suggestions !== null && suggestions.length > 0 && (
                        <div className="space-y-1">
                            <p className="text-muted-foreground text-xs">{t('courier_locations.carrybee_hint')}</p>
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
                    {saving ? t('courier_locations.saving') : t('courier_locations.save_and_use')}
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={onDone}>
                    {t('courier_locations.cancel')}
                </Button>
            </div>
        </div>
    );
}

/** One courier band: its name and the districts and thanas it covers. */
function BandEditor({ band, provider, districts }: { band: Band | null; provider: string; districts: string[] }) {
    const { t } = useTranslation();
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
            <button
                type="button"
                className="text-muted-foreground hover:text-foreground"
                aria-label={t('courier_locations.remove_item', { name: label })}
                onClick={onRemove}
            >
                x
            </button>
        </span>
    );

    return (
        <div className="border-border space-y-3 rounded-md border p-3">
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-48 flex-1 space-y-1">
                    <label className="text-xs font-medium" htmlFor={`band-name-${band?.id ?? 'new'}`}>
                        {band ? t('courier_locations.band_name') : t('courier_locations.new_band_name')}
                    </label>
                    <Input id={`band-name-${band?.id ?? 'new'}`} value={name} maxLength={120} onChange={(e) => setName(e.target.value)} />
                </div>
                {band?.is_default && <p className="text-muted-foreground text-xs">{t('courier_locations.default_band_note')}</p>}
            </div>

            {!band?.is_default && (
                <div className="grid gap-3 md:grid-cols-2">
                    <div className="space-y-2">
                        <p className="text-xs font-medium">{t('courier_locations.whole_districts')}</p>
                        <div className="flex flex-wrap gap-1">
                            {chosenDistricts.length === 0 && <span className="text-muted-foreground text-xs">{t('courier_locations.none')}</span>}
                            {chosenDistricts.map((d) => chip(d, () => setChosenDistricts(chosenDistricts.filter((x) => x !== d))))}
                        </div>
                        <div className="flex gap-2">
                            <SearchSelect value={pickDistrict} onChange={(e) => setPickDistrict(e.target.value)}>
                                <option value="">{t('courier_locations.add_district')}</option>
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
                                {t('courier_locations.add')}
                            </Button>
                        </div>
                    </div>
                    <div className="space-y-2">
                        <p className="text-xs font-medium">{t('courier_locations.single_thanas')}</p>
                        <div className="flex flex-wrap gap-1">
                            {chosenThanas.length === 0 && <span className="text-muted-foreground text-xs">{t('courier_locations.none')}</span>}
                            {chosenThanas.map((th) => chip(th, () => setChosenThanas(chosenThanas.filter((x) => x !== th))))}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <SearchSelect
                                value={thanaDistrict}
                                onChange={(e) => {
                                    setThanaDistrict(e.target.value);
                                    setPickThana('');
                                }}
                            >
                                <option value="">{t('courier_locations.district')}</option>
                                {districts.map((d) => (
                                    <option key={d} value={d}>
                                        {d}
                                    </option>
                                ))}
                            </SearchSelect>
                            <SearchSelect value={pickThana} disabled={!thanaDistrict} onChange={(e) => setPickThana(e.target.value)}>
                                <option value="">{t('courier_locations.thana')}</option>
                                {thanaOptions.map((th) => (
                                    <option key={th} value={th}>
                                        {th}
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
                                {t('courier_locations.add')}
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
                    {band ? t('courier_locations.save_band') : t('courier_locations.add_band')}
                </Button>
                {band && !band.is_default && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            if (window.confirm(t('courier_locations.confirm_remove_band', { name: band.name })))
                                router.delete(route('couriers.settings.locations.bands.destroy', band.id), { preserveScroll: true });
                        }}
                    >
                        {t('courier_locations.remove')}
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function CourierLocations() {
    const { t } = useTranslation();
    const { providers, provider, filter, search, stats, lastSync, maps, cities, bands, districts, errors } = usePage<PageProps>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('courier_locations.breadcrumb_couriers'), href: '/settings/couriers' },
        { title: t('courier_locations.breadcrumb_locations'), href: '/settings/couriers/locations' },
    ];
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
                <Head title={t('courier_locations.title')} />
                <SettingsLayout>
                    <HeadingSmall title={t('courier_locations.title')} description={t('courier_locations.no_provider')} />
                </SettingsLayout>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('courier_locations.title')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <HeadingSmall title={t('courier_locations.title')} description={t('courier_locations.description')} />
                        <Link href={route('couriers.settings.index')} className="text-sm underline underline-offset-4">
                            {t('courier_locations.courier_accounts')}
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
                            label={t('courier_locations.stat_synced')}
                            value={stats.synced.zone}
                            hint={t('courier_locations.stat_synced_hint', {
                                cities: stats.synced.city,
                                zones: stats.synced.zone,
                                areas: stats.synced.area,
                            })}
                        />
                        <Stat
                            label={t('courier_locations.stat_in_use')}
                            value={`${stats.mapped} / ${stats.thanas}`}
                            hint={stats.area_maps > 0 ? t('courier_locations.stat_area_maps', { count: stats.area_maps }) : undefined}
                            tone="good"
                        />
                        <Stat label={t('courier_locations.waiting_review')} value={stats.review} tone={stats.review > 0 ? 'warn' : undefined} />
                        <Stat
                            label={t('courier_locations.stat_no_match')}
                            value={stats.unmapped}
                            tone={stats.unmapped > 0 ? 'bad' : undefined}
                            hint={t('courier_locations.stat_pick_hand')}
                        />
                    </div>

                    <div className="border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                        <div className="text-sm">
                            {lastSync === null ? (
                                <p className="text-muted-foreground">{t('courier_locations.never_synced')}</p>
                            ) : lastSync.status === 'running' ? (
                                <p>
                                    {t('courier_locations.syncing_since', {
                                        at: lastSync.started_at ?? '',
                                        cities: lastSync.cities,
                                        zones: lastSync.zones,
                                        areas: lastSync.areas,
                                        calls: lastSync.calls,
                                    })}
                                </p>
                            ) : lastSync.status === 'failed' ? (
                                <p className="text-destructive">{t('courier_locations.sync_failed', { error: lastSync.last_error ?? '' })}</p>
                            ) : (
                                <p className="text-muted-foreground">
                                    {t('courier_locations.last_synced', {
                                        at: lastSync.finished_at ?? '',
                                        cities: lastSync.cities,
                                        zones: lastSync.zones,
                                        areas: lastSync.areas,
                                    })}
                                </p>
                            )}
                            {current.accounts.length === 0 && (
                                <p className="text-muted-foreground text-xs">{t('courier_locations.needs_account', { name: current.name })}</p>
                            )}
                            <InputError message={errors.sync ?? errors.provider ?? errors.map} />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                disabled={current.accounts.length === 0 || running || busy !== null}
                                onClick={() => post('couriers.settings.locations.sync', 'sync')}
                            >
                                {busy === 'sync'
                                    ? t('courier_locations.starting')
                                    : running
                                      ? t('courier_locations.syncing')
                                      : t('courier_locations.sync_now')}
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={stats.synced.zone === 0 || busy !== null}
                                onClick={() => post('couriers.settings.locations.match', 'match')}
                            >
                                {t('courier_locations.match_again')}
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={stats.unreviewed_high === 0 || busy !== null}
                                title={t('courier_locations.approve_high_title')}
                                onClick={() => post('couriers.settings.locations.approve-high', 'high')}
                            >
                                {t('courier_locations.approve_high', { count: stats.unreviewed_high })}
                            </Button>
                        </div>
                    </div>

                    <div className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex flex-wrap gap-1">
                                {FILTERS.map((f) => (
                                    <Button key={f} size="sm" variant={f === filter ? 'secondary' : 'ghost'} onClick={() => go({ filter: f })}>
                                        {t(`courier_locations.filter_${f}`)}
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
                                    placeholder={t('courier_locations.search_placeholder')}
                                    aria-label={t('courier_locations.search_label')}
                                    onChange={(e) => setQuery(e.target.value)}
                                    className="w-56"
                                />
                                <Button type="submit" size="sm" variant="outline">
                                    {t('courier_locations.search')}
                                </Button>
                            </form>
                        </div>

                        {maps.data.length === 0 ? (
                            <div className="border-border flex flex-col items-start gap-2 rounded-md border border-dashed p-6">
                                <MapPin className="text-muted-foreground size-6" />
                                <p className="font-medium">
                                    {stats.synced.zone === 0 ? t('courier_locations.nothing_synced') : t('courier_locations.nothing_here')}
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    {stats.synced.zone === 0 ? t('courier_locations.nothing_synced_help') : t('courier_locations.no_place_matches')}
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                {/* Below sm each place is a card: the actions sit under it, never off the side (QA walk, 11 Oct 2026). */}
                                <table className="w-full text-sm max-sm:block">
                                    <thead className="text-muted-foreground text-left text-xs max-sm:hidden">
                                        <tr className="border-border border-b max-sm:block">
                                            <th className="py-2 pr-3 font-medium">{t('courier_locations.col_place')}</th>
                                            <th className="py-2 pr-3 font-medium">
                                                {t('courier_locations.col_courier_place', { name: current.name })}
                                            </th>
                                            <th className="py-2 pr-3 font-medium">{t('courier_locations.col_state')}</th>
                                            <th className="py-2 pr-3 font-medium">{t('courier_locations.col_why')}</th>
                                            <th className="py-2 font-medium" />
                                        </tr>
                                    </thead>
                                    <tbody className="max-sm:block">
                                        {maps.data.map((row) => (
                                            <Fragment key={row.id}>
                                                <tr className="border-border border-b align-top max-sm:block max-sm:py-2">
                                                    <td className="py-2 pr-3 max-sm:block max-sm:py-1">
                                                        <p className="font-medium">{row.thana}</p>
                                                        <p className="text-muted-foreground text-xs">
                                                            {row.area ? `${row.area}, ` : ''}
                                                            {row.district}
                                                        </p>
                                                    </td>
                                                    <td className="py-2 pr-3 max-sm:block max-sm:py-1">
                                                        {row.zone_name ? (
                                                            <>
                                                                <p>{row.zone_name}</p>
                                                                <p className="text-muted-foreground text-xs">
                                                                    {row.city_name}
                                                                    {row.area_name
                                                                        ? `, ${t('courier_locations.area_named', { name: row.area_name })}`
                                                                        : ''}
                                                                </p>
                                                            </>
                                                        ) : (
                                                            <p className="text-muted-foreground">
                                                                {row.city_name
                                                                    ? t('courier_locations.zone_not_picked', { city: row.city_name })
                                                                    : t('courier_locations.no_city')}
                                                                {row.candidates.length > 0
                                                                    ? ` (${t('courier_locations.possible', { count: row.candidates.length })})`
                                                                    : ''}
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="py-2 pr-3 max-sm:block max-sm:py-1">
                                                        <ConfidenceBadge row={row} />
                                                        {row.reviewed_by && (
                                                            <p className="text-muted-foreground mt-1 text-xs">
                                                                {row.reviewed_by}, {row.reviewed_at}
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground py-2 pr-3 text-xs max-sm:block max-sm:py-1">{row.note}</td>
                                                    <td className="py-2 max-sm:block max-sm:py-1">
                                                        <div className="flex justify-end gap-1 max-sm:justify-start">
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
                                                                    {t('courier_locations.approve')}
                                                                </Button>
                                                            )}
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => setEditingId(editingId === row.id ? null : row.id)}
                                                            >
                                                                {editingId === row.id
                                                                    ? t('courier_locations.close')
                                                                    : row.zone_id
                                                                      ? t('courier_locations.change')
                                                                      : t('courier_locations.pick')}
                                                            </Button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                {editingId === row.id && (
                                                    <tr className="border-border border-b">
                                                        <td colSpan={5} className="py-2 max-sm:block">
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
                                    {t('courier_locations.page_of', { page: maps.current_page, last: maps.last_page, total: maps.total })}
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={!maps.prev_page_url}
                                        onClick={() => maps.prev_page_url && router.get(maps.prev_page_url, {}, { preserveState: true })}
                                    >
                                        {t('courier_locations.previous')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={!maps.next_page_url}
                                        onClick={() => maps.next_page_url && router.get(maps.next_page_url, {}, { preserveState: true })}
                                    >
                                        {t('courier_locations.next')}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="space-y-3">
                        <HeadingSmall
                            title={t('courier_locations.bands_title', { name: current.name })}
                            description={t('courier_locations.bands_description')}
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
