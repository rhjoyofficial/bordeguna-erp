import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export type TakeOff = { on: boolean; amount: string };

export const noTakeOff: TakeOff = { on: false, amount: '' };

type OpenAdvance = { key: string; number: string; date: string | null; open: string };
type OpenInvoice = { id: number; number: string; date: string | null; open: string };

type WhatTheyOwe = {
    linked: boolean;
    customer: { id: number; name: string } | null;
    advances: OpenAdvance[];
    invoices: OpenInvoice[];
    advance_total: string;
    invoice_total: string;
    total: string;
    may_set_off: boolean;
    posts_now: boolean;
};

/** One advance used or one set-off against a bill, as the server reports it. */
export type TakenOffRow = {
    kind: 'advance' | 'set_off';
    number: string;
    amount: string;
    state: string;
    invoices: string[];
    url: string | null;
};

const toPaisa = (value: string): number => Math.round((Number(value) || 0) * 100);

/** The amount the server should take off, in paisa (0 when the box is not used). */
export const takeOffPaisa = (takeOff: TakeOff): number => (takeOff.on ? toPaisa(takeOff.amount) : 0);

/** What the server gets: the amount, or null. */
export const takeOffPayload = (takeOff: TakeOff): string | null => (takeOffPaisa(takeOff) > 0 ? takeOff.amount : null);

/** The server's own split, done again while typing: advances oldest first, then invoices oldest first. */
const splitOf = (owe: WhatTheyOwe, amount: number) => {
    let left = amount;
    const rows: { kind: 'advance' | 'invoice'; number: string; amount: number }[] = [];

    for (const advance of owe.advances) {
        const take = Math.max(0, Math.min(left, toPaisa(advance.open)));
        rows.push({ kind: 'advance', number: advance.number, amount: take });
        left -= take;
    }

    for (const invoice of owe.invoices) {
        const take = Math.max(0, Math.min(left, toPaisa(invoice.open)));
        rows.push({ kind: 'invoice', number: invoice.number, amount: take });
        left -= take;
    }

    return { rows, invoicePart: rows.filter((row) => row.kind === 'invoice').reduce((sum, row) => sum + row.amount, 0) };
};

/**
 * ⭐ Joy, 26 Oct 2026: "purchased amount will be deducted from the total they
 * owe, not only from Allocate advance". Shown only when the supplier is also
 * our customer with open invoices; otherwise the "Allocate advance" box is the
 * whole story. One amount, prefilled with the smaller of the bill and what
 * they owe; the server takes it from our advances first, then their invoices
 * by set-off, and refuses anything the books do not allow.
 */
