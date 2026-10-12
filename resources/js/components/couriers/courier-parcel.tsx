import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

// Courier hub slice 4: booking a challan through the courier's API, settling a
// booking whose answer was lost, and the courier's own timeline. None of this
// is money: what the courier reports is confirmed by a person on the outcome
// form, as before.

export interface CourierChoice {
    value: string;
    label: string;
}

export interface CourierAccountOption {
    id: number;
    label: string;
    provider: string;
    provider_name: string;
    environment: string;
    store_name: string | null;
    // ⭐ Joy, 11 Oct 2026: which brand's parcels the account carries here (null =
    // any), whether it serves THIS order's brand, and why not when it does not.
    serves_brand: string | null;
    available: boolean;
    unavailable_reason: string | null;
    preselected: boolean;
    delivery_types: CourierChoice[];
    item_types: CourierChoice[];
    default_delivery_type: string;
    default_item_type: string;
    max_weight_grams: number;
}

export interface CourierDestinationPreview {
    problems: string[];
    resolved: boolean;
    message: string | null;
    where: string | null;
}

export interface CourierDraft {
    cod_whole: number | null;
    due: string | null;
    due_has_paisa: boolean;
    description: string;
    destinations: Record<string, CourierDestinationPreview>;
}

export interface CourierBooking {
    id: number;
    status: 'requested' | 'unknown' | 'failed';
    needs_decision: boolean;
    account: string;
    provider_name: string;
    merchant_order_id: string;
    provider_consignment_id: string | null;
    error: string | null;
    at: string | null;
    can_look_up: boolean;
}

export interface CourierEvent {
    id: number;
    source: 'webhook' | 'poll' | 'booking';
    event: string;
    raw_status: string | null;
    status_label: string | null;
    applied: boolean;
    occurred_at: string | null;
}

export interface CourierPrompt {
    outcome: 'delivered' | 'partially_delivered' | 'returned' | 'paid_return';
    collected: string | null;
    message: string;
}

export interface CourierInfo {
    booked_via: 'api' | 'manual';
    courier_account: string | null;
    courier_status: string | null;
    courier_status_label: string | null;
    courier_status_raw: string | null;
    status_synced_at: string | null;
    provider_fee_quoted: string | null;
    provider_cod_fee_quoted: string | null;
    hub_weight_grams: number | null;
    courier_collected_amount: string | null;
    courier_invoice_ref: string | null;
    can_cancel: boolean;
    can_refresh: boolean;
    fee_estimate: { total: string | null; basis: string; confidence: string } | null;
    prompt: CourierPrompt | null;
    events: CourierEvent[];
}

export interface FeeEstimatePayload {
    sufficient: boolean;
    method: 'provider_quote' | 'rate_card' | 'historical' | 'insufficient';
    total: number | null;
    delivery_fee: number | null;
    cod_fee: number | null;
    confidence: 'high' | 'medium' | 'low' | 'none';
    confidence_label: string;
    basis: string;
    return_fee_scenario: number | null;
}

const confidenceClass: Record<FeeEstimatePayload['confidence'], string> = {
    high: 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/40',
    medium: 'border-sky-300 bg-sky-50 dark:border-sky-800 dark:bg-sky-950/40',
    low: 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40',
    none: 'border-border bg-muted/40',
};

/**
 * What the courier will charge us for this parcel, worked out again whenever
 * the account, weight, amount to collect or delivery type changes. Our COST:
 * never the customer's delivery charge.
 */
