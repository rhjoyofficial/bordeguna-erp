import { Badge } from '@/components/ui/badge';
import { dmy } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

export type SampleRow = {
    id: number;
    number: string;
    given_on: string;
    recipient: string;
    recipient_phone: string | null;
    recipient_kind: string;
    recipient_url: string | null;
    purpose: string;
    purpose_note: string | null;
    warehouse: string | null;
    items: { product_id: number; product: string | null; quantity: string; unit: string | null; batch: string | null }[];
    cost: string | null;
    delivery_method: string;
    delivery_charge: string | null;
    delivery_to: string | null;
    feedback_on: string | null;
    given_by: string | null;
    status: 'posted' | 'waiting' | 'rejected' | 'reversed' | string;
    note: string | null;
};

export type SamplesReceived = { rows: SampleRow[]; count: number; cost: string | null; delivery: string; url: string };

/**
 * ⭐ Samples (25 Oct 2026): the one table every sample list draws (the
 * Samples screen, a customer's page, an employee's page), so they read alike.
 */
export function SampleRows({
    rows,
    showCost,
    showRecipient = true,
    actions,
}: {
    rows: SampleRow[];
    showCost: boolean;
    showRecipient?: boolean;
    actions?: (row: SampleRow) => ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full min-w-[60rem] text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="p-3">{t('smp.date')}</th>
                        <th className="p-3">{t('smp.number')}</th>
                        {showRecipient && <th className="p-3">{t('smp.to')}</th>}
                        <th className="p-3">{t('smp.purpose')}</th>
                        <th className="p-3">{t('smp.items')}</th>
                        {showCost && <th className="p-3 text-right">{t('smp.cost')}</th>}
                        <th className="p-3">{t('smp.delivery')}</th>
                        <th className="p-3">{t('smp.given_by')}</th>
                        {actions && <th className="p-3 text-right">{t('smp.actions')}</th>}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="border-t align-top">
                            <td className="p-3 whitespace-nowrap">{dmy(row.given_on)}</td>
                            <td className="p-3 font-mono">
                                {row.number}
                                {row.status !== 'posted' && (
                                    <Badge variant="secondary" className="ml-2">
                                        {t(`smp.status_${row.status}` as 'smp.status_waiting')}
                                    </Badge>
                                )}
                            </td>
                            {showRecipient && (
                                <td className="p-3">
                                    {row.recipient_url ? (
                                        <Link href={row.recipient_url} className="underline-offset-2 hover:underline">
                                            {row.recipient}
                                        </Link>
                                    ) : (
                                        row.recipient
                                    )}
                                    <div className="text-muted-foreground text-xs">
                                        {t(`smp.kind_${row.recipient_kind}` as 'smp.kind_customer')}
                                        {row.recipient_phone ? ` · ${row.recipient_phone}` : ''}
                                    </div>
                                </td>
                            )}
                            <td className="p-3">
                                {t(`smp.purpose_${row.purpose}` as 'smp.purpose_tasting')}
                                {row.purpose_note && <div className="text-muted-foreground text-xs">{row.purpose_note}</div>}
                                {row.feedback_on && (
                                    <div className="text-muted-foreground text-xs">
                                        {t('smp.feedback_short')} {dmy(row.feedback_on)}
                                    </div>
                                )}
                            </td>
                            <td className="p-3">
                                <ul className="space-y-0.5">
                                    {row.items.map((item, index) => (
                                        <li key={index}>
                                            {item.product} · {item.quantity}
                                            {item.unit ? ` ${item.unit}` : ''}
                                            {item.batch && <span className="text-muted-foreground font-mono text-xs"> {item.batch}</span>}
                                        </li>
                                    ))}
                                </ul>
                            </td>
                            {showCost && <td className="p-3 text-right tabular-nums">{row.cost === null ? '-' : formatMoney(row.cost)}</td>}
                            <td className="p-3">
                                {t(`smp.method_${row.delivery_method}` as 'smp.method_hand')}
                                {row.delivery_charge && (
                                    <div className="text-muted-foreground text-xs tabular-nums">
                                        {formatMoney(row.delivery_charge)}
                                        {row.delivery_to ? ` · ${row.delivery_to}` : ''}
                                    </div>
                                )}
                            </td>
                            <td className="p-3">{row.given_by}</td>
                            {actions && <td className="p-3">{actions(row)}</td>}
                        </tr>
                    ))}
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={9} className="text-muted-foreground p-6 text-center">
                                {t('smp.empty')}
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

/** A person's page: the samples they received, with the totals and a link to the full list. */
export function SamplesReceivedCard({ samples }: { samples: SamplesReceived }) {
    const { t } = useTranslation();

    if (samples.rows.length === 0) {
        return null;
    }

    return (
        <section className="grid gap-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold">{t('smp.received')}</h2>
                <Link href={samples.url} className="text-sm underline-offset-2 hover:underline">
                    {t('smp.see_all')}
                </Link>
            </div>
            <p className="text-muted-foreground text-xs">
                {t('smp.received_totals', { count: samples.count, delivery: formatMoney(samples.delivery) })}
                {samples.cost !== null && ` · ${t('smp.cost')}: ${formatMoney(samples.cost)}`}
            </p>
            <SampleRows rows={samples.rows} showCost={samples.cost !== null} showRecipient={false} />
        </section>
    );
}
