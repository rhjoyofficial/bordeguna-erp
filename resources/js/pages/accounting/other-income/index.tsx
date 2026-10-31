import { ActionDialog } from '@/components/action-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { RecordHistory } from '@/components/record-history';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface VoucherLine {
    id: number;
    account: string | null;
    amount: string;
    description: string | null;
}

interface Voucher {
    id: number;
    voucher_number: string;
    /** ⚠ A plain Y-m-d from the server. The raw date cast serialises in UTC and
     *  would render a Dhaka receipt one day early. */
    received_on: string | null;
    received_into: string | null;
    payer: string;
    total: string;
    reference: string | null;
    note: string | null;
    lines: VoucherLine[];
    evidence: { id: number; name: string; uploaded_by: string | null; url: string }[];
    /** posted, reversed, or corrected (reversed with a successor). */
    status: 'posted' | 'reversed' | 'corrected';
    correction_of: string | null;
    corrected_to: string | null;
    corrected_to_id: number | null;
    closed_month_exception: boolean;
    reversal: { on: string | null; at: string | null; by: string | null; reason: string | null; journal: string | null } | null;
    actions: ActionOption[];
}

/** One button the server allows, blocks or lets the person request (records.actions shape). */
interface ActionOption {
    action: 'reverse' | 'correct';
    label: string;
    allowed: boolean;
    requestable: boolean;
    blockers: { code: string; message: string; url: string | null; requestable: boolean }[];
    date: { rule: string; sentence: string } | null;
}

interface Props {
    vouchers: Paginated<Voucher>;
    canRecord: boolean;
    showCorrections: boolean;
    reversalReasons: { id: number; name: string }[];
    withdrawalReasons: { id: number; name: string }[];
    myWaitingRequests: { id: number; summary: string; waiting_for: string; withdraw_url: string }[];
    incomeAccounts: { id: number; label: string }[];
    moneyAccounts: { id: number; name: string; type: string }[];
    customers: { id: number; name: string }[];
    today: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/overview' },
    { title: 'Other income', href: '/accounting/other-income' },
];

const EMPTY_LINE = { account_id: '', amount: '', description: '' };

