import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { priceStanding as standingAgainstCost } from '@/lib/market-pricing';
import { formatMoney } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

/**
 * What a "Set today's price" panel shows for one product at one batch cost
 * (ProductPricing::proposal). ⭐ 18 Oct 2026 (Joy, D-E): no suggestion; the
 * batch cost, the previous price and its since date, and the prefill (the
 * previous price, or empty).
 */
export interface MarketProposal {
    cost: string;
    current_price: string | null;
    current_since: string | null;
    current_since_date?: string | null;
    prefill: string | null;
    batch_id?: number;
    batches?: MarketBatch[];
}

/** A batch on hand a price may be set from (Selling prices popup). */
export interface MarketBatch {
    id: number;
    batch_number: string | null;
    cost: string;
    on_hand: string;
    received_on: string | null;
}

export interface MarketProduct {
    id: number;
    name: string;
    sku: string;
    unit: string | null;
}

/** "170.0000" as "170", "164.6000" as "164.6": what a person types. */
export function plainPrice(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';
    const number = parseFloat(value);
    return Number.isFinite(number) ? String(number) : value;
}

/** Where a typed price stands against the proposal's batch cost (see lib/market-pricing). */
export function priceStanding(price: string, proposal: MarketProposal | null): 'blank' | 'below_cost' | 'ok' {
    return standingAgainstCost(price, proposal?.cost);
}

/** The batch cost and the previous price, under a price box, on the receipt step and in the popup. */
export function ProposalFacts({ proposal, unit }: { proposal: MarketProposal; unit: string | null }) {
    const { t } = useTranslation();

    return (
        <div className="text-muted-foreground grid gap-0.5 text-[11.5px]">
            <span>
                {t('market.batch_cost', { unit: unit ?? 'unit' })}: <span className="font-medium tabular-nums">{formatMoney(proposal.cost)}</span>
            </span>
            <span>
                {proposal.current_price !== null && proposal.current_since !== null
                    ? t('market.previous_price', {
                          price: plainPrice(proposal.current_price),
                          date: proposal.current_since_date ? formatDate(proposal.current_since_date) : '',
                          time: proposal.current_since,
                      })
                    : t('market.no_current_price')}
            </span>
        </div>
    );
}

/** The markdown warning for a price under the batch cost. Null when fine or blank. */
export function StandingNote({
    standing,
    proposal,
    canMarkDown,
}: {
    standing: ReturnType<typeof priceStanding>;
    proposal: MarketProposal | null;
    canMarkDown: boolean;
}) {
    const { t } = useTranslation();

    if (standing !== 'below_cost' || proposal === null) return null;

    return (
        <p className={canMarkDown ? 'text-[11.5px] text-amber-700 dark:text-amber-400' : 'text-destructive text-[11.5px]'}>
            {t('market.markdown_warning', { cost: plainPrice(proposal.cost) })}
            {!canMarkDown && ` ${t('market.markdown_no_permission')}`}
        </p>
    );
}

/**
 * Set today's price of a market product in a popup (Phase 2 / 1B, 17 Oct
 * 2026): from Sales setup > Selling prices (pick the product and, when more
 * than one is on hand, the batch) or from an unconfirmed receipt line (the
 * product and the batch are that line's). Both post to ConfirmMarketPrice
 * through their own route; the gate is the server's.
 *
 * ⭐ 18 Oct 2026 (Joy, D-E): no suggestion. The box is prefilled with the
 * previous price when there is one, else empty, and the person types.
 */
