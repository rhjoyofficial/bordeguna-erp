import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

/** What the server proposes for one product at one cost (ProductPricing::proposal). */
export interface MarketProposal {
    cost: string;
    suggested: string;
    suggested_margin_percent: string | null;
    min_margin_price: string | null;
    markup_percent: string | null;
    min_margin_percent: string | null;
    rounding_step: string | null;
    current_price: string | null;
    current_since: string | null;
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

/**
 * Where a typed price stands against the proposal: under cost (refused),
 * under the margin floor (reason needed, and the price-setting permission),
 * or fine. The server decides again; this only says it before the round trip.
 */
export function priceStanding(price: string, proposal: MarketProposal | null): 'blank' | 'below_cost' | 'below_margin' | 'ok' {
    if (proposal === null || price.trim() === '') return 'blank';
    const typed = parseFloat(price);
    if (!Number.isFinite(typed) || typed <= 0) return 'blank';
    if (typed < parseFloat(proposal.cost)) return 'below_cost';
    if (proposal.min_margin_price !== null && typed < parseFloat(proposal.min_margin_price)) return 'below_margin';
    return 'ok';
}

/** The proposal's three facts, under a price box, on the receipt step and in the popup. */
export function ProposalFacts({ proposal, unit, canOverrideMargin }: { proposal: MarketProposal; unit: string | null; canOverrideMargin: boolean }) {
    const { t } = useTranslation();
    void canOverrideMargin;

    return (
        <div className="text-muted-foreground grid gap-0.5 text-[11.5px]">
            <span>
                {t('market.cost_per_unit', { unit: unit ?? 'unit' })}: <span className="tabular-nums">{formatMoney(proposal.cost)}</span>
                {' · '}
                {t('market.suggested')}: <span className="font-medium tabular-nums">{formatMoney(proposal.suggested)}</span>
                {proposal.suggested_margin_percent !== null && ` (${t('market.suggested_margin', { percent: proposal.suggested_margin_percent })})`}
            </span>
            <span>
                {proposal.min_margin_price !== null && proposal.min_margin_percent !== null
                    ? t('market.min_margin_price', { price: plainPrice(proposal.min_margin_price), percent: plainPrice(proposal.min_margin_percent) })
                    : t('market.no_floor')}
                {' · '}
                {proposal.current_price !== null && proposal.current_since !== null
                    ? t('market.current_price', { price: plainPrice(proposal.current_price), time: proposal.current_since })
                    : t('market.no_current_price')}
            </span>
        </div>
    );
}

/** The one-line warning for a typed price, by its standing. Null when fine or blank. */
export function StandingNote({ standing, canOverrideMargin }: { standing: ReturnType<typeof priceStanding>; canOverrideMargin: boolean }) {
    const { t } = useTranslation();

    if (standing === 'below_cost') return <p className="text-destructive text-[11.5px]">{t('market.below_cost')}</p>;
    if (standing === 'below_margin') {
        return (
            <p className="text-[11.5px] text-amber-700 dark:text-amber-400">
                {canOverrideMargin ? t('market.below_margin') : t('market.below_margin_no_permission')}
            </p>
        );
    }

    return null;
}

/**
 * Set today's price of a market product in a popup (Phase 2 / 1B, 17 Oct
 * 2026): from Sales setup > Selling prices (pick the product, the proposal
 * comes from its latest lot) or from an unconfirmed receipt line (the
 * product is fixed, the proposal comes from THAT lot). Both post to
 * ConfirmMarketPrice through their own route; the gates are the server's.
 */
export function MarketPriceDialog({
    open,
    onOpenChange,
    products,
    product = null,
    receiptLineId = null,
    canOverrideMargin,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Products to choose from when none is fixed. */
    products?: MarketProduct[];
    /** A fixed product (an unconfirmed receipt line). */
    product?: MarketProduct | null;
    receiptLineId?: number | null;
    canOverrideMargin: boolean;
}) {
    const { t } = useTranslation();
    const [productId, setProductId] = useState<string>(product ? String(product.id) : '');
    const [proposal, setProposal] = useState<MarketProposal | null>(null);
    const [proposalError, setProposalError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({ product_id: '', price: '', reason: '' });

    const chosen = product ?? (products ?? []).find((p) => String(p.id) === productId) ?? null;

    useEffect(() => {
        if (!open) return;
        setProductId(product ? String(product.id) : '');
        setProposal(null);
        setProposalError(null);
        reset();
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, product?.id, receiptLineId]);

    // The proposal for the chosen product (or the fixed receipt line), and the
    // price box prefilled with it so confirming the suggestion is one click.
    useEffect(() => {
        if (!open) return;
        if (receiptLineId === null && productId === '') {
            setProposal(null);
            return;
        }

        const query = receiptLineId !== null ? `receipt_line_id=${receiptLineId}` : `product_id=${productId}`;
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
                setProposal(body as MarketProposal);
                setData((current) => ({ ...current, product_id: productId, price: plainPrice((body as MarketProposal).suggested) }));
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
    }, [open, productId, receiptLineId]);

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
                                <SearchSelect id="mp-product" value={productId} onChange={(e) => setProductId(e.target.value)}>
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

                    {loading && <p className="text-muted-foreground text-xs">{t('market.proposal_loading')}</p>}
                    {proposalError && <p className="text-destructive text-xs">{proposalError}</p>}
                    {proposal && <ProposalFacts proposal={proposal} unit={chosen?.unit ?? null} canOverrideMargin={canOverrideMargin} />}

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
                    <StandingNote standing={standing} canOverrideMargin={canOverrideMargin} />

                    {standing === 'below_margin' && canOverrideMargin && (
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
                                processing || proposal === null || standing === 'below_cost' || (standing === 'below_margin' && !canOverrideMargin)
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