export function TakeOffWhatTheyOwe({
    supplierId,
    value,
    onChange,
    billPaisa,
    errors = {},
}: {
    supplierId: string;
    value: TakeOff;
    onChange: (value: TakeOff) => void;
    /** What the bill will come to, so the amount never starts above it. */
    billPaisa: number;
    errors?: Record<string, string | undefined>;
}) {
    const { t } = useTranslation();
    const [owe, setOwe] = useState<WhatTheyOwe | null>(null);

    useEffect(() => {
        let cancelled = false;
        setOwe(null);
        onChange(noTakeOff);

        if (supplierId === '') {
            return;
        }

        fetch(`/purchasing/suppliers/${supplierId}/what-they-owe`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: WhatTheyOwe | null) => {
                if (!cancelled) {
                    setOwe(data);
                }
            })
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
        // Only a new supplier asks again.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [supplierId]);

    if (owe === null || !owe.linked || owe.invoices.length === 0) {
        return null;
    }

    const total = toPaisa(owe.total);
    const all = Math.max(0, Math.min(billPaisa, total));
    const amount = takeOffPaisa(value);
    const split = splitOf(owe, amount);
    const money = (paisa: number) => formatMoney(paisa / 100);

    return (
        <section className="flex max-w-3xl flex-col gap-2 rounded-lg border p-3">
            <h3 className="text-sm font-medium">{t('tko.title')}</h3>
            <p className="text-muted-foreground text-xs">{t('tko.hint', { name: owe.customer?.name ?? '' })}</p>

            <div className="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <p className="font-medium">{t('tko.our_advance')}</p>
                    {owe.advances.length === 0 && <p className="text-muted-foreground">{t('tko.none')}</p>}
                    <ul className="grid gap-0.5">
                        {owe.advances.map((advance) => (
                            <li key={advance.key} className="flex flex-wrap gap-2">
                                <span>{advance.number}</span>
                                <span className="text-muted-foreground text-xs">{showDate(advance.date)}</span>
                                <span className="tabular-nums">{t('tko.open', { amount: formatMoney(advance.open) })}</span>
                            </li>
                        ))}
                    </ul>
                </div>
                <div>
                    <p className="font-medium">{t('tko.their_invoices')}</p>
                    <ul className="grid gap-0.5">
                        {owe.invoices.map((invoice) => (
                            <li key={invoice.id} className="flex flex-wrap gap-2">
                                <span>{invoice.number}</span>
                                <span className="text-muted-foreground text-xs">{showDate(invoice.date)}</span>
                                <span className="tabular-nums">{t('tko.open', { amount: formatMoney(invoice.open) })}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
            <p className="text-sm font-medium">{t('tko.total', { amount: formatMoney(owe.total) })}</p>

            <label className="flex w-fit items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={value.on}
                    onChange={(e) => onChange(e.target.checked ? { on: true, amount: (all / 100).toFixed(2) } : noTakeOff)}
                />
                {t('tko.use')}
            </label>

            {value.on && (
                <>
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span>{t('tko.amount')}</span>
                        <Input
                            inputMode="decimal"
                            aria-label={t('tko.amount')}
                            className="w-36 text-right tabular-nums"
                            value={value.amount}
                            onChange={(e) => onChange({ on: true, amount: e.target.value })}
                        />
                        <Button type="button" variant="ghost" size="sm" onClick={() => onChange({ on: true, amount: (all / 100).toFixed(2) })}>
                            {money(all)}
                        </Button>
                    </div>

                    {amount > 0 && (
                        <div className="text-sm">
                            <p className="text-muted-foreground text-xs">{t('tko.split')}</p>
                            <p>
                                {split.rows
                                    .filter((row) => row.amount > 0 || row.kind === 'invoice')
                                    .map((row) =>
                                        row.kind === 'advance'
                                            ? t('tko.from_advance', { number: row.number, amount: money(row.amount) })
                                            : t('tko.from_invoice', { number: row.number, amount: money(row.amount) }),
                                    )
                                    .join(' · ')}
                            </p>
                        </div>
                    )}

                    {amount > total && <p className="text-destructive text-sm">{t('tko.too_much_total', { amount: formatMoney(owe.total) })}</p>}
                    {amount > billPaisa && billPaisa > 0 && (
                        <p className="text-destructive text-sm">{t('tko.too_much_bill', { amount: money(billPaisa) })}</p>
                    )}
                    {split.invoicePart > 0 && !owe.may_set_off && <p className="text-destructive text-sm">{t('tko.no_right')}</p>}
                    {split.invoicePart > 0 && owe.may_set_off && (
                        <p className={owe.posts_now ? 'text-muted-foreground text-xs' : 'text-sm text-amber-700 dark:text-amber-400'}>
                            {owe.posts_now ? t('tko.posts_now') : t('tko.waits')}
                        </p>
                    )}
                    <p className="text-sm">{t('tko.after', { amount: money(Math.max(billPaisa - amount, 0)) })}</p>
                </>
            )}
            <InputError message={errors.take_off} />
        </section>
    );
}

/** What came off a bill: each advance used and each set-off, with its state. */
export function TakenOffList({ rows }: { rows: TakenOffRow[] }) {
    const { t } = useTranslation();

    if (rows.length === 0) {
        return null;
    }

    const stateLabel = (state: string) =>
        state === 'posted'
            ? t('tko.state_posted')
            : state === 'pending'
              ? t('tko.state_pending')
              : state === 'rejected'
                ? t('tko.state_rejected')
                : state === 'reversed'
                  ? t('tko.state_reversed')
                  : state;

    return (
        <section className="flex flex-col gap-1.5 rounded-lg border p-3 text-sm">
            <h2 className="font-medium">{t('tko.result_title')}</h2>
            <ul className="grid gap-1">
                {rows.map((row, index) => {
                    const label =
                        row.kind === 'advance'
                            ? t('tko.result_advance', { amount: formatMoney(row.amount), number: row.number })
                            : t('tko.result_set_off', { amount: formatMoney(row.amount), invoices: row.invoices.join(', '), number: row.number });

                    return (
                        <li key={`${row.kind}-${index}`} className="flex flex-wrap items-center gap-2">
                            {row.url ? (
                                <Link href={row.url} className="underline underline-offset-2">
                                    {label}
                                </Link>
                            ) : (
                                <span>{label}</span>
                            )}
                            {row.kind === 'set_off' && <span className="text-muted-foreground text-xs">({stateLabel(row.state)})</span>}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
