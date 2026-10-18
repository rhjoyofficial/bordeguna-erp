import InputError from '@/components/input-error';
import { ReverseDocumentForm } from '@/components/purchasing/reverse-document';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { showDate } from '@/lib/dates';
import { formatMoney } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Supplier advances (ruling P2, 18 Oct 2026): the pieces the bill page, the
 * bill form and the supplier's advances page share.
 *
 * ⚠ The server decides every figure and refuses anything over what is open;
 * the boxes here only carry the default proposal (the purchase order's own
 * advances first, then the oldest) and what Finance changes it to.
 */

/** One advance the supplier holds, as SupplierAdvanceController::availableRow sends it. */
export interface AvailableAdvance {
    /** "advance:12", or "on_account" for the 1160 balance vouchers left. */
    key: string;
    id: number | null;
    number: string;
    date: string;
    open: string;
    purchase_order_id: number | null;
    po_number: string | null;
}

export interface AppliedAdvance {
    id: number;
    number: string;
    applied_on: string | null;
    amount: string;
    tds_covered: string;
    advance: { id: number; number: string } | null;
    bill?: { id: number; bill_number: string } | null;
    source_note: string | null;
    is_reversed: boolean;
    can_reverse?: boolean;
}

/** Money in these forms is paisa. Two decimal places, added as integers. */
export const toPaisa = (value: string | undefined): number => Math.round((Number(String(value ?? '').replace(/,/g, '')) || 0) * 100);

/** The default fill: each row in the order given, up to `limit` paisa. */
export function proposeUpTo(available: AvailableAdvance[], limit: number): Record<string, string> {
    let left = limit;
    const amounts: Record<string, string> = {};

    for (const row of available) {
        const take = Math.max(0, Math.min(left, toPaisa(row.open)));
        amounts[row.key] = take > 0 ? (take / 100).toFixed(2) : '';
        left -= take;
    }

    return amounts;
}

function AdvanceName({ row }: { row: AvailableAdvance }) {
    if (row.id === null) {
        return <span title="Paid by Payment vouchers before advances had their own documents">On account (vouchers)</span>;
    }

    return (
        <Link href={`/purchasing/advances/${row.id}`} className="font-medium hover:underline">
            {row.number}
        </Link>
    );
}

/**
 * The rows of open advances with a box each. Controlled: the bill form keeps
 * the amounts with its own data; ApplyAdvancesForm keeps them itself.
 */
