import { RecordHistory } from '@/components/record-history';
import { EngineActions, ReasonDialog, type EngineAction, type ReasonOption } from '@/components/sales/correction-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

export type InvoiceCorrectionsProps = {
    actions: EngineAction[];
    counter_sale: boolean;
    can_credit: boolean;
    reversed: boolean;
    reversal: { on: string | null; by: string | null; reason: string | null } | null;
    correction_of: string | null;
    corrected_to: string | null;
    corrected_to_id: number | null;
    extra_charge_on: { id: number; invoice_number: string } | null;
    credit_notes: { number: string; kind: 'full' | 'price'; date: string; amount: string; reason: string }[];
    extra_charges: { id: number; number: string; amount: string; credited: boolean }[];
    reversal_reasons: ReasonOption[];
    correction_reasons: ReasonOption[];
    today: string;
};

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): what can be done to an issued sales
 * invoice, which itself never changes. Two buttons the engine decides (Credit
 * note and Correct, or Void sale on a counter sale), Price credit and Extra
 * charge beside them as documents of their own, and what was already issued
 * against it.
 */
export function InvoiceCorrections({
    invoice,
    corrections,
}: {
    invoice: { id: number; invoice_number: string };
    corrections: InvoiceCorrectionsProps;
}) {
    const { t } = useTranslation();
    const [creditDate, setCreditDate] = useState(corrections.today);
    const [creditAmount, setCreditAmount] = useState('');
    const [chargeDate, setChargeDate] = useState(corrections.today);
    const [chargeAmount, setChargeAmount] = useState('');
    const [chargeFor, setChargeFor] = useState('');
    const number = invoice.invoice_number;

    return (
        <section className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="font-medium">{t('corr.sales.invoice.corrections')}</h2>
                    {corrections.reversed && <Badge variant="secondary">{t('corr.sales.invoice.badge_credited')}</Badge>}
                    {corrections.correction_of && <Badge variant="outline">{corrections.correction_of}</Badge>}
                    {corrections.extra_charge_on && (
                        <Badge variant="outline">
                            <Link href={`/sales/invoices/${corrections.extra_charge_on.id}`}>
                                {t('corr.sales.invoice.extra_on', { number: corrections.extra_charge_on.invoice_number })}
                            </Link>
                        </Badge>
                    )}
                </div>
                <div className="flex flex-col items-end gap-2">
                    <EngineActions
                        type="sales.invoice"
                        id={invoice.id}
                        recordLabel={number}
                        actions={corrections.actions}
                        reasons={corrections.reversal_reasons}
                        labels={{
                            reverse: corrections.counter_sale ? t('corr.sales.invoice.void_sale') : t('corr.sales.invoice.credit_note'),
                            correct: t('corr.sales.invoice.correct'),
                        }}
                        correctHref={`/sales/invoices/${invoice.id}/correct`}
                    />
                    <div className="flex flex-wrap justify-end gap-2">
                        {corrections.can_credit && !corrections.counter_sale && (
                            <>
                                <ReasonDialog
                                    trigger={
                                        <Button size="sm" variant="ghost">
                                            {t('corr.sales.invoice.price_credit')}
                                        </Button>
                                    }
                                    title={t('corr.sales.invoice.price_credit_title', { number })}
                                    description={t('corr.sales.invoice.price_credit_text')}
                                    url={`/sales/invoices/${invoice.id}/price-credit`}
                                    reasons={corrections.correction_reasons}
                                    data={{ credit_date: creditDate, amount: creditAmount }}
                                    ready={creditDate !== '' && Number(creditAmount) > 0}
                                    confirmLabel={t('corr.sales.invoice.issue_credit')}
                                    fields={
                                        <div className="flex flex-wrap gap-3">
                                            <div className="grid gap-1">
                                                <Label htmlFor="price-credit-date" required>
                                                    {t('corr.sales.invoice.credit_date')}
                                                </Label>
                                                <Input
                                                    id="price-credit-date"
                                                    type="date"
                                                    max={corrections.today}
                                                    className="w-40"
                                                    value={creditDate}
                                                    onChange={(e) => setCreditDate(e.target.value)}
                                                />
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor="price-credit-amount" required>
                                                    {t('corr.sales.amount')}
                                                </Label>
                                                <Input
                                                    id="price-credit-amount"
                                                    inputMode="decimal"
                                                    className="w-36 text-right"
                                                    value={creditAmount}
                                                    onChange={(e) => setCreditAmount(e.target.value)}
                                                />
                                            </div>
                                        </div>
                                    }
                                />
                                <ReasonDialog
                                    trigger={
                                        <Button size="sm" variant="ghost">
                                            {t('corr.sales.invoice.extra_charge')}
                                        </Button>
                                    }
                                    title={t('corr.sales.invoice.extra_title', { number })}
                                    description={t('corr.sales.invoice.extra_text')}
                                    url={`/sales/invoices/${invoice.id}/extra-charge`}
                                    reasons={corrections.correction_reasons}
                                    data={{ charge_date: chargeDate, amount: chargeAmount, description: chargeFor }}
                                    ready={chargeDate !== '' && Number(chargeAmount) > 0 && chargeFor.trim() !== ''}
                                    confirmLabel={t('corr.sales.invoice.raise_extra')}
                                    fields={
                                        <div className="flex flex-wrap gap-3">
                                            <div className="grid gap-1">
                                                <Label htmlFor="extra-charge-date" required>
                                                    {t('corr.sales.invoice.charge_date')}
                                                </Label>
                                                <Input
                                                    id="extra-charge-date"
                                                    type="date"
                                                    max={corrections.today}
                                                    className="w-40"
                                                    value={chargeDate}
                                                    onChange={(e) => setChargeDate(e.target.value)}
                                                />
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor="extra-charge-amount" required>
                                                    {t('corr.sales.amount')}
                                                </Label>
                                                <Input
                                                    id="extra-charge-amount"
                                                    inputMode="decimal"
                                                    className="w-36 text-right"
                                                    value={chargeAmount}
                                                    onChange={(e) => setChargeAmount(e.target.value)}
                                                />
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor="extra-charge-for" required>
                                                    {t('corr.sales.invoice.charge_for')}
                                                </Label>
                                                <Input
                                                    id="extra-charge-for"
                                                    className="w-64"
                                                    maxLength={200}
                                                    value={chargeFor}
                                                    onChange={(e) => setChargeFor(e.target.value)}
                                                />
                                            </div>
                                        </div>
                                    }
                                />
                            </>
                        )}
                        <RecordHistory type="sales.invoice" id={invoice.id} label={number} />
                    </div>
                </div>
            </div>

            {corrections.reversed && (
                <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                    {t('corr.sales.invoice.credited_banner')}
                    {corrections.reversal?.reason ? ` ${corrections.reversal.reason}` : ''}
                    {corrections.corrected_to && corrections.corrected_to_id && (
                        <>
                            {' '}
                            <Link href={`/sales/invoices/${corrections.corrected_to_id}`} className="underline">
                                {t('corr.sales.invoice.corrected_to', { number: corrections.corrected_to })}
                            </Link>
                        </>
                    )}
                </p>
            )}

            {corrections.credit_notes.length > 0 && (
                <div className="text-sm">
                    <h3 className="text-muted-foreground mb-1 text-xs font-semibold uppercase">{t('corr.sales.invoice.credit_notes')}</h3>
                    <ul className="grid gap-1">
                        {corrections.credit_notes.map((note) => (
                            <li key={note.number} className="flex flex-wrap gap-x-3">
                                <span className="font-medium">{note.number}</span>
                                <span>{note.kind === 'full' ? t('corr.sales.invoice.kind_full') : t('corr.sales.invoice.kind_price')}</span>
                                <span>{formatDate(note.date)}</span>
                                <span className="tabular-nums">{formatMoney(Number(note.amount))}</span>
                                <span className="text-muted-foreground">{note.reason}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {corrections.extra_charges.length > 0 && (
                <div className="text-sm">
                    <h3 className="text-muted-foreground mb-1 text-xs font-semibold uppercase">{t('corr.sales.invoice.extra_charges')}</h3>
                    <ul className="grid gap-1">
                        {corrections.extra_charges.map((extra) => (
                            <li key={extra.id} className="flex flex-wrap gap-x-3">
                                <Link href={`/sales/invoices/${extra.id}`} className="font-medium underline">
                                    {extra.number}
                                </Link>
                                <span className="tabular-nums">{formatMoney(Number(extra.amount))}</span>
                                {extra.credited && <Badge variant="secondary">{t('corr.sales.invoice.badge_credited')}</Badge>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
