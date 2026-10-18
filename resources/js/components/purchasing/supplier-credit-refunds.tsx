import InputError from '@/components/input-error';
import { ReverseDocumentForm, type Reversal } from '@/components/purchasing/reverse-document';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Ruling P5 (19 Oct 2026): the supplier pays back what they owe us on
 * Accounts Payable (Dr the money account / Cr 2110 with them as party). The
 * server caps it at that debit balance; advances are refunded as advances.
 */

export interface CreditRefundRow {
    id: number;
    number: string;
    received_on: string | null;
    amount: string;
    money_account: string | null;
    reference: string | null;
    is_reversed: boolean;
    reversal: Reversal | null;
}

export function SupplierCreditRefunds({
    supplier,
    balance,
    refunds,
    moneyAccounts,
    canRecord,
    today,
}: {
    supplier: { id: number; name: string };
    balance: string;
    refunds: CreditRefundRow[];
    moneyAccounts: { id: number; name: string }[];
    canRecord: boolean;
    today: string;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [reversing, setReversing] = useState<number | null>(null);
    const owed = Number(balance) > 0;
    const { data, setData, post, errors, processing, reset, isDirty } = useForm({
        supplier_id: supplier.id,
        money_account_id: '',
        received_on: today,
        amount: '',
        reference: '',
        note: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/purchasing/supplier-refunds', {
            preserveScroll: true,
            onSuccess: () => {
                reset('amount', 'reference', 'note');
                setOpen(false);
            },
        });
    };

    if (!owed && refunds.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-muted-foreground text-sm font-medium">{t('refund_credit.title')}</h2>
                {canRecord && owed && (
                    <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                        {t('refund_credit.record')}
                    </Button>
                )}
            </div>
            <p className="text-muted-foreground text-sm">
                {owed ? t('refund_credit.explain', { supplier: supplier.name, amount: formatMoney(balance) }) : t('refund_credit.none')}
            </p>

            {refunds.length > 0 && (
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Refund</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Into</th>
                                <th className="p-3">Reference</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {refunds.map((row) => (
                                <tr key={row.id} className={`border-t ${row.is_reversed ? 'text-muted-foreground line-through' : ''}`}>
                                    <td className="p-3 font-medium">{row.number}</td>
                                    <td className="p-3">{showDate(row.received_on)}</td>
                                    <td className="p-3">{row.money_account ?? '-'}</td>
                                    <td className="p-3">{row.reference ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(row.amount)}</td>
                                    <td className="p-3 text-right">
                                        {row.is_reversed ? (
                                            <span className="text-xs">Reversed</span>
                                        ) : (
                                            canRecord && (
                                                <button
                                                    type="button"
                                                    className="text-muted-foreground text-xs underline"
                                                    onClick={() => setReversing(row.id)}
                                                >
                                                    Reverse
                                                </button>
                                            )
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent aria-describedby={undefined} dirty={isDirty}>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>{t('refund_credit.record')}</DialogTitle>
                        </DialogHeader>
                        <p className="text-muted-foreground text-sm">
                            {t('refund_credit.explain', { supplier: supplier.name, amount: formatMoney(balance) })}
                        </p>
                        <div className="grid gap-1">
                            <Label htmlFor="credit_refund_account" required>
                                {t('refund_credit.received_into')}
                            </Label>
                            <SearchSelect
                                fit
                                id="credit_refund_account"
                                value={data.money_account_id}
                                onChange={(e) => setData('money_account_id', e.target.value)}
                            >
                                <option value="">Select an account</option>
                                {moneyAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.money_account_id} />
                        </div>
                        <div className="flex flex-wrap gap-4">
                            <div className="grid gap-1">
                                <Label htmlFor="credit_refund_date" required>
                                    Date
                                </Label>
                                <Input
                                    id="credit_refund_date"
                                    type="date"
                                    className="w-auto"
                                    value={data.received_on}
                                    onChange={(e) => setData('received_on', e.target.value)}
                                />
                                <InputError message={errors.received_on} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="credit_refund_amount" required>
                                    Amount
                                </Label>
                                <Input
                                    id="credit_refund_amount"
                                    inputMode="decimal"
                                    className="w-36 text-right tabular-nums"
                                    value={data.amount}
                                    onChange={(e) => setData('amount', e.target.value)}
                                />
                                <span className="text-muted-foreground text-xs">Up to {formatMoney(balance)}</span>
                                <InputError message={errors.amount} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="credit_refund_reference">Reference</Label>
                                <Input
                                    id="credit_refund_reference"
                                    className="w-56"
                                    value={data.reference}
                                    onChange={(e) => setData('reference', e.target.value)}
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                {t('bill_flex.cancel')}
                            </Button>
                            <Button type="submit" disabled={processing || !(Number(data.amount) > 0) || data.money_account_id === ''}>
                                {t('refund_credit.record')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={reversing !== null} onOpenChange={(next) => !next && setReversing(null)}>
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>{t('reverse.supplier_refund.title')}</DialogTitle>
                    </DialogHeader>
                    {reversing !== null && (
                        <ReverseDocumentForm
                            kind="supplier_refund"
                            action={`/purchasing/supplier-refunds/${reversing}/reverse`}
                            originalDate={refunds.find((row) => row.id === reversing)?.received_on ?? ''}
                            idPrefix={`reverse-credit-refund-${reversing}`}
                            onDone={() => setReversing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