export function MarketPriceDialog({
    open,
    onOpenChange,
    products,
    product = null,
    receiptLineId = null,
    canMarkDown,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Products to choose from when none is fixed. */
    products?: MarketProduct[];
    /** A fixed product (an unconfirmed receipt line). */
    product?: MarketProduct | null;
    receiptLineId?: number | null;
    /** May set a price below the batch cost (sales.prices.markdown). */
    canMarkDown: boolean;
}) {
    const { t } = useTranslation();
    const [productId, setProductId] = useState<string>(product ? String(product.id) : '');
    const [batchId, setBatchId] = useState<string>('');
    const [batches, setBatches] = useState<MarketBatch[]>([]);
    const [proposal, setProposal] = useState<MarketProposal | null>(null);
    const [proposalError, setProposalError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({ product_id: '', batch_id: '', price: '', reason: '' });

    const chosen = product ?? (products ?? []).find((p) => String(p.id) === productId) ?? null;

    useEffect(() => {
        if (!open) return;
        setProductId(product ? String(product.id) : '');
        setBatchId('');
        setBatches([]);
        setProposal(null);
        setProposalError(null);
        reset();
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, product?.id, receiptLineId]);

    const chooseProduct = (id: string) => {
        // A new product starts on its newest batch on hand.
        setProductId(id);
        setBatchId('');
    };

    // The batch cost and the previous price for the chosen product and batch
    // (or the fixed receipt line). The price box is prefilled with the
    // previous price only when the product changes, never over typing.
    useEffect(() => {
        if (!open) return;
        if (receiptLineId === null && productId === '') {
            setProposal(null);
            setBatches([]);
            return;
        }

        const query =
            receiptLineId !== null ? `receipt_line_id=${receiptLineId}` : `product_id=${productId}${batchId !== '' ? `&batch_id=${batchId}` : ''}`;
        let cancelled = false;
        setLoading(true);
        setProposalError(null);

        fetch(`${route('sales.market-prices.proposal')}?${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async (response) => {
                const body = await response.json();
                if (cancelled) return;
                if (!response.ok) {
                    setProposal(null);
                    setProposalError(body.refusal ?? t('market.proposal_failed'));
                    return;
                }
                const next = body as MarketProposal;
                setProposal(next);
                if (batchId === '') setBatches(next.batches ?? []);
                setData((current) => ({
                    ...current,
                    product_id: productId,
                    batch_id: receiptLineId === null && next.batch_id !== undefined ? String(next.batch_id) : '',
                    price: batchId === '' ? (next.prefill ?? '') : current.price,
                }));
            })
            .catch(() => {
                if (!cancelled) setProposalError(t('market.proposal_failed'));
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, productId, batchId, receiptLineId]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const standing = priceStanding(data.price, proposal);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const url = receiptLineId !== null ? route('sales.market-prices.confirm-receipt-line', receiptLineId) : route('sales.market-prices.store');
        post(url, { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {t('market.set_todays_price')}
                            {chosen ? `: ${chosen.name}` : ''}
                        </DialogTitle>
                    </DialogHeader>

                    {product === null && (
                        <FormRow>
                            <Field label={t('market.product')} htmlFor="mp-product" size="grow" error={errors.product_id} required>
                                <SearchSelect id="mp-product" value={productId} onChange={(e) => chooseProduct(e.target.value)}>
                                    <option value="">{t('market.choose_product')}</option>
                                    {(products ?? []).map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name} ({p.sku})
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                        </FormRow>
                    )}

                    {/* ⭐ 18 Oct 2026 (Joy, D-E): the price can be set from any batch on hand. */}
                    {receiptLineId === null && batches.length > 1 && (
                        <FormRow>
                            <Field label={t('market.batch')} htmlFor="mp-batch" size="grow" error={errors.batch_id}>
                                <SearchSelect
                                    id="mp-batch"
                                    value={batchId === '' ? data.batch_id : batchId}
                                    onChange={(e) => setBatchId(e.target.value)}
                                >
                                    {batches.map((batch) => (
                                        <option key={batch.id} value={batch.id}>
                                            {t('market.batch_option', {
                                                batch: batch.batch_number ?? `#${batch.id}`,
                                                cost: plainPrice(batch.cost),
                                                on_hand: plainPrice(batch.on_hand),
                                                unit: chosen?.unit ?? '',
                                            })}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                        </FormRow>
                    )}

                    {loading && <p className="text-muted-foreground text-xs">{t('market.proposal_loading')}</p>}
                    {proposalError && <p className="text-destructive text-xs">{proposalError}</p>}
                    {proposal && <ProposalFacts proposal={proposal} unit={chosen?.unit ?? null} />}

                    <FormRow>
                        <Field
                            label={t('market.price_label', { unit: chosen?.unit ?? 'unit' })}
                            htmlFor="mp-price"
                            size="sm"
                            error={errors.price}
                            required
                        >
                            <Input
                                id="mp-price"
                                inputMode="decimal"
                                aria-required
                                value={data.price}
                                onChange={(e) => setData('price', e.target.value)}
                                disabled={proposal === null}
                            />
                        </Field>
                    </FormRow>
                    <StandingNote standing={standing} proposal={proposal} canMarkDown={canMarkDown} />

                    {standing === 'below_cost' && canMarkDown && (
                        <FormRow>
                            <Field label={t('market.reason')} htmlFor="mp-reason" size="grow" error={errors.reason} required>
                                <Input
                                    id="mp-reason"
                                    aria-required
                                    value={data.reason}
                                    placeholder={t('market.reason_placeholder')}
                                    onChange={(e) => setData('reason', e.target.value)}
                                />
                            </Field>
                        </FormRow>
                    )}
                    <InputError message={errors.product_id && product !== null ? errors.product_id : undefined} />

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                processing ||
                                proposal === null ||
                                standing === 'blank' ||
                                (standing === 'below_cost' && (!canMarkDown || data.reason.trim() === ''))
                            }
                        >
                            {t('market.confirm')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