// Money in an estimate always has two decimals: Tk 117.70, never Tk 117.7 (QA walk, 11 Oct 2026).
function taka(amount: number): string {
    return amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function LiveFeeEstimate({
    challanId,
    accountId,
    weight,
    cod,
    deliveryType,
}: {
    challanId: number;
    accountId: string;
    weight: string;
    cod: string;
    deliveryType: string;
}) {
    const { t, tr } = useTranslation();
    const [estimate, setEstimate] = useState<FeeEstimatePayload | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (accountId === '') {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            setFailed(false);

            const params: Record<string, string> = { courier_account_id: accountId };
            if (/^\d+$/.test(weight.trim())) params.weight_grams = weight.trim();
            if (/^\d+(\.\d+)?$/.test(cod.trim())) params.cod_amount = cod.trim();
            if (deliveryType !== '') params.delivery_type = deliveryType;

            try {
                const response = await fetch(route('sales.consignments.estimate', { delivery: challanId, ...params }), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                setEstimate((await response.json()) as FeeEstimatePayload);
            } catch {
                if (!controller.signal.aborted) {
                    setFailed(true);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            }
        }, 400);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [challanId, accountId, weight, cod, deliveryType]);

    if (failed) {
        return <p className="text-muted-foreground text-xs">{t('parcel.estimate_failed')}</p>;
    }

    if (estimate === null) {
        return loading ? <p className="text-muted-foreground text-xs">{t('parcel.estimate_working')}</p> : null;
    }

    return (
        <div className={`rounded-md border p-2 text-xs ${confidenceClass[estimate.confidence]}`} aria-live="polite">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span className="font-medium">
                    {estimate.sufficient && estimate.total !== null
                        ? t('parcel.estimate_about', { amount: taka(estimate.total) })
                        : t('parcel.estimate_insufficient')}
                </span>
                {estimate.sufficient && (
                    <span className="text-muted-foreground">
                        {t('parcel.estimate_parts', {
                            delivery: taka(estimate.delivery_fee ?? 0),
                            cod: taka(estimate.cod_fee ?? 0),
                        })}
                    </span>
                )}
                <Badge variant="outline">
                    {t('parcel.estimate_confidence', { label: tr(`parcel.confidence.${estimate.confidence}`, estimate.confidence_label) })}
                </Badge>
                {loading && <span className="text-muted-foreground">{t('parcel.estimate_updating')}</span>}
            </div>
            <p className="text-muted-foreground mt-1">{estimate.basis}</p>
            {estimate.return_fee_scenario !== null && (
                <p className="text-muted-foreground mt-1">{t('parcel.estimate_return_fee', { amount: taka(estimate.return_fee_scenario) })}</p>
            )}
        </div>
    );
}

function selectClass() {
    return 'border-input bg-background h-9 rounded-md border px-2 text-sm';
}

/** Book a challan through a courier's API, with what the ERP would send shown first. */
export function ApiBookParcelForm({
    challanId,
    accounts,
    draft,
    weightSuggested,
    missingWeight,
}: {
    challanId: number;
    accounts: CourierAccountOption[];
    draft: CourierDraft;
    weightSuggested: number | null;
    missingWeight: number;
}) {
    const { t } = useTranslation();
    // The server ranks them: the brand's own default, its own link, the any-brand
    // default. An account kept for another brand is never chosen for the person.
    const first = accounts.find((a) => a.preselected) ?? accounts.find((a) => a.available);
    const refused = accounts.filter((a) => !a.available);
    const { data, setData, post, errors, processing } = useForm({
        courier_account_id: first ? String(first.id) : '',
        cod_amount: draft.cod_whole === null ? '' : String(draft.cod_whole),
        declared_weight_grams: weightSuggested === null ? '' : String(weightSuggested),
        delivery_type: first?.default_delivery_type ?? '',
        item_type: first?.default_item_type ?? '',
        description: draft.description,
        special_instruction: '',
    });

    const account = accounts.find((a) => String(a.id) === data.courier_account_id);
    const destination = account ? draft.destinations[String(account.id)] : undefined;
    const blocked = !destination || !destination.resolved || destination.problems.length > 0;
    const allErrors = errors as Record<string, string | undefined>;

    const pickAccount = (id: string) => {
        const next = accounts.find((a) => String(a.id) === id);
        setData((current) => ({
            ...current,
            courier_account_id: id,
            delivery_type: next?.default_delivery_type ?? '',
            item_type: next?.default_item_type ?? '',
        }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.consignments.book', challanId), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-3 flex flex-col gap-3">
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1">
                    <Label htmlFor={`api-account-${challanId}`} required>
                        {t('parcel.courier_account')}
                    </Label>
                    <select
                        id={`api-account-${challanId}`}
                        className={selectClass()}
                        value={data.courier_account_id}
                        onChange={(e) => pickAccount(e.target.value)}
                        aria-required
                    >
                        {accounts.map((a) => (
                            <option key={a.id} value={a.id} disabled={!a.available}>
                                {a.provider_name}: {a.label}
                                {a.serves_brand ? ` (${a.serves_brand})` : ''}
                                {a.environment === 'sandbox' ? t('parcel.sandbox') : ''}
                                {a.available ? '' : t('parcel.not_for_this_order')}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.courier_account_id} />
                    {refused.map((a) => (
                        <p key={a.id} className="text-muted-foreground max-w-80 text-xs">
                            {a.unavailable_reason}
                        </p>
                    ))}
                </div>
                <div className="grid gap-1">
                    <Label htmlFor={`api-cod-${challanId}`} required>
                        {t('parcel.amount_to_collect')}
                    </Label>
                    <Input
                        id={`api-cod-${challanId}`}
                        className="w-32"
                        inputMode="numeric"
                        value={data.cod_amount}
                        onChange={(e) => setData('cod_amount', e.target.value)}
                        aria-required
                        placeholder={t('parcel.zero_if_paid')}
                    />
                    <InputError message={errors.cod_amount} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor={`api-weight-${challanId}`} required={weightSuggested === null}>
                        {t('parcel.weight_g')}
                    </Label>
                    <Input
                        id={`api-weight-${challanId}`}
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={account?.max_weight_grams}
                        step={1}
                        className="w-28"
                        value={data.declared_weight_grams}
                        onChange={(e) => setData('declared_weight_grams', e.target.value)}
                        aria-required={weightSuggested === null}
                    />
                    <InputError message={errors.declared_weight_grams} />
                </div>
                {account && account.delivery_types.length > 0 && (
                    <div className="grid gap-1">
                        <Label htmlFor={`api-delivery-${challanId}`} required>
                            {t('parcel.delivery_type')}
                        </Label>
                        <select
                            id={`api-delivery-${challanId}`}
                            className={selectClass()}
                            value={data.delivery_type}
                            onChange={(e) => setData('delivery_type', e.target.value)}
                            aria-required
                        >
                            {account.delivery_types.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.delivery_type} />
                    </div>
                )}
                {account && account.item_types.length > 0 && (
                    <div className="grid gap-1">
                        <Label htmlFor={`api-item-${challanId}`} required>
                            {t('parcel.item_type')}
                        </Label>
                        <select
                            id={`api-item-${challanId}`}
                            className={selectClass()}
                            value={data.item_type}
                            onChange={(e) => setData('item_type', e.target.value)}
                            aria-required
                        >
                            {account.item_types.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.item_type} />
                    </div>
                )}
            </div>
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid min-w-64 flex-1 gap-1">
                    <Label htmlFor={`api-desc-${challanId}`}>{t('parcel.what_inside')}</Label>
                    <Input
                        id={`api-desc-${challanId}`}
                        maxLength={250}
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                    <InputError message={errors.description} />
                </div>
                <div className="grid min-w-64 flex-1 gap-1">
                    <Label htmlFor={`api-note-${challanId}`}>{t('parcel.rider_note')}</Label>
                    <Input
                        id={`api-note-${challanId}`}
                        maxLength={250}
                        placeholder={t('parcel.optional')}
                        value={data.special_instruction}
                        onChange={(e) => setData('special_instruction', e.target.value)}
                    />
                    <InputError message={errors.special_instruction} />
                </div>
            </div>

            <div className="text-xs">
                {account?.store_name && <p className="text-muted-foreground">{t('parcel.picked_up_from', { store: account.store_name })}</p>}
                {destination?.resolved && <p className="text-muted-foreground">{t('parcel.goes_to', { where: destination.where ?? '' })}</p>}
                {destination && !destination.resolved && destination.message && (
                    <p className="text-amber-700 dark:text-amber-400">
                        {destination.message}{' '}
                        <Link href={route('couriers.settings.locations.index')} className="underline">
                            {t('parcel.open_locations')}
                        </Link>
                    </p>
                )}
                {destination?.problems.map((problem) => (
                    <p key={problem} className="text-amber-700 dark:text-amber-400">
                        {problem}
                    </p>
                ))}
                {draft.due_has_paisa && (
                    <p className="text-amber-700 dark:text-amber-400">
                        {t('parcel.paisa_rounded', { due: draft.due ?? '', whole: draft.cod_whole ?? '' })}
                    </p>
                )}
                {missingWeight > 0 && <p className="text-amber-700 dark:text-amber-400">{t('parcel.missing_weight')}</p>}
                {['destination', 'recipient_phone', 'recipient_name', 'address', 'order'].map((key) =>
                    allErrors[key] ? <InputError key={key} message={allErrors[key]} /> : null,
                )}
            </div>

            <LiveFeeEstimate
                challanId={challanId}
                accountId={data.courier_account_id}
                weight={data.declared_weight_grams}
                cod={data.cod_amount}
                deliveryType={data.delivery_type}
            />

            <div>
                <Button size="sm" disabled={processing || blocked}>
                    {processing ? t('parcel.booking') : t('parcel.book_with', { courier: account?.provider_name ?? t('parcel.the_courier') })}
                </Button>
            </div>
        </form>
    );
}

/**
 * A booking with no clear answer. Never sent again by itself: the ERP asks the
 * courier for our reference (CarryBee), or a person says what the panel shows.
 */
export function SettleBookingPanel({ booking, canBook }: { booking: CourierBooking; canBook: boolean }) {
    const { t } = useTranslation();
    const [number, setNumber] = useState('');
    const [busy, setBusy] = useState(false);
    const post = (name: string, payload: Record<string, string> = {}) => {
        setBusy(true);
        router.post(route(name, booking.id), payload, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    if (booking.status === 'failed') {
        return (
            <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">
                {t('parcel.booking_refused', { courier: booking.provider_name, error: booking.error ?? '' })}
            </p>
        );
    }

    if (!booking.needs_decision) {
        return <p className="text-muted-foreground mt-2 text-xs">{t('parcel.booking_on_way', { courier: booking.provider_name })}</p>;
    }

    return (
        <div className="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs dark:border-amber-800 dark:bg-amber-950/40">
            <p className="font-medium">
                {t('parcel.no_clear_answer', { courier: booking.provider_name, order: booking.merchant_order_id, at: booking.at ?? '' })}
            </p>
            <p className="text-muted-foreground mt-1">
                {t('parcel.may_not_exist')}
                {booking.error ? ` ${booking.error}` : ''}
            </p>
            {canBook && (
                <div className="mt-2 flex flex-wrap items-end gap-2">
                    {booking.can_look_up ? (
                        <Button size="sm" variant="outline" disabled={busy} onClick={() => post('sales.courier-bookings.look-up')}>
                            {t('parcel.check_at', { courier: booking.provider_name })}
                        </Button>
                    ) : (
                        <>
                            <div className="grid gap-1">
                                <Label htmlFor={`settle-number-${booking.id}`} required>
                                    {t('parcel.number_in_panel', { courier: booking.provider_name })}
                                </Label>
                                <Input
                                    id={`settle-number-${booking.id}`}
                                    className="w-48"
                                    value={number}
                                    onChange={(e) => setNumber(e.target.value)}
                                    aria-required
                                />
                            </div>
                            <Button
                                size="sm"
                                disabled={busy || number.trim() === ''}
                                onClick={() => post('sales.courier-bookings.booked', { consignment_number: number })}
                            >
                                {t('parcel.it_was_booked')}
                            </Button>
                            {booking.provider_consignment_id === null && (
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm" variant="outline" disabled={busy}>
                                            {t('parcel.it_was_not_booked')}
                                        </Button>
                                    }
                                    title={t('parcel.not_booked_title')}
                                    description={t('parcel.not_booked_description', {
                                        order: booking.merchant_order_id,
                                        courier: booking.provider_name,
                                    })}
                                    confirmLabel={t('parcel.it_was_not_booked')}
                                    onConfirm={() => post('sales.courier-bookings.not-booked')}
                                />
                            )}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

/** What the courier says about a parcel, its fees, and its timeline. */
export function CourierStatusPanel({ consignmentId, info, canBook }: { consignmentId: number; info: CourierInfo; canBook: boolean }) {
    const { t, tr } = useTranslation();
    const [busy, setBusy] = useState(false);
    const [open, setOpen] = useState(false);

    const refresh = () => {
        setBusy(true);
        router.post(route('sales.consignments.refresh', consignmentId), {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const cancel = (reason: string) => router.post(route('sales.consignments.cancel', consignmentId), { reason }, { preserveScroll: true });

    if (info.booked_via === 'manual' && info.courier_status === null) {
        return null;
    }

    return (
        <div className="mt-2 text-xs">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground">{t('parcel.courier_says')}</span>
                <Badge variant="outline">
                    {info.courier_status_label !== null
                        ? tr(`courier_status.${info.courier_status ?? ''}`, info.courier_status_label)
                        : t('parcel.no_word_yet')}
                </Badge>
                {info.courier_status_raw && <span className="text-muted-foreground">({info.courier_status_raw})</span>}
                <span className="text-muted-foreground">
                    {info.status_synced_at ? t('parcel.last_heard', { at: info.status_synced_at }) : t('parcel.not_asked_yet')}
                    {info.provider_fee_quoted !== null && t('parcel.delivery_fee_part', { amount: info.provider_fee_quoted })}
                    {info.provider_cod_fee_quoted !== null && t('parcel.cod_fee_part', { amount: info.provider_cod_fee_quoted })}
                    {info.hub_weight_grams !== null && t('parcel.hub_weight_part', { grams: info.hub_weight_grams.toLocaleString('en-IN') })}
                    {info.courier_invoice_ref && t('parcel.courier_invoice_part', { ref: info.courier_invoice_ref })}
                </span>
                {info.can_refresh && (
                    <Button size="sm" variant="ghost" className="h-7 px-2" disabled={busy} onClick={refresh}>
                        {busy ? t('parcel.asking') : t('parcel.refresh_status')}
                    </Button>
                )}
                {info.can_cancel && canBook && (
                    <ConfirmDialog
                        trigger={
                            <Button size="sm" variant="ghost" className="h-7 px-2 text-red-600">
                                {t('parcel.cancel_at_courier')}
                            </Button>
                        }
                        title={t('parcel.cancel_title')}
                        description={t('parcel.cancel_description')}
                        confirmLabel={t('parcel.cancel_parcel')}
                        destructive
                        withNote
                        noteRequired
                        noteLabel={t('parcel.why')}
                        onConfirm={cancel}
                    />
                )}
                {info.events.length > 0 && (
                    <button type="button" className="text-muted-foreground underline" onClick={() => setOpen((v) => !v)}>
                        {open ? t('parcel.hide_timeline') : t('parcel.timeline', { count: info.events.length })}
                    </button>
                )}
            </div>
            {info.fee_estimate && <p className="text-muted-foreground mt-1">{t('parcel.estimate_kept', { basis: info.fee_estimate.basis })}</p>}
            {open && (
                <ol className="border-sidebar-border/70 mt-2 ml-1 flex flex-col gap-1 border-l pl-3">
                    {info.events.map((event) => (
                        <li key={event.id} className={event.applied ? '' : 'text-muted-foreground'}>
                            <span className="tabular-nums">{event.occurred_at}</span> {event.status_label ?? event.raw_status ?? event.event}
                            <span className="text-muted-foreground"> ({tr(`parcel.source.${event.source}`, event.source)}</span>
                            {!event.applied && event.status_label && <span className="text-muted-foreground">{t('parcel.event_late')}</span>}
                            {!event.status_label && <span className="text-muted-foreground">{t('parcel.event_no_change')}</span>}
                            <span className="text-muted-foreground">)</span>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
