import InputError from '@/components/input-error';
import { ReverseDocumentForm, type Reversal } from '@/components/purchasing/reverse-document';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { showDate } from '@/lib/dates';
import { formatMoney } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Supplier advances (ruling P2, 18 Oct 2026): the supplier gives an advance
 * back, in full or in part (Dr the money account / Cr 1160). The server caps
 * it at what is still open; the form only offers that figure.
 */

export interface RefundRow {
    id: number;
    number: string;
    refunded_on: string | null;
    amount: string;
    money_account: string | null;
    advance: { id: number; number: string } | null;
    reference: string | null;
    is_reversed: boolean;
    reversal: Reversal | null;
}

export function RefundForm({
    supplierId,
    options,
    moneyAccounts,
    today,
}: {
    supplierId: number;
    /** What may be refunded: an advance ("advance:12") or the on-account balance ("on_account"). */
    options: { key: string; label: string; open: string }[];
    moneyAccounts: { id: number; name: string; balance?: string | null }[];
    today: string;
}) {
    const { data, setData, post, errors, processing, reset } = useForm({
        supplier_id: supplierId,
        advance_key: options[0]?.key ?? '',
        money_account_id: '',
        refunded_on: today,
        amount: '',
        reference: '',
        note: '',
    });
    const chosen = options.find((option) => option.key === data.advance_key);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/purchasing/advance-refunds', { preserveScroll: true, onSuccess: () => reset('amount', 'reference', 'note') });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-lg border p-4">
            <h3 className="text-sm font-semibold">Record a refund from the supplier</h3>
            <div className="flex flex-wrap items-start gap-4">
                {options.length > 1 && (
                    <div className="grid gap-2">
                        <Label htmlFor="advance_key" required>
                            Refund of
                        </Label>
                        <SearchSelect fit id="advance_key" value={data.advance_key} onChange={(e) => setData('advance_key', e.target.value)}>
                            {options.map((option) => (
                                <option key={option.key} value={option.key}>
                                    {option.label} ({formatMoney(option.open)} open)
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                )}
                <div className="grid gap-2">
                    <Label htmlFor="refund_money_account_id" required>
                        Received into
                    </Label>
                    <SearchSelect
                        fit
                        id="refund_money_account_id"
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
                <div className="grid gap-2">
                    <Label htmlFor="refunded_on" required>
                        Date
                    </Label>
                    <Input
                        id="refunded_on"
                        type="date"
                        className="w-auto"
                        value={data.refunded_on}
                        onChange={(e) => setData('refunded_on', e.target.value)}
                    />
                    <InputError message={errors.refunded_on} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="refund_amount" required>
                        Amount
                    </Label>
                    <Input
                        id="refund_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        className="w-36 text-right tabular-nums"
                        value={data.amount}
                        onChange={(e) => setData('amount', e.target.value)}
                    />
                    {chosen && <span className="text-muted-foreground text-xs">Up to {formatMoney(chosen.open)}</span>}
                    <InputError message={errors.amount} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="refund_reference">Reference</Label>
                    <Input id="refund_reference" className="w-56" value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                </div>
            </div>
            <InputError message={(errors as Record<string, string>).supplier_advance_id ?? errors.advance_key} />
            <Button type="submit" variant="outline" className="w-fit" disabled={processing || !(Number(data.amount) > 0)}>
                Record refund
            </Button>
        </form>
    );
}

export function RefundsTable({ refunds, canReverse }: { refunds: RefundRow[]; canReverse: boolean }) {
    const [reversing, setReversing] = useState<number | null>(null);

    if (refunds.length === 0) {
        return <p className="text-muted-foreground text-sm">Nothing refunded.</p>;
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-3">Refund</th>
                            <th className="p-3">Date</th>
                            <th className="p-3">Of</th>
                            <th className="p-3">Into</th>
                            <th className="p-3 text-right">Amount</th>
                            <th className="p-3" />
                        </tr>
                    </thead>
                    <tbody>
                        {refunds.map((row) => (
                            <tr key={row.id} className={`border-t ${row.is_reversed ? 'text-muted-foreground line-through' : ''}`}>
                                <td className="p-3 font-medium">{row.number}</td>
                                <td className="p-3">{showDate(row.refunded_on)}</td>
                                <td className="p-3">{row.advance?.number ?? 'On account (vouchers)'}</td>
                                <td className="p-3">{row.money_account ?? '-'}</td>
                                <td className="p-3 text-right tabular-nums">{formatMoney(row.amount)}</td>
                                <td className="p-3 text-right">
                                    {row.is_reversed ? (
                                        <span className="text-xs">Reversed</span>
                                    ) : (
                                        canReverse && (
                                            <button
                                                type="button"
                                                className="text-muted-foreground text-xs underline"
                                                onClick={() => setReversing(reversing === row.id ? null : row.id)}
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
            {reversing !== null && (
                <ReverseDocumentForm
                    kind="advance_refund"
                    action={`/purchasing/advance-refunds/${reversing}/reverse`}
                    originalDate={refunds.find((row) => row.id === reversing)?.refunded_on ?? ''}
                    idPrefix={`reverse-refund-${reversing}`}
                    onDone={() => setReversing(null)}
                />
            )}
        </div>
    );
}
