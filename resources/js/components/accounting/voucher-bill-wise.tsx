import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { showDate } from '@/lib/dates';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { useEffect, useMemo, useState } from 'react';

/**
 * ⭐ Tally's bill-wise details under a supplier's or customer's line (party
 * ledgers, 20 Oct 2026). Lists what the line can be against (bills, invoices,
 * what a supplier owes back, a customer's advance, returns, paid orders) with
 * what is open on each. "Oldest first" fills itself; "Choose bills myself"
 * lets the person share the amount out, the rest being an Advance where the
 * voucher allows one. The server checks the same list again on posting
 * (PartyBillWise), so this only fills the boxes.
 */

export interface BillWiseItem {
    ref: string;
    kind: string;
    number: string;
    date: string | null;
    due_date: string | null;
    open: string;
    open_label: string;
}

interface PartyItems {
    mode: 'supplier_payment' | 'customer_receipt' | 'supplier_refund' | 'customer_refund' | 'adjustment' | 'employee' | null;
    party: { type: string; id: number; name: string } | null;
    rest_is_advance: boolean;
    items: BillWiseItem[];
}

export interface WithholdingOption {
    id: number;
    kind: 'tds' | 'vds';
    label: string;
    rate: number;
    supplier_type: string | null;
    from: string | null;
    to: string | null;
}

type Allocation = { ref: string; amount: string };