export function AdvanceRows({
    available,
    amounts,
    onChange,
    errors = {},
    purchaseOrderId = null,
}: {
    available: AvailableAdvance[];
    amounts: Record<string, string>;
    onChange: (amounts: Record<string, string>) => void;
    errors?: Record<string, string>;
    purchaseOrderId?: number | null;
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="p-3">Advance</th>
                        <th className="p-3">Paid on</th>
                        <th className="p-3">Purchase order</th>
                        <th className="p-3 text-right">Open</th>
                        <th className="p-3 text-right">Use now</th>
                    </tr>
                </thead>
                <tbody>
                    {available.map((row, index) => {
                        const isOver = toPaisa(amounts[row.key]) > toPaisa(row.open);

                        return (
                            <tr key={row.key} className="border-t">
                                <td className="p-3">
                                    <AdvanceName row={row} />
                                    {purchaseOrderId !== null && row.purchase_order_id === purchaseOrderId && (
                                        <span className="text-muted-foreground ml-2 text-xs">this order</span>
                                    )}
                                </td>
                                <td className="p-3">{showDate(row.date)}</td>
                                <td className="p-3">{row.po_number ?? '-'}</td>
                                <td className="p-3 text-right tabular-nums">
                                    <button
                                        type="button"
                                        className="tabular-nums hover:underline"
                                        title="Use all of it"
                                        onClick={() => onChange({ ...amounts, [row.key]: row.open })}
                                    >
                                        {formatMoney(row.open)}
                                    </button>
                                </td>
                                <td className="p-3 text-right">
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        aria-label={`Use from ${row.number}`}
                                        className={`ml-auto max-w-32 text-right tabular-nums ${isOver ? 'border-destructive' : ''}`}
                                        value={amounts[row.key] ?? ''}
                                        onChange={(e) => onChange({ ...amounts, [row.key]: e.target.value })}
                                    />
                                    {isOver && <p className="text-destructive mt-1 text-xs">Only {formatMoney(row.open)} is open.</p>}
                                    {errors[`advances.${index}.amount`] && (
                                        <p className="text-destructive mt-1 text-xs">{errors[`advances.${index}.amount`]}</p>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/** The rows a form posts: only boxes with an amount, in the order shown. */
export function advanceRowsToPost(available: AvailableAdvance[], amounts: Record<string, string>) {
    return available.map((row) => ({ key: row.key, amount: amounts[row.key] ?? '' })).filter((row) => toPaisa(row.amount) > 0);
}

/** Apply advances to an existing bill: the bill page and the supplier's advances page. */
export function ApplyAdvancesForm({
    billId,
    billNumber,
    billDate,
    outstanding,
    available,
    proposal,
    today,
    purchaseOrderId = null,
}: {
    billId: number;
    billNumber: string;
    billDate: string | null;
    outstanding: string;
    available: AvailableAdvance[];
    proposal: { key: string; amount: string }[];
    today: string;
    purchaseOrderId?: number | null;
}) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [amounts, setAmounts] = useState<Record<string, string>>(() => Object.fromEntries(proposal.map((p) => [p.key, p.amount])));
    const [appliedOn, setAppliedOn] = useState(billDate && billDate > today ? billDate : today);
    const [processing, setProcessing] = useState(false);

    const total = available.reduce((sum, row) => sum + toPaisa(amounts[row.key]), 0);
    const overBill = total > toPaisa(outstanding);
    const overRow = available.some((row) => toPaisa(amounts[row.key]) > toPaisa(row.open));

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(
            `/purchasing/bills/${billId}/advances`,
            { applied_on: appliedOn, advances: advanceRowsToPost(available, amounts) },
            { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-lg border p-4">
            <div>
                <h2 className="text-sm font-semibold">Apply advances to bill {billNumber}</h2>
                <p className="text-muted-foreground text-sm">
                    Proposed: this order&apos;s own advances first, then the oldest. Change any amount before applying. The bill owes{' '}
                    {formatMoney(outstanding)}.
                </p>
            </div>
            <AdvanceRows available={available} amounts={amounts} onChange={setAmounts} errors={errors} purchaseOrderId={purchaseOrderId} />
            <div className="flex flex-wrap items-end gap-4">
                <div className="grid gap-2">
                    <Label htmlFor={`applied_on_${billId}`} required>
                        Applied on
                    </Label>
                    <Input
                        id={`applied_on_${billId}`}
                        type="date"
                        className="w-auto"
                        value={appliedOn}
                        onChange={(e) => setAppliedOn(e.target.value)}
                    />
                    <InputError message={errors.applied_on} />
                </div>
                <Button type="submit" disabled={processing || total <= 0 || overBill || overRow}>
                    Apply {formatMoney(total / 100)}
                </Button>
                {overBill && <span className="text-destructive text-sm">More than the bill owes.</span>}
            </div>
            <InputError message={errors.advances} />
        </form>
    );
}

/** Applications so far, each reversible with a reason. */
export function AppliedAdvancesTable({ applications, showBill = false }: { applications: AppliedAdvance[]; showBill?: boolean }) {
    const [reversing, setReversing] = useState<number | null>(null);

    if (applications.length === 0) {
        return <p className="text-muted-foreground text-sm">No advance has been applied yet.</p>;
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-3">Application</th>
                            <th className="p-3">Date</th>
                            {showBill && <th className="p-3">Bill</th>}
                            <th className="p-3">From</th>
                            <th className="p-3 text-right">Amount</th>
                            <th className="p-3 text-right">TDS already held</th>
                            <th className="p-3" />
                        </tr>
                    </thead>
                    <tbody>
                        {applications.map((row) => (
                            <tr key={row.id} className={`border-t ${row.is_reversed ? 'text-muted-foreground line-through' : ''}`}>
                                <td className="p-3 font-medium">{row.number}</td>
                                <td className="p-3">{showDate(row.applied_on)}</td>
                                {showBill && (
                                    <td className="p-3">
                                        {row.bill ? (
                                            <Link href={`/purchasing/bills/${row.bill.id}`} className="hover:underline">
                                                {row.bill.bill_number}
                                            </Link>
                                        ) : (
                                            '-'
                                        )}
                                    </td>
                                )}
                                <td className="p-3">
                                    {row.advance ? (
                                        <Link href={`/purchasing/advances/${row.advance.id}`} className="hover:underline">
                                            {row.advance.number}
                                        </Link>
                                    ) : (
                                        <span title={row.source_note ?? undefined}>On account (vouchers)</span>
                                    )}
                                </td>
                                <td className="p-3 text-right tabular-nums">{formatMoney(row.amount)}</td>
                                <td className="p-3 text-right tabular-nums">{toPaisa(row.tds_covered) > 0 ? formatMoney(row.tds_covered) : '-'}</td>
                                <td className="p-3 text-right">
                                    {row.is_reversed ? (
                                        <span className="text-xs">Reversed</span>
                                    ) : (
                                        row.can_reverse !== false && (
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
                    kind="advance_application"
                    action={`/purchasing/advance-applications/${reversing}/reverse`}
                    originalDate={applications.find((row) => row.id === reversing)?.applied_on ?? ''}
                    idPrefix={`reverse-application-${reversing}`}
                    onDone={() => setReversing(null)}
                />
            )}
        </div>
    );
}
