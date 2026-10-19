import { RecordHistory } from '@/components/record-history';
import { EngineActions, ReasonDialog, type EngineAction, type ReasonOption } from '@/components/sales/correction-actions';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { useState } from 'react';

export type ReceiptCorrections = {
    id: number;
    label: string;
    reversed: boolean;
    correction_of: string | null;
    released: { invoice_number: string | null; amount: string }[];
    actions: EngineAction[];
    can_reallocate: boolean;
    open_invoices: { id: number; number: string; outstanding: string }[];
    invoices: { id: number; number: string | null; amount: string }[];
};

/** ⭐ Corrections wave 1: the badges of a receipt in the list. */
export function ReceiptBadges({ receipt }: { receipt: ReceiptCorrections }) {
    const { t } = useTranslation();

    return (
        <div className="mt-1 flex flex-wrap justify-end gap-1">
            {receipt.reversed && <Badge variant="secondary">{t('corr.sales.reversed')}</Badge>}
            {receipt.correction_of && <Badge variant="outline">{receipt.correction_of}</Badge>}
            {receipt.reversed && receipt.released.length > 0 && (
                <span className="text-muted-foreground text-xs">
                    {t('corr.sales.receipt.released', { list: receipt.released.map((r) => `${r.invoice_number ?? ''} ${r.amount}`).join(', ') })}
                </span>
            )}
        </div>
    );
}

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): Correct and Reverse (the engine's, the
 * accountant's), Re-allocate (whoever records receipts), History.
 */
export function ReceiptActions({
    receipt,
    reversalReasons,
    correctionReasons,
}: {
    receipt: ReceiptCorrections;
    reversalReasons: ReasonOption[];
    correctionReasons: ReasonOption[];
}) {
    const { t } = useTranslation();
    const [from, setFrom] = useState(String(receipt.invoices[0]?.id ?? ''));
    const [to, setTo] = useState('');
    const [amount, setAmount] = useState('');
    const targets = receipt.open_invoices.filter((invoice) => String(invoice.id) !== from);

    return (
        <div className="flex flex-col items-end gap-1">
            <EngineActions
                type="sales.customer_receipt"
                id={receipt.id}
                recordLabel={receipt.label}
                actions={receipt.actions}
                reasons={reversalReasons}
                labels={{ reverse: t('corr.sales.receipt.reverse'), correct: t('corr.sales.receipt.correct') }}
                correctHref={`/sales/receipts/${receipt.id}/correct`}
            />
            <div className="flex flex-wrap justify-end gap-2">
                {receipt.can_reallocate && (
                    <ReasonDialog
                        trigger={
                            <Button size="sm" variant="ghost">
                                {t('corr.sales.receipt.reallocate')}
                            </Button>
                        }
                        title={t('corr.sales.receipt.reallocate_title', { label: receipt.label })}
                        description={t('corr.sales.receipt.reallocate_text')}
                        url={`/sales/receipts/${receipt.id}/reallocate`}
                        reasons={correctionReasons}
                        data={{ from_invoice_id: from, to_invoice_id: to, amount }}
                        ready={from !== '' && to !== '' && Number(amount) > 0}
                        confirmLabel={t('corr.sales.receipt.move')}
                        fields={
                            <div className="grid gap-3">
                                <div className="grid gap-1">
                                    <Label htmlFor={`reallocate-from-${receipt.id}`} required>
                                        {t('corr.sales.receipt.from_invoice')}
                                    </Label>
                                    <SearchSelect id={`reallocate-from-${receipt.id}`} value={from} onChange={(e) => setFrom(e.target.value)}>
                                        {receipt.invoices.map((invoice) => (
                                            <option key={invoice.id} value={invoice.id}>
                                                {invoice.number} · {invoice.amount}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`reallocate-to-${receipt.id}`} required>
                                        {t('corr.sales.receipt.to_invoice')}
                                    </Label>
                                    <SearchSelect id={`reallocate-to-${receipt.id}`} value={to} onChange={(e) => setTo(e.target.value)}>
                                        <option value="">{t('corr.sales.receipt.choose_invoice')}</option>
                                        {targets.map((invoice) => (
                                            <option key={invoice.id} value={invoice.id}>
                                                {invoice.number} · {t('corr.sales.receipt.owes', { amount: invoice.outstanding })}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`reallocate-amount-${receipt.id}`} required>
                                        {t('corr.sales.amount')}
                                    </Label>
                                    <Input
                                        id={`reallocate-amount-${receipt.id}`}
                                        inputMode="decimal"
                                        className="w-36 text-right"
                                        value={amount}
                                        onChange={(e) => setAmount(e.target.value)}
                                    />
                                </div>
                            </div>
                        }
                    />
                )}
                <RecordHistory type="sales.customer_receipt" id={receipt.id} label={receipt.label} />
            </div>
        </div>
    );
}