const toPaisa = (value: string) => Math.round((Number.parseFloat(value) || 0) * 100);
const taka = (paisa: number) => (paisa / 100).toFixed(2);
const money = (paisa: number) => (paisa / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Oldest first over the open items, as PostVoucher does when nothing was chosen. */
function oldestFirst(items: BillWiseItem[], amount: number): Allocation[] {
    let left = amount;
    const out: Allocation[] = [];

    for (const item of items) {
        if (left <= 0) {
            break;
        }

        const take = Math.min(toPaisa(item.open), left);

        if (take > 0) {
            out.push({ ref: item.ref, amount: taka(take) });
            left -= take;
        }
    }

    return out;
}

const NOTHING_OPEN: Record<string, TranslationKey> = {
    supplier_payment: 'pl.nothing_open_payment',
    customer_receipt: 'pl.nothing_open_receipt',
    supplier_refund: 'pl.nothing_open_supplier_refund',
    customer_refund: 'pl.nothing_open_customer_refund',
    adjustment: 'pl.nothing_open_adjustment',
};

const MODE_NOTE: Record<string, TranslationKey | undefined> = {
    supplier_refund: 'pl.mode_supplier_refund',
    customer_refund: 'pl.mode_customer_refund',
    adjustment: 'pl.mode_adjustment',
};

export function VoucherBillWise({
    voucherType,
    head,
    date,
    amount,
    billWise,
    onChange,
    error,
    withholding,
}: {
    voucherType: string;
    head: string;
    date: string;
    amount: string;
    /** '' = oldest first; otherwise the JSON of the chosen allocations. */
    billWise: string;
    onChange: (billWise: string) => void;
    error?: string;
    /** Supplier payment lines only: the owner's TDS / VDS rates and the line's choice. */
    withholding?: {
        rates: WithholdingOption[];
        supplierType: string | null;
        tds: string;
        vds: string;
        onChange: (patch: { tds_rate_id?: string; vds_rate_id?: string }) => void;
        errors: { tds?: string; vds?: string };
    };
}) {
    const { t } = useTranslation();
    const [data, setData] = useState<PartyItems | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!head) {
            setData(null);

            return;
        }

        const controller = new AbortController();
        setLoading(true);

        fetch(route('accounting.vouchers.party-items', { type: voucherType, head, date }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((json: PartyItems | null) => setData(json))
            .catch(() => undefined)
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [voucherType, head, date]);

    const lineMinor = toPaisa(amount);
    const items = useMemo(() => data?.items ?? [], [data]);
    const chosen: Allocation[] | null = useMemo(() => {
        if (!billWise) {
            return null;
        }

        try {
            return JSON.parse(billWise) as Allocation[];
        } catch {
            return null;
        }
    }, [billWise]);

    const shown = chosen ?? oldestFirst(items, lineMinor);
    const amountFor = (ref: string) => shown.find((row) => row.ref === ref)?.amount ?? '';
    const allocated = shown.reduce((sum, row) => sum + toPaisa(row.amount), 0);
    const rest = lineMinor - allocated;

    if (!head || data?.mode === 'employee' || data?.mode === null) {
        return null;
    }

    const setAmount = (ref: string, value: string) => {
        const base = chosen ?? shown;
        const next = base.some((row) => row.ref === ref)
            ? base.map((row) => (row.ref === ref ? { ...row, amount: value } : row))
            : [...base, { ref, amount: value }];

        onChange(JSON.stringify(next.filter((row) => row.amount !== '')));
    };

    // Tax on the whole line, as PostVoucher holds it back.
    const inForce = (rate: WithholdingOption) =>
        (rate.supplier_type === null || rate.supplier_type === withholding?.supplierType) &&
        (rate.from === null || rate.from <= date) &&
        (rate.to === null || rate.to >= date);
    const rateOf = (id: string) => withholding?.rates.find((rate) => String(rate.id) === id);
    const taxOf = (id: string) => {
        const rate = rateOf(id);

        return rate ? Math.round((lineMinor * rate.rate) / 100) : 0;
    };

    const mode = data?.mode ?? null;
    const note = mode ? MODE_NOTE[mode] : undefined;

    return (
        <div className="bg-muted/30 mt-2 grid gap-2 rounded-md border p-2 text-xs">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{t('pl.bill_wise')}</span>
                {loading && <span className="text-muted-foreground">{t('pl.loading')}</span>}
                {items.length > 0 &&
                    (chosen === null ? (
                        <Button type="button" variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={() => onChange(JSON.stringify(shown))}>
                            {t('pl.choose_myself')}
                        </Button>
                    ) : (
                        <Button type="button" variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={() => onChange('')}>
                            {t('pl.use_oldest')}
                        </Button>
                    ))}
                {chosen === null && items.length > 0 && <span className="text-muted-foreground">{t('pl.oldest_first')}</span>}
            </div>

            {note && <p className="text-muted-foreground">{t(note)}</p>}

            {!loading && data && items.length === 0 && (
                <p className="text-muted-foreground">{t(NOTHING_OPEN[data.mode ?? ''] ?? 'pl.nothing_open_payment')}</p>
            )}

            {items.length > 0 && (
                <table className="w-auto text-xs">
                    <thead>
                        <tr className="text-muted-foreground text-left">
                            <th className="py-1 pr-3 font-medium">{t('pl.col_against')}</th>
                            <th className="py-1 pr-3 font-medium">{t('pl.col_date')}</th>
                            <th className="py-1 pr-3 text-right font-medium">{t('pl.col_open')}</th>
                            <th className="py-1 text-right font-medium">{t('pl.col_this')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item) => (
                            <tr key={item.ref}>
                                <td className="py-1 pr-3 whitespace-nowrap">{item.number}</td>
                                <td className="py-1 pr-3 whitespace-nowrap">{item.date ? showDate(item.date) : ''}</td>
                                <td className="py-1 pr-3 text-right tabular-nums">{item.open_label}</td>
                                <td className="py-1 text-right">
                                    <Input
                                        inputMode="decimal"
                                        className="h-7 w-28 text-right text-xs tabular-nums"
                                        value={amountFor(item.ref)}
                                        readOnly={chosen === null}
                                        onChange={(e) => setAmount(item.ref, e.target.value)}
                                        aria-label={`${t('pl.col_this')}: ${item.number}`}
                                        placeholder="0.00"
                                    />
                                </td>
                            </tr>
                        ))}
                        {data?.rest_is_advance && rest > 0 && (
                            <tr>
                                <td className="py-1 pr-3" colSpan={3}>
                                    {t('pl.advance_row')}
                                </td>
                                <td className="py-1 text-right tabular-nums">{money(rest)}</td>
                            </tr>
                        )}
                    </tbody>
                </table>
            )}

            {data && !data.rest_is_advance && rest > 0 && (
                <p className="text-amber-700 dark:text-amber-400">{t('pl.not_covered', { amount: money(rest) })}</p>
            )}
            {rest < 0 && <p className="text-destructive">{t('pl.over_line')}</p>}

            {withholding && mode === 'supplier_payment' && (
                <div className="flex flex-wrap items-end gap-3">
                    {(['tds', 'vds'] as const).map((kind) => (
                        <div key={kind} className="grid gap-1">
                            <span className="text-muted-foreground">{t(kind === 'tds' ? 'pl.tds' : 'pl.vds')}</span>
                            <SearchSelect
                                fit
                                value={kind === 'tds' ? withholding.tds : withholding.vds}
                                onChange={(e) =>
                                    withholding.onChange(kind === 'tds' ? { tds_rate_id: e.target.value } : { vds_rate_id: e.target.value })
                                }
                                invalid={Boolean(withholding.errors[kind])}
                            >
                                <option value="">{t('pl.no_tax')}</option>
                                {withholding.rates
                                    .filter((rate) => rate.kind === kind)
                                    .map((rate) => (
                                        <option key={rate.id} value={rate.id} disabled={!inForce(rate)}>
                                            {rate.label}
                                        </option>
                                    ))}
                            </SearchSelect>
                            <InputError message={withholding.errors[kind]} />
                        </div>
                    ))}
                    {(withholding.tds || withholding.vds) && (
                        <span className="text-muted-foreground pb-2">
                            {t('pl.tax_total')}:{' '}
                            <span className="text-foreground tabular-nums">{money(taxOf(withholding.tds) + taxOf(withholding.vds))}</span>
                        </span>
                    )}
                </div>
            )}

            <InputError message={error} />
        </div>
    );
}

/** The tax a supplier line holds back, in paisa, for the voucher's footer. */
export function heldBack(rates: WithholdingOption[], amount: string, tds: string, vds: string): number {
    const line = toPaisa(amount);

    return [tds, vds].reduce((sum, id) => {
        const rate = rates.find((option) => String(option.id) === id);

        return sum + (rate ? Math.round((line * rate.rate) / 100) : 0);
    }, 0);
}
