import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dhakaToday } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

// Courier hub slice 8: settling what a courier reports beyond a plain
// delivery. A difference at the door (ruling 3), a lost or damaged parcel and
// its claim (ruling 5), and a courier answer posted after a closed month
// (risk 9). The page props say who may do what; the server checks again.

export interface LossClaimInfo {
    id: number;
    kind: 'lost' | 'damaged';
    kind_label: string;
    status: 'open' | 'accepted' | 'paid' | 'rejected';
    status_label: string;
    value_at_cost: string;
    claimed_amount: string;
    compensation_received: string;
    notes: string | null;
    closed: boolean;
    paid_on_statement: boolean;
    adjustments: { id: number; quantity: string; status: string | null }[];
}

export interface ParcelSettlement {
    posted_on: string | null;
    cutoff_note: string | null;
    door_resolution: string | null;
    door_resolution_label: string | null;
    door_difference: string | null;
    door_reason: string | null;
    loss_claim: LossClaimInfo | null;
    waiting_discount: { id: number; discount: string; reason: string; requested_by: string | null } | null;
}

interface SettlementProps {
    door_resolutions: { value: string; label: string }[];
    can_correct_price: boolean;
    can_report_loss: boolean;
    can_settle_claims: boolean;
    books_open_from: string | null;
    money_accounts: { id: number; name: string; type: string }[];
    [key: string]: unknown;
}

const useSettlementProps = () => usePage<SettlementProps>().props;

const selectClass = 'border-input bg-background h-9 rounded-md border px-2 text-sm';

/** A date before the books open posts on the first open day: say so before it is pressed. */
export function ClosedPeriodHint({ date }: { date: string }) {
    const { t } = useTranslation();
    const { books_open_from } = useSettlementProps();

    if (!books_open_from || !date || date >= books_open_from) {
        return null;
    }

    return <p className="text-xs text-amber-700 dark:text-amber-400">{t('parcel.books_closed', { open: books_open_from, date })}</p>;
}

export type DoorFields = {
    resolution: string;
    door_reason: string;
    prices: { delivery_line_id: number; unit_price: string }[];
};