const money = (value: string | number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * The proof behind one voucher, and the way to add more.
 *
 * ⚠⚠ "No proof" IS THE MESSAGE, exactly as on the expense side. A voucher with
 * nothing attached says so in words rather than showing an empty space, because
 * an empty space reads as "nothing to see" when it means "this money arrived
 * with no evidence".
 */
function EvidenceCell({ voucher, canRecord }: { voucher: Voucher; canRecord: boolean }) {
    const [busy, setBusy] = useState(false);

    const upload = (file: File | undefined) => {
        if (!file) return;
        setBusy(true);
        router.post(
            `/accounting/other-income/${voucher.id}/evidence`,
            { file },
            { preserveScroll: true, forceFormData: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <div className="space-y-1">
            {voucher.evidence.length === 0 ? (
                <p className="text-destructive text-xs">No proof</p>
            ) : (
                voucher.evidence.map((file) => (
                    <div key={file.id} className="flex items-center gap-2">
                        <a href={file.url} target="_blank" rel="noreferrer" className="text-xs underline">
                            {file.name}
                        </a>
                        {canRecord && (
                            <button
                                type="button"
                                className="text-muted-foreground hover:text-destructive text-xs"
                                onClick={() => router.delete(`/accounting/other-income/${voucher.id}/evidence/${file.id}`, { preserveScroll: true })}
                            >
                                remove
                            </button>
                        )}
                    </div>
                ))
            )}
            {canRecord && (
                <label className="text-muted-foreground hover:text-foreground block cursor-pointer text-xs underline">
                    {busy ? 'Uploading…' : 'Attach'}
                    <input type="file" className="hidden" onChange={(e) => upload(e.target.files?.[0])} />
                </label>
            )}
        </div>
    );
}

/**
 * Recording income, behind a button.
 *
 * ⚠ The same shape as the expense screen deliberately: the list is what people
 * come to read, and the form belongs behind the primary action in the heading
 * row rather than shouting from the top of the page.
 */
function RecordDialog({ incomeAccounts, moneyAccounts, customers, today }: Pick<Props, 'incomeAccounts' | 'moneyAccounts' | 'customers' | 'today'>) {
    const [open, setOpen] = useState(false);
    const { duplicateWarning } = usePage<SharedData>().props;

    const { data, setData, post, processing, errors, reset, clearErrors, transform } = useForm({
        received_on: today,
        money_account_id: '',
        customer_id: '',
        received_from: '',
        reference: '',
        note: '',
        lines: [{ ...EMPTY_LINE }],
    });

    const setLine = (index: number, field: 'account_id' | 'amount' | 'description', value: string) => {
        const lines = data.lines.map((line, i) => (i === index ? { ...line, [field]: value } : line));
        setData('lines', lines);
    };

    const send = (ack: string | null) => {
        // ⭐ 17 Oct 2026: "Save anyway" answers a possible duplicate with the
        // server's ack token, bound to this person and exactly this payload.
        transform((form) => ({ ...form, duplicate_ack: ack }));
        post(route('accounting.other-income.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        send(null);
    };

    const duplicateError = (errors as Record<string, string | undefined>)._duplicate;

    // ⚠ Added up in the browser so the person entering it sees the figure they
    // are about to commit. The ledger's own total is computed server-side from
    // the same lines; this is a reading aid, never the source.
    const total = data.lines.reduce((sum, line) => sum + (Number.parseFloat(line.amount) || 0), 0);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button size="sm">Record income</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Record income</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="received_on" required>
                                Date the money arrived
                            </Label>
                            <Input
                                id="received_on"
                                type="date"
                                className="w-auto"
                                value={data.received_on}
                                onChange={(e) => setData('received_on', e.target.value)}
                            />
                            <InputError message={errors.received_on} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="money_account_id" required>
                                Received into
                            </Label>
                            {/*
                              ⚠⚠ Only accounts that already have a ledger account appear
                              here. An unmapped one is refused by the engine with an error
                              naming an account nobody remembers creating, so it is not
                              offered at all.
                            */}
                            <SearchSelect
                                id="money_account_id"
                                value={data.money_account_id}
                                onChange={(e) => setData('money_account_id', e.target.value)}
                            >
                                <option value="">Choose an account</option>
                                {moneyAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.money_account_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="customer_id">Customer</Label>
                            <SearchSelect id="customer_id" value={data.customer_id} onChange={(e) => setData('customer_id', e.target.value)}>
                                <option value="">Not a customer</option>
                                {customers.map((customer) => (
                                    <option key={customer.id} value={customer.id}>
                                        {customer.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.customer_id} />
                        </div>

                        <div className="grid gap-2">
                            {/* A scrap dealer or a bank is not a customer and should not
                                have to become one to hand over money. */}
                            <Label htmlFor="received_from">Received from</Label>
                            <Input
                                id="received_from"
                                value={data.received_from}
                                onChange={(e) => setData('received_from', e.target.value)}
                                placeholder="e.g. Adabor scrap dealer"
                            />
                            <InputError message={errors.received_from} />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="reference">Reference</Label>
                            <Input
                                id="reference"
                                value={data.reference}
                                onChange={(e) => setData('reference', e.target.value)}
                                placeholder="Deposit slip or transfer number, or your own"
                            />
                            <InputError message={errors.reference} />
                        </div>
                    </div>

                    <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-md border p-3">
                        <Label required>What the money was for</Label>

                        {data.lines.map((line, index) => (
                            <div key={index} className="grid gap-2">
                                <div className="grid gap-2 sm:grid-cols-[1fr_8rem]">
                                    <SearchSelect
                                        newLedger={{ types: ['income'], reload: ['incomeAccounts'] }}
                                        aria-label={`Income account, line ${index + 1}`}
                                        value={line.account_id}
                                        onChange={(e) => setLine(index, 'account_id', e.target.value)}
                                    >
                                        <option value="">Choose an income account</option>
                                        {incomeAccounts.map((account) => (
                                            <option key={account.id} value={account.id}>
                                                {account.label}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                    <Input
                                        aria-label={`Amount, line ${index + 1}`}
                                        value={line.amount}
                                        onChange={(e) => setLine(index, 'amount', e.target.value)}
                                        className="text-right"
                                        placeholder="0.00"
                                        inputMode="decimal"
                                    />
                                </div>
                                <div className="flex items-center gap-2">
                                    <Input
                                        aria-label={`Description, line ${index + 1}`}
                                        value={line.description}
                                        onChange={(e) => setLine(index, 'description', e.target.value)}
                                        placeholder="Description (optional)"
                                    />
                                    {data.lines.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setData(
                                                    'lines',
                                                    data.lines.filter((_, i) => i !== index),
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </div>
                                <InputError message={errors[`lines.${index}.account_id` as keyof typeof errors]} />
                                <InputError message={errors[`lines.${index}.account` as keyof typeof errors]} />
                                <InputError message={errors[`lines.${index}.amount` as keyof typeof errors]} />
                            </div>
                        ))}

                        <div className="flex items-center justify-between">
                            <Button type="button" variant="outline" size="sm" onClick={() => setData('lines', [...data.lines, { ...EMPTY_LINE }])}>
                                Add another line
                            </Button>
                            <p className="text-sm">
                                <span className="text-muted-foreground">Total </span>
                                <span className="font-semibold">BDT {money(total)}</span>
                            </p>
                        </div>
                        <InputError message={errors.lines} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="note">Note</Label>
                        {/* ⛔ The one control that IS full width, because a note is prose. */}
                        <textarea
                            id="note"
                            className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                        <InputError message={errors.note} />
                    </div>

                    {duplicateError && duplicateWarning && (
                        <div className="grid gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                            <p className="font-medium">Possible duplicate.</p>
                            {duplicateWarning.candidates.map((candidate, index) => (
                                <p key={`${candidate.number ?? 'hidden'}-${index}`}>
                                    {candidate.summary}
                                    {candidate.saved_by ? ` was saved ${candidate.saved_ago} by ${candidate.saved_by}` : ''}
                                    {candidate.number ? ` (${candidate.number}).` : '.'}{' '}
                                    {candidate.url && (
                                        <a href={candidate.url} target="_blank" rel="noreferrer" className="underline">
                                            Open {candidate.number}
                                        </a>
                                    )}
                                </p>
                            ))}
                            <p>Is this a different income?</p>
                            <div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                    onClick={() => send(duplicateWarning.ack_token)}
                                >
                                    Save anyway
                                </Button>
                            </div>
                        </div>
                    )}

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            Record income
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Correct and Reverse for one voucher (edit, delete and correct, Phase 0, 17
 * Oct 2026). The server decides which are allowed; a blocked one stays
 * visible, greyed, with its sentence under it (it is readable on a phone,
 * where a tooltip is not). A closed month is not a dead end for Finance: the
 * same button sends it to the Owner.
 */
function CorrectionActions({ voucher, reasons }: { voucher: Voucher; reasons: Props['reversalReasons'] }) {
    if (voucher.actions.length === 0) {
        return null;
    }

    const base = `/records/accounting.income_voucher/${voucher.id}`;

    return (
        <div className="flex flex-col items-end gap-1">
            <div className="flex flex-wrap justify-end gap-2">
                {voucher.actions.map((option) => {
                    const usable = option.allowed || option.requestable;
                    const reason = option.blockers[0]?.message;

                    if (option.action === 'correct') {
                        return usable ? (
                            <Button key="correct" asChild size="sm" variant="outline">
                                <Link href={`/accounting/other-income/${voucher.id}/correct`}>Correct</Link>
                            </Button>
                        ) : (
                            <Button key="correct" size="sm" variant="outline" disabled title={reason}>
                                Correct
                            </Button>
                        );
                    }

                    return usable ? (
                        <ActionDialog
                            key="reverse"
                            action="reverse"
                            recordLabel={voucher.voucher_number}
                            previewUrl={`${base}/reverse/preview`}
                            submitUrl={`${base}/reverse`}
                            reasons={reasons}
                            allowToday={option.allowed && option.date?.rule === 'original'}
                            trigger={
                                <Button size="sm" variant="outline">
                                    Reverse
                                </Button>
                            }
                        />
                    ) : (
                        <Button key="reverse" size="sm" variant="outline" disabled title={reason}>
                            Reverse
                        </Button>
                    );
                })}
            </div>
            {voucher.actions
                .filter((option) => !option.allowed)
                .map((option) => option.blockers[0]?.message)
                .filter((message, index, all): message is string => !!message && all.indexOf(message) === index)
                .map((message) => (
                    <p key={message} className="text-muted-foreground max-w-56 text-right text-xs">
                        {message}
                    </p>
                ))}
        </div>
    );
}

/** Reversed, Corrected (with the new voucher), Correction of, Closed-month exception. */
function StatusBadges({ voucher }: { voucher: Voucher }) {
    return (
        <div className="mt-1 flex flex-wrap gap-1">
            {voucher.status === 'reversed' && <Badge variant="secondary">Reversed</Badge>}
            {voucher.status === 'corrected' && (
                <Badge variant="secondary">Corrected{voucher.corrected_to ? `, see ${voucher.corrected_to}` : ''}</Badge>
            )}
            {voucher.correction_of && <Badge variant="outline">Correction of {voucher.correction_of}</Badge>}
            {voucher.closed_month_exception && <Badge variant="destructive">Closed-month exception</Badge>}
        </div>
    );
}

/**
 * Money that came in and was not a sale.
 *
 * ⚠⚠ WHY THIS SCREEN EXISTS. `4900 Other Income` was postable from the day the
 * chart was seeded and nothing in the application could write to it. Scrap,
 * bank interest, rent, a supplier refund: all of it needed a hand-written
 * journal, on the one screen the rest of this system exists to spare people.
 * The expense side has had a proper document since August; this is its mirror.
 *
 * ⭐ 17 Oct 2026: live vouchers by default; "Show corrections" brings back the
 * reversed and corrected ones with their badges.
 */
export default function OtherIncomeIndex({
    vouchers,
    canRecord,
    showCorrections,
    reversalReasons,
    withdrawalReasons,
    myWaitingRequests,
    incomeAccounts,
    moneyAccounts,
    customers,
    today,
}: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Other income" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <ReportHeading title="Other income" />
                    {canRecord && <RecordDialog incomeAccounts={incomeAccounts} moneyAccounts={moneyAccounts} customers={customers} today={today} />}
                </div>

                <label className="flex w-fit cursor-pointer items-center gap-2 text-sm">
                    <Checkbox
                        checked={showCorrections}
                        onCheckedChange={(checked) =>
                            router.get('/accounting/other-income', checked === true ? { show_corrections: 1 } : {}, {
                                preserveState: true,
                                preserveScroll: true,
                            })
                        }
                    />
                    Show corrections
                </label>

                {myWaitingRequests.length > 0 && (
                    <div className="grid gap-2 rounded-md border p-3 text-sm">
                        <p className="font-medium">Your requests waiting for approval</p>
                        {myWaitingRequests.map((request) => (
                            <div key={request.id} className="flex flex-wrap items-center gap-3">
                                <span>
                                    {request.summary}, waiting for {request.waiting_for}.
                                </span>
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm" variant="outline">
                                            Withdraw
                                        </Button>
                                    }
                                    title={`Withdraw "${request.summary}"?`}
                                    description="Nothing has changed yet, and nothing will. The request is closed with your reason."
                                    confirmLabel="Withdraw"
                                    withNote
                                    noteRequired
                                    noteLabel="Why"
                                    notePlaceholder={withdrawalReasons[0]?.name ?? 'Asked by mistake'}
                                    onConfirm={(note) => router.post(request.withdraw_url, { reason_text: note }, { preserveScroll: true })}
                                />
                            </div>
                        ))}
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Voucher</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Received from</th>
                                <th className="p-3">Into</th>
                                <th className="p-3">What for</th>
                                <th className="p-3">Proof</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {vouchers.data.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground p-6 text-center">
                                        {/* ⚠ Says what the screen is for, not merely that it is
                                            empty. A blank table on a new install reads as broken. */}
                                        No income recorded yet.
                                    </td>
                                </tr>
                            ) : (
                                vouchers.data.map((voucher) => {
                                    const stale = voucher.status !== 'posted';

                                    return (
                                        <tr key={voucher.id} className="border-sidebar-border/70 dark:border-sidebar-border border-t align-top">
                                            <td className="p-3">
                                                <div className="font-mono text-xs">{voucher.voucher_number}</div>
                                                <StatusBadges voucher={voucher} />
                                            </td>
                                            <td className="p-3 whitespace-nowrap">{showDate(voucher.received_on) || '-'}</td>
                                            <td className="p-3">{voucher.payer}</td>
                                            <td className="p-3">{voucher.received_into ?? '-'}</td>
                                            <td className="p-3">
                                                {voucher.lines.map((line) => (
                                                    <div key={line.id} className="text-xs">
                                                        <span>{line.description || line.account}</span>
                                                        {line.description && line.account && (
                                                            <span className="text-muted-foreground block">{line.account}</span>
                                                        )}
                                                    </div>
                                                ))}
                                                {voucher.reversal?.reason && (
                                                    <div className="text-muted-foreground mt-1 text-xs">
                                                        {voucher.status === 'corrected' ? 'Corrected' : 'Reversed'} by {voucher.reversal.by}:{' '}
                                                        {voucher.reversal.reason}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="p-3">
                                                <EvidenceCell voucher={voucher} canRecord={canRecord && !stale} />
                                            </td>
                                            <td
                                                className={`p-3 text-right font-medium whitespace-nowrap ${stale ? 'text-muted-foreground line-through' : ''}`}
                                            >
                                                {money(voucher.total)}
                                            </td>
                                            <td className="p-3 text-right">
                                                <div className="flex flex-col items-end gap-2">
                                                    <CorrectionActions voucher={voucher} reasons={reversalReasons} />
                                                    <div className="flex gap-3">
                                                        <RecordHistory
                                                            type="accounting.income_voucher"
                                                            id={voucher.id}
                                                            label={voucher.voucher_number}
                                                        />
                                                        <Link href={`/accounting/other-income/${voucher.id}/print`} className="text-xs underline">
                                                            Print
                                                        </Link>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={vouchers} />
            </div>
        </AppLayout>
    );
}
