import { RelatedDocumentsPanel } from '@/components/documents/related-documents-panel';
import { ApprovedViaNotice, type ApprovedVia } from '@/components/purchasing/purchase-approval-decision';
import { showDate } from '@/lib/dates';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEventHandler } from 'react';

import { Field } from '@/components/form/field';

import { ActionDialog } from '@/components/action-dialog';
import { useTermsLabel } from '@/components/purchasing/payment-terms-fields';
import { AppliedAdvancesTable, ApplyAdvancesForm, type AppliedAdvance, type AvailableAdvance } from '@/components/purchasing/supplier-advances';
import { CorrectionButtons, CorrectionChainNote, type CorrectionsProps } from '@/components/purchasing/supplier-money-corrections';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type PaymentTerms } from '@/lib/payment-terms';
import type { BreadcrumbItem } from '@/types';

type Bill = {
    id: number;
    bill_number: string;
    bill_date: string | null;
    due_date: string | null;
    /** ⭐ Ruling P6: the terms the bill kept, and where its due date came from. */
    terms?: PaymentTerms | null;
    due_date_source?: 'terms' | 'typed' | 'override' | 'changed' | null;
    due_date_reason?: string | null;
    note: string | null;
    total: string;
    tax_total: string;
    tax_claimed: string;
    paid: string;
    credited: string;
    outstanding: string;
    is_reversed: boolean;
    reversed_at: string | null;
    reversal_note: string | null;
    supplier: string | null;
    purchase_order_id: number | null;
    po_number: string | null;
    /** 18 Oct 2026: the landed cost this bill records, when it is one. */
    landed_cost: { number: string; url: string } | null;
    /** ⭐ Ruling P5 (19 Oct 2026): orders a bill across several covers, the non-PO flag, the duplicate override, carriage. */
    orders?: Array<{ id: number; po_number: string }>;
    has_non_po_lines?: boolean;
    duplicate_override_reason?: string | null;
    duplicate_matches?: Array<{ id: number; bill_number: string; bill_date: string | null; total: string; why: string }>;
    carriage?: Array<{
        id: number;
        number: string;
        total: string;
        status: 'draft' | 'awaiting' | 'approved' | 'posted';
        goods_receipt_id: number;
        url: string;
        bills: Array<{ id: number; bill_number: string }>;
    }>;
    lines: Array<{
        id: number;
        kind?: 'goods' | 'expense';
        description?: string | null;
        expense_account?: string | null;
        po_number?: string | null;
        challan?: string | null;
        goods_receipt_id?: number | null;
        product: string | null;
        sku: string | null;
        quantity: string;
        unit_cost: string;
        order_unit_cost: string | null;
        line_total: string;
    }>;
    /** ⭐ Supplier discounts (ruling P3, 18 Oct 2026). */
    discounted: string;
    discounts: Discount[];
};

interface Availability {
    action: string;
    allowed: boolean;
    requestable: boolean;
    blockers: { code: string; message: string }[];
    date: { rule: string } | null;
}

/** A trade or settlement discount on this bill: where its cost went, and how to reverse it. */
type Discount = {
    id: number;
    number: string;
    kind: 'trade' | 'settlement';
    kind_label: string;
    date: string | null;
    amount: string;
    percent: string | null;
    reference: string;
    reason: string | null;
    payment_id: number | null;
    on_hand: string;
    consumed: string;
    batches: Array<{ batch: string | null; on_hand: string; consumed: string; old_unit_cost: string; new_unit_cost: string | null }>;
    journal: string | null;
    reversal: { on: string | null; by: string | null; reason: string | null } | null;
    actions: Availability[];
};

type JournalView = {
    id: number;
    journal_number: string;
    is_reversal: boolean;
    lines: Array<{ account: string | null; amount: string }>;
};

const money = (value: string) => formatMoney(value);

/** Supplier advances (ruling P2, 18 Oct 2026): applied so far, and what may be applied now. */
type BillAdvances = {
    applied: string;
    applications: AppliedAdvance[];
    available: AvailableAdvance[];
    proposal: { key: string; amount: string }[];
    can_apply: boolean;
    today: string;
};