/** How a difference between what was owed and what was collected is settled. */
export function DoorResolutionFields({
    data,
    onChange,
    lines,
    errors,
}: {
    data: DoorFields;
    onChange: (patch: Partial<DoorFields>) => void;
    lines: { id: number; product: string | null; sku: string | null; quantity: string }[];
    errors: Record<string, string | undefined>;
}) {
    const { t, tr } = useTranslation();
    const { door_resolutions, can_correct_price } = useSettlementProps();
    const options = door_resolutions.filter((option) => option.value !== 'price_correction' || can_correct_price);

    const setPrice = (id: number, unit_price: string) =>
        onChange({
            prices: lines.map((line) => ({
                delivery_line_id: line.id,
                unit_price: line.id === id ? unit_price : (data.prices.find((entry) => entry.delivery_line_id === line.id)?.unit_price ?? ''),
            })),
        });

    return (
        <div className="flex flex-col gap-2 rounded-md border border-dashed p-2">
            <p className="text-muted-foreground text-xs">{t('parcel.door_help')}</p>
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="door-resolution">{t('parcel.if_amount_differs')}</Label>
                    <select
                        id="door-resolution"
                        className={selectClass}
                        value={data.resolution}
                        onChange={(e) => onChange({ resolution: e.target.value })}
                    >
                        <option value="">{t('parcel.it_matches')}</option>
                        {options.map((option) => (
                            <option key={option.value} value={option.value}>
                                {tr(`parcel.door.${option.value}`, option.label)}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.resolution} />
                </div>
                {data.resolution !== '' && (
                    <div className="grid flex-1 gap-1">
                        <Label htmlFor="door-reason" required>
                            {t('parcel.why')}
                        </Label>
                        <Input
                            id="door-reason"
                            className="max-w-md"
                            value={data.door_reason}
                            onChange={(e) => onChange({ door_reason: e.target.value })}
                            aria-required
                        />
                        <InputError message={errors.door_reason} />
                    </div>
                )}
            </div>
            {data.resolution === 'price_correction' && (
                <table className="w-full max-w-xl text-sm">
                    <thead>
                        <tr className="text-muted-foreground text-left">
                            <th className="py-1">{t('parcel.product')}</th>
                            <th className="py-1">{t('parcel.quantity')}</th>
                            <th className="py-1">{t('parcel.corrected_unit_price')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((line) => (
                            <tr key={line.id}>
                                <td className="py-1">{line.product}</td>
                                <td className="py-1 tabular-nums">{formatQuantity(line.quantity)}</td>
                                <td className="py-1">
                                    <Input
                                        className="w-28"
                                        inputMode="decimal"
                                        placeholder={t('parcel.unchanged')}
                                        value={data.prices.find((entry) => entry.delivery_line_id === line.id)?.unit_price ?? ''}
                                        onChange={(e) => setPrice(line.id, e.target.value)}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            <InputError message={errors.prices} />
        </div>
    );
}

/** The courier lost or damaged the parcel: closes it and opens a claim. */
export function ReportLossForm({ consignmentId, codAmount }: { consignmentId: number; codAmount: string }) {
    const { t } = useTranslation();
    const { can_report_loss } = useSettlementProps();
    const [open, setOpen] = useState(false);
    const { data, setData, post, errors, processing } = useForm({
        kind: 'lost',
        reported_on: dhakaToday(),
        claimed_amount: codAmount,
        notes: '',
    });

    if (!can_report_loss) {
        return null;
    }

    if (!open) {
        return (
            <button type="button" className="text-muted-foreground mt-2 text-xs underline" onClick={() => setOpen(true)}>
                {t('parcel.report_loss')}
            </button>
        );
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.consignments.loss', consignmentId), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-3 flex flex-col gap-2 rounded-md border border-rose-200 p-2 dark:border-rose-900">
            <p className="text-muted-foreground text-xs">{t('parcel.loss_help')}</p>
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1">
                    <Label htmlFor={`loss-kind-${consignmentId}`} required>
                        {t('parcel.what_happened')}
                    </Label>
                    <select
                        id={`loss-kind-${consignmentId}`}
                        className={selectClass}
                        value={data.kind}
                        onChange={(e) => setData('kind', e.target.value)}
                        aria-required
                    >
                        <option value="lost">{t('parcel.lost_by_courier')}</option>
                        <option value="damaged">{t('parcel.damaged_by_courier')}</option>
                    </select>
                    <InputError message={errors.kind} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor={`loss-on-${consignmentId}`} required>
                        {t('parcel.reported_on')}
                    </Label>
                    <Input
                        id={`loss-on-${consignmentId}`}
                        type="date"
                        className="w-40"
                        value={data.reported_on}
                        onChange={(e) => setData('reported_on', e.target.value)}
                        aria-required
                    />
                    <InputError message={errors.reported_on} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor={`loss-claim-${consignmentId}`} required>
                        {t('parcel.claim_from_courier')}
                    </Label>
                    <Input
                        id={`loss-claim-${consignmentId}`}
                        className="w-32"
                        inputMode="decimal"
                        value={data.claimed_amount}
                        onChange={(e) => setData('claimed_amount', e.target.value)}
                        aria-required
                    />
                    <InputError message={errors.claimed_amount} />
                </div>
            </div>
            <ClosedPeriodHint date={data.reported_on} />
            <div className="flex items-center gap-2">
                <Input
                    className="max-w-sm"
                    placeholder={t('parcel.notes_optional')}
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                />
                <Button size="sm" variant="destructive" disabled={processing}>
                    {t('parcel.record_open_claim')}
                </Button>
                <Button size="sm" type="button" variant="ghost" onClick={() => setOpen(false)}>
                    {t('parcel.cancel')}
                </Button>
            </div>
        </form>
    );
}

function ClaimActions({ claim }: { claim: LossClaimInfo }) {
    const { t } = useTranslation();
    const { money_accounts } = useSettlementProps();
    const decision = useForm({ decision: 'accepted', accepted_amount: claim.claimed_amount, note: '' });
    const money = useForm({
        money_account_id: money_accounts.length === 1 ? String(money_accounts[0].id) : '',
        amount: claim.claimed_amount,
        received_on: dhakaToday(),
        reference: '',
    });

    const decide: FormEventHandler = (e) => {
        e.preventDefault();
        decision.post(route('sales.courier-claims.decide', claim.id), { preserveScroll: true });
    };

    const receive: FormEventHandler = (e) => {
        e.preventDefault();
        money.post(route('sales.courier-claims.compensation', claim.id), { preserveScroll: true });
    };

    return (
        <div className="mt-2 flex flex-col gap-2">
            <form onSubmit={decide} className="flex flex-wrap items-end gap-2">
                <select
                    aria-label={t('parcel.courier_answer')}
                    className={selectClass}
                    value={decision.data.decision}
                    onChange={(e) => decision.setData('decision', e.target.value)}
                    aria-required
                >
                    {claim.status === 'open' && <option value="accepted">{t('parcel.courier_accepted')}</option>}
                    <option value="rejected">{t('parcel.courier_rejected')}</option>
                </select>
                {decision.data.decision === 'accepted' && (
                    <Input
                        aria-label={t('parcel.amount_accepted')}
                        className="w-28"
                        inputMode="decimal"
                        value={decision.data.accepted_amount}
                        onChange={(e) => decision.setData('accepted_amount', e.target.value)}
                    />
                )}
                <Input
                    aria-label={t('parcel.note')}
                    className="max-w-xs"
                    placeholder={decision.data.decision === 'rejected' ? t('parcel.why_required') : t('parcel.note_optional')}
                    value={decision.data.note}
                    onChange={(e) => decision.setData('note', e.target.value)}
                    aria-required={decision.data.decision === 'rejected'}
                />
                <Button size="sm" variant="outline" disabled={decision.processing}>
                    {t('parcel.save_answer')}
                </Button>
                <InputError message={decision.errors.note ?? (decision.errors as Record<string, string | undefined>).claim} />
            </form>
            <form onSubmit={receive} className="flex flex-wrap items-end gap-2">
                <span className="text-xs font-medium">{t('parcel.paid_outside')}</span>
                <select
                    aria-label={t('parcel.arrived_in')}
                    className={selectClass}
                    value={money.data.money_account_id}
                    onChange={(e) => money.setData('money_account_id', e.target.value)}
                    aria-required
                >
                    <option value="">{t('parcel.arrived_in_pick')}</option>
                    {money_accounts.map((account) => (
                        <option key={account.id} value={account.id}>
                            {account.name}
                        </option>
                    ))}
                </select>
                <Input
                    aria-label={t('parcel.amount_received')}
                    className="w-28"
                    inputMode="decimal"
                    value={money.data.amount}
                    onChange={(e) => money.setData('amount', e.target.value)}
                    aria-required
                />
                <Input
                    aria-label={t('parcel.received_on')}
                    type="date"
                    className="w-40"
                    value={money.data.received_on}
                    onChange={(e) => money.setData('received_on', e.target.value)}
                    aria-required
                />
                <Input
                    aria-label={t('parcel.bank_reference')}
                    className="w-36"
                    placeholder={t('parcel.reference')}
                    value={money.data.reference}
                    onChange={(e) => money.setData('reference', e.target.value)}
                />
                <Button size="sm" disabled={money.processing}>
                    {t('parcel.record_compensation')}
                </Button>
                <InputError
                    message={
                        money.errors.money_account_id ??
                        money.errors.amount ??
                        money.errors.received_on ??
                        (money.errors as Record<string, string | undefined>).claim
                    }
                />
            </form>
            <ClosedPeriodHint date={money.data.received_on} />
        </div>
    );
}

/** What slice 8 recorded about the parcel's money, under its outcome. */
export function ParcelSettlementPanel({ settlement }: { settlement: ParcelSettlement }) {
    const { t, tr, locale } = useTranslation();
    const { can_settle_claims } = useSettlementProps();
    const claim = settlement.loss_claim;

    if (!settlement.cutoff_note && !settlement.door_resolution && !claim && !settlement.waiting_discount) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-col gap-1 text-xs">
            {settlement.waiting_discount && (
                <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-800 dark:bg-amber-950/40">
                    {t('parcel.discount_waiting', { amount: formatMoney(settlement.waiting_discount.discount) })}
                    {settlement.waiting_discount.requested_by && t('parcel.asked_by', { name: settlement.waiting_discount.requested_by })}:{' '}
                    {settlement.waiting_discount.reason}. {t('parcel.nothing_posted_until')}
                </p>
            )}
            {settlement.door_resolution_label && (
                <p>
                    <span className="font-medium">{tr(`parcel.door.${settlement.door_resolution ?? ''}`, settlement.door_resolution_label)}</span>
                    {settlement.door_difference !== null &&
                        Number(settlement.door_difference) !== 0 &&
                        t('parcel.difference_part', { amount: formatMoney(settlement.door_difference) })}
                    {settlement.door_reason && `: ${settlement.door_reason}`}
                </p>
            )}
            {settlement.cutoff_note && <p className="text-amber-700 dark:text-amber-400">{settlement.cutoff_note}</p>}
            {claim && (
                <div className="rounded-md border p-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{t('parcel.claim_on_courier')}</span>
                        <Badge variant={claim.status === 'paid' ? 'default' : claim.status === 'rejected' ? 'destructive' : 'secondary'}>
                            {tr(`parcel.claim_status.${claim.status}`, claim.status_label)}
                        </Badge>
                    </div>
                    <p className="text-muted-foreground mt-1">
                        {t('parcel.claim_summary', {
                            kind: tr(`parcel.claim_kind.${claim.kind}`, claim.kind_label),
                            cost: formatMoney(claim.value_at_cost),
                            claimed: formatMoney(claim.claimed_amount),
                        })}
                        {Number(claim.compensation_received) > 0 &&
                            `${t('parcel.claim_received', { amount: formatMoney(claim.compensation_received) })}${claim.paid_on_statement ? t('parcel.on_payout_statement') : ''}`}
                        {locale === 'bn' ? '।' : '.'}
                        {claim.adjustments.length > 0 &&
                            t('parcel.write_off', {
                                items: claim.adjustments
                                    .map((adjustment) => `${formatQuantity(adjustment.quantity)} (${adjustment.status ?? t('parcel.pending')})`)
                                    .join(', '),
                            })}
                    </p>
                    {claim.notes && <p className="text-muted-foreground whitespace-pre-line">{claim.notes}</p>}
                    {!claim.closed && can_settle_claims && <ClaimActions claim={claim} />}
                </div>
            )}
        </div>
    );
}
