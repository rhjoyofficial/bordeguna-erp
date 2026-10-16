import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

export interface ActivityTypeOption {
    value: string;
    label: string;
    has_direction: boolean;
    has_duration: boolean;
}

type Located = { latitude: number; longitude: number; accuracy: number };

type ActivityForm = {
    type: string;
    direction: string;
    occurred_at: string;
    summary: string;
    body: string;
    outcome: string;
    duration_seconds: string;
    latitude: number | null;
    longitude: number | null;
    gps_accuracy_meters: number | null;
    photos: File[];
};

const EMPTY: ActivityForm = {
    type: 'call',
    direction: 'outbound',
    occurred_at: '',
    summary: '',
    body: '',
    outcome: '',
    duration_seconds: '',
    latitude: null,
    longitude: null,
    gps_accuracy_meters: null,
    photos: [],
};

/**
 * Record what happened with a lead or a customer. Append-only once saved.
 *
 * ⭐ CRM slice 2d (Joy's answer 2): a VISIT may carry a location from the phone
 * and photos, both optional. Nothing waits for either, and no other kind of
 * activity offers them.
 *
 * ⚠ The location is taken only when the officer presses the button, the same
 * way the attendance clock asks, so the ERP never reads a position nobody chose
 * to share.
 */
export function ActivityLogForm({
    subject,
    activityTypes,
    directions,
    preset = null,
}: {
    subject: { kind: string; id: number };
    activityTypes: ActivityTypeOption[];
    directions: { value: string; label: string }[];
    /**
     * ⭐ Round 3 (16 Oct 2026, decision 4): the lead page's WhatsApp button
     * opens WhatsApp AND sets this form to WhatsApp, so logging what was said is
     * one tap. Nothing is logged by itself. A new `nonce` re-applies the kind.
     */
    preset?: { type: string; nonce: number } | null;
}) {
    const photoInput = useRef<HTMLInputElement>(null);
    const summaryInput = useRef<HTMLInputElement>(null);
    const [locating, setLocating] = useState(false);
    const [locationError, setLocationError] = useState<string | null>(null);
    const log = useForm<ActivityForm>(EMPTY);

    useEffect(() => {
        if (!preset) return;
        log.setData('type', preset.type);
        summaryInput.current?.focus({ preventScroll: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [preset?.nonce]);

    const chosen = activityTypes.find((t) => t.value === log.data.type);
    const isVisit = log.data.type === 'visit';
    const located: Located | null =
        log.data.latitude !== null && log.data.longitude !== null && log.data.gps_accuracy_meters !== null
            ? { latitude: log.data.latitude, longitude: log.data.longitude, accuracy: log.data.gps_accuracy_meters }
            : null;

    // ⚠ Proof leaves the browser only with a visit. A location taken for a visit
    // and then left in the form when the kind changes to a call is dropped here,
    // and dropped again on the server.
    log.transform((d) => (d.type === 'visit' ? d : { ...d, latitude: null, longitude: null, gps_accuracy_meters: null, photos: [] }));

    const clearProof = () => {
        log.setData((d) => ({ ...d, latitude: null, longitude: null, gps_accuracy_meters: null, photos: [] }));
        if (photoInput.current) photoInput.current.value = '';
        setLocationError(null);
    };

    const addLocation = () => {
        setLocationError(null);

        if (!navigator.geolocation) {
            setLocationError('This browser cannot share a location. Record the visit without it.');
            return;
        }

        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                setLocating(false);
                log.setData((d) => ({
                    ...d,
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    gps_accuracy_meters: Math.round(position.coords.accuracy * 100) / 100,
                }));
            },
            (error) => {
                setLocating(false);
                setLocationError(
                    error.code === error.PERMISSION_DENIED
                        ? 'Location is blocked for this site. Allow it in the browser, or record the visit without it.'
                        : 'The phone could not find its location. Try again outside, or record the visit without it.',
                );
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        log.post(`/crm/${subject.kind}/${subject.id}/activities`, {
            preserveScroll: true,
            forceFormData: isVisit && log.data.photos.length > 0,
            onSuccess: () => {
                log.reset();
                clearProof();
            },
        });
    };

    const errors = log.errors as Record<string, string | undefined>;
    const photoError = Object.entries(errors).find(([key]) => key.startsWith('photos'))?.[1];

    return (
        <form id="log-activity" onSubmit={submit} className="bg-card flex scroll-mt-20 flex-col gap-3 rounded-xl border p-4">
            <h2 className="text-[15px] font-semibold">Log what happened</h2>
            {/* The kind as chips: one tap, and the officer sees every choice. */}
            <div role="group" aria-label="Kind" className="flex flex-wrap gap-1.5">
                {activityTypes.map((t) => (
                    <button
                        key={t.value}
                        type="button"
                        aria-pressed={log.data.type === t.value}
                        onClick={() => log.setData('type', t.value)}
                        className={cn(
                            'h-8 rounded-full border px-3 text-[12.5px] whitespace-nowrap transition-colors',
                            log.data.type === t.value ? 'border-foreground bg-foreground text-background' : 'bg-card hover:bg-muted',
                        )}
                    >
                        {t.label}
                    </button>
                ))}
            </div>
            <div className="flex flex-wrap items-end gap-3">
                {/* ⚠ A note has no direction and only a call has a
                    duration. Hidden rather than stored and ignored. */}
                {chosen?.has_direction && (
                    <div className="grid gap-2">
                        <Label htmlFor="direction">Direction</Label>
                        <SearchSelect fit id="direction" value={log.data.direction} onChange={(e) => log.setData('direction', e.target.value)}>
                            {directions.map((d) => (
                                <option key={d.value} value={d.value}>
                                    {d.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                )}
                {chosen?.has_duration && (
                    <div className="grid gap-2">
                        <Label htmlFor="duration_seconds">Seconds</Label>
                        <Input
                            id="duration_seconds"
                            className="w-24"
                            inputMode="numeric"
                            value={log.data.duration_seconds}
                            onChange={(e) => log.setData('duration_seconds', e.target.value)}
                        />
                    </div>
                )}
                <div className="grid gap-2">
                    {/* ⚠⚠ When it HAPPENED, not when it was typed. */}
                    <Label htmlFor="occurred_at">When</Label>
                    <Input
                        id="occurred_at"
                        type="datetime-local"
                        value={log.data.occurred_at}
                        onChange={(e) => log.setData('occurred_at', e.target.value)}
                    />
                    <InputError message={log.errors.occurred_at} />
                </div>
            </div>
            <div className="grid gap-2">
                <Label htmlFor="summary" required>
                    What happened
                </Label>
                <Input
                    ref={summaryInput}
                    id="summary"
                    aria-required
                    value={log.data.summary}
                    onChange={(e) => log.setData('summary', e.target.value)}
                    placeholder="Wants 5kg, will confirm Thursday"
                />
                <InputError message={log.errors.summary} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="body">Detail (optional)</Label>
                <textarea
                    id="body"
                    className="border-input bg-background min-h-20 rounded-md border p-2 text-sm"
                    value={log.data.body}
                    onChange={(e) => log.setData('body', e.target.value)}
                />
            </div>

            {isVisit && (
                <div className="flex flex-col gap-3 rounded-md border border-dashed p-3">
                    <div className="flex flex-wrap items-center gap-3">
                        {located ? (
                            <>
                                <span className="flex items-center gap-1 text-sm">
                                    <MapPin className="size-4" />
                                    Location added, within {Math.round(located.accuracy)} m
                                </span>
                                <a
                                    className="text-sm underline"
                                    href={`https://www.google.com/maps/search/?api=1&query=${located.latitude},${located.longitude}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Check on the map
                                </a>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => log.setData((d) => ({ ...d, latitude: null, longitude: null, gps_accuracy_meters: null }))}
                                >
                                    Remove
                                </Button>
                            </>
                        ) : (
                            <Button type="button" variant="outline" onClick={addLocation} disabled={locating}>
                                <MapPin className="size-4" />
                                {locating ? 'Finding your location…' : 'Add my location'}
                            </Button>
                        )}
                    </div>
                    <InputError message={locationError ?? errors.latitude ?? errors.longitude ?? errors.gps_accuracy_meters} />
                    <div className="grid gap-2">
                        <Label htmlFor="visit_photos">Photos (up to 6)</Label>
                        <input
                            ref={photoInput}
                            id="visit_photos"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            className="w-full min-w-0 text-sm"
                            onChange={(e) => log.setData('photos', Array.from(e.target.files ?? []))}
                        />
                        <InputError message={photoError} />
                    </div>
                </div>
            )}

            <div>
                <Button disabled={log.processing}>Save</Button>
            </div>
        </form>
    );
}