export default function BillShow({
    bill,
    journals,
    can_pay,
    advances,
    reversalReasons = [],
    approval = null,
    can_change_due_date = false,
    corrections = null,
}: {
    bill: Bill;
    journals: JournalView[];
    can_pay: boolean;
    advances?: BillAdvances;
    reversalReasons?: { id: number; name: string }[];
    approval?: ApprovedVia | null;
    can_change_due_date?: boolean;
    /** Corrections wave 1 (20 Oct 2026): Correct, Reverse, Fix details and the chain. */
    corrections?: (CorrectionsProps & { fix_seen: string }) | null;
}) {
    const { t } = useTranslation();
    const termsLabel = useTermsLabel();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Bills & payments', href: '/purchasing/bills' },
        { title: bill.bill_number, href: '#' },
    ];

    const fix = corrections?.actions.find((a) => a.action === 'fix_details');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Bill ${bill.bill_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <ApprovedViaNotice approval={approval} />
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Bill {bill.bill_number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {bill.supplier}
                            {bill.po_number && bill.purchase_order_id ? (
                                <>
                                    {' · against '}
                                    <Link href={route('purchasing.purchase-orders.show', bill.purchase_order_id)} className="hover:underline">
                                        {bill.po_number}
                                    </Link>
                                </>
                            ) : (
                                ''
                            )}
                            {bill.landed_cost && (
                                <>
                                    {' · records landed cost '}
                                    <Link href={bill.landed_cost.url} className="hover:underline">
                                        {bill.landed_cost.number}
                                    </Link>
                                </>
                            )}
                            {/* ⭐ Ruling P5: one bill across several orders names each. */}
                            {!bill.purchase_order_id && (bill.orders ?? []).length > 0 && (
                                <>
                                    {' · covers '}
                                    {(bill.orders ?? []).map((o, i) => (
                                        <span key={o.id}>
                                            {i > 0 && ', '}
                                            <Link href={route('purchasing.purchase-orders.show', o.id)} className="hover:underline">
                                                {o.po_number}
                                            </Link>
                                        </span>
                                    ))}
                                </>
                            )}
                            {bill.bill_date ? ` · dated ${showDate(bill.bill_date)}` : ''}
                            {bill.due_date ? ` · due ${showDate(bill.due_date)}` : ''}
                        </p>
                        {/* ⭐ Ruling P6: the terms this bill was recorded on, copied
                            then; a later change to the supplier's terms moves nothing. */}
                        <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-2 text-xs">
                            {bill.terms && <span>{t('terms.kept', { terms: termsLabel(bill.terms.type, bill.terms.days) ?? '' })}</span>}
                            {bill.due_date_source === 'terms' && <span>{t('terms.source.terms')}</span>}
                            {bill.due_date_source === 'typed' && <span>{t('terms.source.typed')}</span>}
                            {bill.due_date_source === 'override' && <span>{t('terms.source.override', { reason: bill.due_date_reason ?? '' })}</span>}
                            {bill.due_date_source === 'changed' && <span>{t('terms.source.changed', { reason: bill.due_date_reason ?? '' })}</span>}
                            {/* Corrections wave 1: Fix details covers the due date too. */}
                            {fix?.allowed && corrections ? (
                                <FixDetailsDialog bill={bill} seen={corrections.fix_seen} reasons={corrections.reasons.fix_details} />
                            ) : (
                                can_change_due_date && <ChangeDueDateDialog billId={bill.id} dueDate={bill.due_date} billDate={bill.bill_date} />
                            )}
                        </div>
                        <CorrectionChainNote corrections={corrections} />
                        {bill.has_non_po_lines && (
                            <span className="mt-1 inline-block rounded border border-amber-300 bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                {t('bill_flex.non_po')}
                            </span>
                        )}
                        {bill.duplicate_override_reason && (
                            <p className="text-muted-foreground mt-1 text-xs">
                                Recorded although it looked like{' '}
                                {(bill.duplicate_matches ?? []).map((m) => `${m.bill_number} (${showDate(m.bill_date)})`).join(', ')}:{' '}
                                {bill.duplicate_override_reason}
                            </p>
                        )}
                    </div>

                    {/* The next step of the purchase chain. Opens the payment form
                        with this supplier chosen and this bill's outstanding typed
                        in; the account and the button are still the clerk's. */}
                    <CorrectionButtons
                        corrections={corrections}
                        documentId={bill.id}
                        number={`Bill ${bill.bill_number}`}
                        correctUrl={`/purchasing/bills/${bill.id}/correct`}
                    />

                    {can_pay && (
                        <Button asChild>
                            <Link href={route('purchasing.payments.create', { bill_id: bill.id })}>Pay this bill</Link>
                        </Button>
                    )}

                    {bill.is_reversed && (
                        <div className="border-destructive/40 bg-destructive/10 rounded border px-3 py-2 text-sm">
                            <div className="text-destructive font-semibold">Reversed {showDate(bill.reversed_at)}</div>
                            {bill.reversal_note && <div className="text-muted-foreground">{bill.reversal_note}</div>}
                        </div>
                    )}
                </div>

                {Number(bill.tax_total) > 0 && (
                    <p className="text-muted-foreground text-sm">
                        The total includes VAT of {money(bill.tax_total)}, of which {money(bill.tax_claimed)} is claimed as input VAT.
                    </p>
                )}

                <div className={`grid gap-4 ${Number(bill.discounted) > 0 ? 'sm:grid-cols-5' : 'sm:grid-cols-4'}`}>
                    {[
                        ['Total', bill.total],
                        ...(Number(bill.discounted) > 0 ? [['Discounts', bill.discounted]] : []),
                        ['Paid', bill.paid],
                        ['Credited', bill.credited],
                        ['Outstanding', bill.outstanding],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-4">
                            <div className="text-muted-foreground text-sm">{label}</div>
                            <div className="text-lg font-semibold tabular-nums">{money(value)}</div>
                        </div>
                    ))}
                </div>

                {/* ── Supplier advances (ruling P2) ── */}
                {advances && (advances.applications.length > 0 || (advances.can_apply && advances.available.length > 0)) && (
                    <section className="flex flex-col gap-3">
                        <h2 className="text-sm font-semibold">Advances {Number(advances.applied) > 0 && `· ${money(advances.applied)} applied`}</h2>
                        <AppliedAdvancesTable applications={advances.applications} />
                        {advances.can_apply && advances.available.length > 0 && (
                            <ApplyAdvancesForm
                                billId={bill.id}
                                billNumber={bill.bill_number}
                                billDate={bill.bill_date}
                                outstanding={bill.outstanding}
                                available={advances.available}
                                proposal={advances.proposal}
                                today={advances.today}
                                purchaseOrderId={bill.purchase_order_id}
                            />
                        )}
                    </section>
                )}
                {/* ── end supplier advances ── */}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3 text-right">Quantity</th>
                                <th className="p-3 text-right">Order price</th>
                                <th className="p-3 text-right">Billed price</th>
                                <th className="p-3 text-right">Line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {bill.lines.map((line) => (
                                <tr key={line.id} className="border-t">
                                    <td className="p-3">
                                        {line.kind === 'expense' ? (
                                            <>
                                                <div className="font-medium">{line.description}</div>
                                                <div className="text-muted-foreground text-xs">{line.expense_account}</div>
                                            </>
                                        ) : (
                                            <>
                                                <div className="font-medium">{line.product}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {[line.sku, line.po_number, line.challan ? `challan ${line.challan}` : null]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </div>
                                            </>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity)}</td>
                                    <td className="p-3 text-right tabular-nums">{line.order_unit_cost ? money(line.order_unit_cost) : '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{money(line.unit_cost)}</td>
                                    <td className="p-3 text-right tabular-nums">{money(line.line_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* ⭐ Ruling P5: carriage on this bill went to Landed costs; each is
                    owed through its own document, never through this bill's total. */}
                {(bill.carriage ?? []).length > 0 && (
                    <div className="rounded-lg border">
                        <div className="bg-muted/50 border-b p-3 text-sm font-semibold">{t('bill_flex.carriage')}</div>
                        <ul className="divide-y text-sm">
                            {(bill.carriage ?? []).map((lc) => (
                                <li key={lc.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
                                    <span>
                                        <Link href={lc.url} className="font-medium hover:underline">
                                            {lc.number}
                                        </Link>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            on goods receipt #{lc.goods_receipt_id} ·{' '}
                                            {
                                                {
                                                    draft: 'draft, not sent yet',
                                                    awaiting: 'waiting for approval',
                                                    approved: 'approved, waiting to be posted',
                                                    posted: 'posted',
                                                }[lc.status]
                                            }
                                        </span>
                                        {lc.bills.map((b) => (
                                            <span key={b.id}>
                                                {' · owed as '}
                                                <Link href={route('purchasing.bills.show', b.id)} className="hover:underline">
                                                    {b.bill_number}
                                                </Link>
                                            </span>
                                        ))}
                                    </span>
                                    <span className="tabular-nums">{money(lc.total)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* ⭐ Supplier discounts (ruling P3, 18 Oct 2026): each one lowered what
                    the goods cost, on hand and already sold, never other income. */}
                {bill.discounts.length > 0 && (
                    <div className="rounded-lg border">
                        <div className="bg-muted/50 border-b p-3 text-sm font-semibold">Supplier discounts</div>
                        <ul className="divide-y">
                            {bill.discounts.map((discount) => {
                                const reverse = discount.actions.find((a) => a.action === 'reverse');
                                const usable = reverse && (reverse.allowed || reverse.requestable);

                                return (
                                    <li key={discount.id} className="flex flex-wrap items-start justify-between gap-4 p-3 text-sm">
                                        <div className="min-w-0">
                                            <div className="font-medium">
                                                {discount.number} · {discount.kind_label}
                                                {discount.reversal && (
                                                    <span className="text-destructive ml-2 text-xs">reversed {showDate(discount.reversal.on)}</span>
                                                )}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {showDate(discount.date)} · {discount.kind === 'trade' ? "Supplier's document" : 'Evidence'}:{' '}
                                                {discount.reference}
                                                {discount.percent ? ` · ${discount.percent}%` : ''}
                                                {discount.payment_id ? (
                                                    <>
                                                        {' · '}
                                                        <Link href={route('purchasing.payments.show', discount.payment_id)} className="underline">
                                                            with payment #{discount.payment_id}
                                                        </Link>
                                                    </>
                                                ) : null}
                                                {discount.journal ? ` · ${discount.journal}` : ''}
                                            </div>
                                            {discount.reason && <div className="text-muted-foreground text-xs">{discount.reason}</div>}
                                            <div className="text-muted-foreground mt-1 text-xs">
                                                Off stock on hand {money(discount.on_hand)} · off cost of goods already sold or used{' '}
                                                {money(discount.consumed)}
                                            </div>
                                            {discount.batches.map((batch, i) => (
                                                <div key={i} className="text-muted-foreground text-xs tabular-nums">
                                                    Batch {batch.batch ?? '-'}: unit cost {money(batch.old_unit_cost)}
                                                    {batch.new_unit_cost ? ` to ${money(batch.new_unit_cost)}` : ''}
                                                </div>
                                            ))}
                                            {discount.reversal?.reason && (
                                                <div className="text-muted-foreground text-xs">{discount.reversal.reason}</div>
                                            )}
                                        </div>
                                        <div className="flex flex-col items-end gap-2">
                                            <span className="font-semibold tabular-nums">{money(discount.amount)}</span>
                                            {reverse &&
                                                !discount.reversal &&
                                                (usable ? (
                                                    <ActionDialog
                                                        action="reverse"
                                                        recordLabel={discount.number}
                                                        previewUrl={`/records/purchasing.supplier_discount/${discount.id}/reverse/preview`}
                                                        submitUrl={`/records/purchasing.supplier_discount/${discount.id}/reverse`}
                                                        reasons={reversalReasons}
                                                        allowToday={reverse.allowed && reverse.date?.rule === 'original'}
                                                        trigger={
                                                            <Button size="sm" variant="outline">
                                                                Reverse discount
                                                            </Button>
                                                        }
                                                    />
                                                ) : (
                                                    <span className="text-muted-foreground block max-w-56 text-right text-xs">
                                                        {reverse.blockers[0]?.message}
                                                    </span>
                                                ))}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                <div>
                    <h2 className="mb-2 text-sm font-semibold">Accounting</h2>
                    <div className="flex flex-col gap-3">
                        {journals.length === 0 && (
                            <p className="text-muted-foreground text-sm">No journal was posted. Account mappings are not configured yet.</p>
                        )}
                        {journals.map((entry) => (
                            <div key={entry.id} className="overflow-x-auto rounded-lg border">
                                <div className="bg-muted/50 border-b p-3 text-sm font-medium">
                                    {entry.journal_number}
                                    {entry.is_reversal && <span className="text-destructive ml-2 text-xs">reversal</span>}
                                </div>
                                {/* ⚠ 17 Oct 2026 (practice run F19): Debit and Credit columns, as
                                    on the payment page, instead of one column of signed amounts.
                                    The amount is signed: positive debits, negative credits. */}
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground text-left">
                                        <tr>
                                            <th className="p-3 font-medium">Account</th>
                                            <th className="p-3 text-right font-medium">Debit</th>
                                            <th className="p-3 text-right font-medium">Credit</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {entry.lines.map((line, i) => (
                                            <tr key={i} className="border-t">
                                                <td className="p-3">{line.account}</td>
                                                <td className="p-3 text-right tabular-nums">{Number(line.amount) > 0 ? money(line.amount) : ''}</td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {Number(line.amount) < 0 ? money(String(-Number(line.amount))) : ''}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ))}
                    </div>
                </div>

                {/* ⚠ D-SB-6: a posted bill is never edited in place. It is corrected
                    with Correct or Reverse through the corrections process (the
                    buttons at the top), each with a reason and a preview (20 Oct 2026). */}
                {/* PR5 (18 Oct 2026): came from, led to, journals. */}
                <RelatedDocumentsPanel />
            </div>
        </AppLayout>
    );
}

/**
 * ⭐ Ruling P6: change the due date of a recorded bill, in a popup, with a
 * reason. No journal moves; the change and the reason are in the bill history.
 */
function ChangeDueDateDialog({ billId, dueDate, billDate }: { billId: number; dueDate: string | null; billDate: string | null }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ due_date: dueDate ?? '', reason: '' });

    const close = () => {
        reset();
        clearErrors();
        setOpen(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/purchasing/bills/${billId}/due-date`, { preserveScroll: true, onSuccess: close });
    };

    return (
        <>
            <Button type="button" variant="outline" size="sm" className="h-7" onClick={() => setOpen(true)}>
                {t('terms.change_due')}
            </Button>
            <Dialog open={open} onOpenChange={(next) => (next ? setOpen(true) : close())}>
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submit} className="grid gap-4">
                        <DialogHeader>
                            <DialogTitle>{t('terms.change_due')}</DialogTitle>
                            <DialogDescription>{t('terms.change_due_help')}</DialogDescription>
                        </DialogHeader>
                        <Field label={t('terms.new_due_date')} htmlFor="change-due-date" required error={errors.due_date}>
                            <Input
                                id="change-due-date"
                                type="date"
                                aria-required
                                min={billDate ?? undefined}
                                value={data.due_date}
                                onChange={(e) => setData('due_date', e.target.value)}
                            />
                        </Field>
                        <Field label={t('terms.reason')} htmlFor="change-due-reason" required error={errors.reason}>
                            <Input id="change-due-reason" aria-required value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                        </Field>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                {t('terms.cancel')}
                            </Button>
                            <Button disabled={processing}>{t('terms.save')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

/**
 * Corrections wave 1 (20 Oct 2026): Fix details of a posted bill, the text
 * that moves no money: the supplier's number typed wrong, the due date, the
 * note. A reason is required; the server refuses a bill changed since this
 * page opened. ⚠ Saved only by clicking Save (no Enter-to-submit, Joy 19 Oct).
 */
function FixDetailsDialog({ bill, seen, reasons }: { bill: Bill; seen: string; reasons: { id: number; name: string }[] }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const blank = { due_date: bill.due_date ?? '', bill_number: bill.bill_number, note: bill.note ?? '', reason_id: '', reason_text: '' };
    const [data, setDataState] = useState(blank);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const set = (field: keyof typeof blank, value: string) => setDataState((previous) => ({ ...previous, [field]: value }));

    const close = () => {
        setDataState(blank);
        setErrors({});
        setOpen(false);
    };

    const save = () => {
        setProcessing(true);
        router.post(
            `/purchasing/bills/${bill.id}/fix-details`,
            {
                ...data,
                due_date: data.due_date === '' ? null : data.due_date,
                reason_id: data.reason_id === '' ? null : Number(data.reason_id),
                seen,
            },
            {
                preserveScroll: true,
                onSuccess: close,
                onError: (e) => setErrors(e),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Button type="button" variant="outline" size="sm" className="h-7" onClick={() => setOpen(true)}>
                {t('corr.sup.fix_details')}
            </Button>
            <Dialog open={open} onOpenChange={(next) => (next ? setOpen(true) : close())}>
                <DialogContent className="sm:max-w-md">
                    <div className="grid gap-4">
                        <DialogHeader>
                            <DialogTitle>{t('corr.sup.fix_title', { number: bill.bill_number })}</DialogTitle>
                            <DialogDescription>{t('corr.sup.fix_help')}</DialogDescription>
                        </DialogHeader>
                        <Field label={t('corr.sup.due_date')} htmlFor="fix-due-date" error={errors.due_date}>
                            <Input
                                id="fix-due-date"
                                type="date"
                                className="w-auto"
                                min={bill.bill_date ?? undefined}
                                value={data.due_date}
                                onChange={(e) => set('due_date', e.target.value)}
                            />
                        </Field>
                        <Field label={t('corr.sup.bill_number')} htmlFor="fix-bill-number" required error={errors.bill_number}>
                            <Input
                                id="fix-bill-number"
                                aria-required
                                className="max-w-60"
                                value={data.bill_number}
                                onChange={(e) => set('bill_number', e.target.value)}
                            />
                        </Field>
                        <Field label={t('corr.sup.note')} htmlFor="fix-note" error={errors.note}>
                            <textarea
                                id="fix-note"
                                className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                                value={data.note}
                                onChange={(e) => set('note', e.target.value)}
                            />
                        </Field>
                        <Field label={t('corr.sup.reason')} htmlFor="fix-reason-id" error={errors.reason_id}>
                            <select
                                id="fix-reason-id"
                                value={data.reason_id}
                                onChange={(e) => set('reason_id', e.target.value)}
                                className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                            >
                                <option value="">{t('corr.sup.reason_pick')}</option>
                                {reasons.map((reason) => (
                                    <option key={reason.id} value={reason.id}>
                                        {reason.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('corr.sup.reason_words')} htmlFor="fix-reason-text" required error={errors.reason_text}>
                            <textarea
                                id="fix-reason-text"
                                aria-required
                                className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                                value={data.reason_text}
                                onChange={(e) => set('reason_text', e.target.value)}
                            />
                        </Field>
                        {errors.stale && <p className="text-destructive text-sm">{errors.stale}</p>}
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                {t('corr.sup.go_back')}
                            </Button>
                            <Button type="button" disabled={processing || data.reason_text.trim().length < 5} onClick={save}>
                                {t('corr.sup.save')}
                            </Button>
                        </DialogFooter>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
