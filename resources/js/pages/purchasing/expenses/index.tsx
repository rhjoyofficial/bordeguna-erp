import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { ReversalNotice, ReverseDocumentForm, type Reversal } from '@/components/purchasing/reverse-document';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * The receipts behind one voucher, and the way to add another.
 *
 * ⚠⚠ "No receipt" IS THE MESSAGE. A voucher with nothing attached says so in
 * plain words rather than showing an empty space, because an empty space reads
 * as "nothing to see" when it actually means "this payment has no proof".
 */
function EvidenceCell({ voucher, canRecord }: { voucher: Voucher; canRecord: boolean }) {
    const [busy, setBusy] = useState(false);

    const upload = (file: File | undefined) => {
        if (!file) return;
        setBusy(true);
        router.post(
            `/purchasing/expenses/${voucher.id}/evidence`,
            { file },
            { preserveScroll: true, forceFormData: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <div className="space-y-1">
            {voucher.evidence.length === 0 ? (
                <p className="text-destructive text-xs">No receipt</p>
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
                                onClick={() => router.delete(`/purchasing/expenses/${voucher.id}/evidence/${file.id}`, { preserveScroll: true })}
                            >
                                remove
                            </button>
                        )}
                    </div>
                ))
            )}

            {canRecord && (
                <label className="text-muted-foreground hover:text-foreground block cursor-pointer text-xs underline">
                    {busy ? 'Uploading…' : 'Attach receipt'}
                    <input
                        type="file"
                        className="hidden"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.csv,.xls,.xlsx"
                        onChange={(e) => upload(e.target.files?.[0])}
                    />
                </label>
            )}

            <a href={`/purchasing/expenses/${voucher.id}/print`} className="block text-xs underline">
                Print voucher
            </a>
        </div>
    );
}

interface VoucherLine {
    id: number;
    account: string | null;
    amount: string;
    description: string | null;
}

interface Voucher {
    id: number;
    voucher_number: string;
    /** ⚠ A plain Y-m-d from the server. The raw `spent_on` date cast serialises
     *  in UTC and would render a Dhaka expense one day early. */
    spent_on: string | null;
    paid_from: string | null;
    payee: string;
    total: string;
    reference: string | null;
    note: string | null;
    lines: VoucherLine[];
    evidence: { id: number; name: string; uploaded_by: string | null; url: string }[];
    /** PUR-003 (14 Oct 2026): null while the voucher stands. */
    reversal: Reversal | null;
}

/**
 * PUR-003 (owner decision 14 Oct 2026): a voucher is corrected by reversing it,
 * with a reason. A reversed one says so, with who, when and why.
 */
function ReversalCell({ voucher, canRecord }: { voucher: Voucher; canRecord: boolean }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    if (voucher.reversal) {
        return (
            <Dialog>
                <DialogTrigger asChild>
                    <Badge variant="destructive" className="mt-1 cursor-pointer">
                        {t('reverse.badge')}
                    </Badge>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{voucher.voucher_number}</DialogTitle>
                    </DialogHeader>
                    <ReversalNotice reversal={voucher.reversal} />
                </DialogContent>
            </Dialog>
        );
    }

    if (!canRecord) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="link" size="sm" className="h-auto px-0 text-xs">
                    {t('reverse.submit')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{voucher.voucher_number}</DialogTitle>
                </DialogHeader>
                <ReverseDocumentForm
                    kind="expense"
                    action={`/purchasing/expenses/${voucher.id}/reverse`}
                    originalDate={voucher.spent_on ?? ''}
                    idPrefix={`reverse-expense-${voucher.id}`}
                    onDone={() => setOpen(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

/** The prediction carried over from a due standing cost. */
interface Prefill {
    occurrence_id: number;
    label: string;
    spent_on: string;
    money_account_id: string;
    supplier_id: string;
    reference: string;
    expense_account_id: string;
    amount: string;
    description: string;
}

interface Props {
    vouchers: Paginated<Voucher>;
    filters: { search: string };
    prefill: Prefill | null;
    canRecord: boolean;
    expenseAccounts: { id: number; label: string }[];
    moneyAccounts: { id: number; name: string; type: string }[];
    suppliers: { id: number; name: string }[];
    today: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Expenses', href: '/purchasing/expenses' },
];

const EMPTY_LINE = { account_id: '', amount: '', description: '' };

/**
 * Recording an expense, behind a button.
 *
 * Joy, 1 September 2026: "here will button i click it will a pop up come will
 * add and it will be listed."
 *
 * ⚠ The form used to sit permanently open across the top half of the page, so
 * the screen shouted "fill me in" at somebody who had come to read the list.
 * The restyle's own rule already said the primary action belongs in the heading
 * row; this page was the one that never followed it, which is why it was the
 * page Joy disliked most.
 *
 * ⚠⚠ A dialog here is only possible because of the pointer-events fix earlier
 * today: until then every picker inside every dialog was unclickable, and this
 * form is four pickers deep. See PortalledOverlayIsClickableTest.
 */
function RecordExpenseDialog({ expenseAccounts, moneyAccounts, suppliers, today, prefill }: Omit<Props, 'vouchers' | 'canRecord' | 'filters'>) {
    // ⚠⚠ Opens ALREADY OPEN when the person arrived from a due standing cost.
    // They pressed "Record payment" one screen ago; making them press a second
    // button to see the form they asked for would be the software forgetting
    // what it was just told.
    const [open, setOpen] = useState(prefill !== null);

    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({
        money_account_id: prefill?.money_account_id ?? '',
        spent_on: prefill?.spent_on ?? today,
        supplier_id: prefill?.supplier_id ?? '',
        paid_to: '',
        reference: prefill?.reference ?? '',
        note: '',
        // ⚠ Every value here is EDITABLE, and the amount most of all. The
        // schedule predicts; the voucher must state what actually left, which
        // for anything billed in dollars is never the same figure twice.
        lines: [
            prefill === null
                ? { ...EMPTY_LINE }
                : { account_id: prefill.expense_account_id, amount: prefill.amount, description: prefill.description },
        ],
        // Carried through so saving settles the month it answers.
        recurring_occurrence_id: prefill?.occurrence_id ?? '',
    });

    const setLine = (index: number, key: keyof typeof EMPTY_LINE, value: string) =>
        setData(
            'lines',
            data.lines.map((line, i) => (i === index ? { ...line, [key]: value } : line)),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('purchasing.expenses.store'), {
            preserveScroll: true,
            // ⚠ Closing only on SUCCESS. A dialog that shuts on a failed save
            // takes the refusal away with it, and the person retypes the whole
            // voucher wondering what happened.
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    // Shown live so the person entering it can check it against the receipt in
    // their hand before anything is posted.
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
                <Button size="sm">Record expense</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-3xl" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{prefill === null ? 'Record an expense' : `Record ${prefill.label}`}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="spent_on" required>
                                Date the money left
                            </Label>
                            <Input
                                id="spent_on"
                                type="date"
                                aria-required
                                value={data.spent_on}
                                onChange={(e) => setData('spent_on', e.target.value)}
                            />
                            <InputError message={errors.spent_on} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="money_account_id" required>
                                Paid from
                            </Label>
                            {/*
                              ⚠⚠ Only accounts that already have a ledger account appear
                              here. An unmapped one is refused by the engine with an error
                              naming an account nobody remembers creating, so it is not
                              offered at all. The bank & cash screen warns about them.
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
                            <Label htmlFor="supplier_id">Supplier (optional)</Label>
                            <SearchSelect id="supplier_id" value={data.supplier_id} onChange={(e) => setData('supplier_id', e.target.value)}>
                                <option value="">Not a supplier</option>
                                {suppliers.map((supplier) => (
                                    <option key={supplier.id} value={supplier.id}>
                                        {supplier.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.supplier_id} />
                        </div>

                        <div className="grid gap-2">
                            {/* A landlord or an electricity board is not a supplier of
                                goods and should not have to become one to be paid. */}
                            <Label htmlFor="paid_to">Paid to</Label>
                            <Input
                                id="paid_to"
                                value={data.paid_to}
                                onChange={(e) => setData('paid_to', e.target.value)}
                                placeholder="e.g. Adabor landlord"
                            />
                            <InputError message={errors.paid_to} />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="reference">Reference</Label>
                            <Input
                                id="reference"
                                value={data.reference}
                                onChange={(e) => setData('reference', e.target.value)}
                                placeholder="Receipt or transfer number, or your own, e.g. NIKOLA-2026-09"
                            />
                            <InputError message={errors.reference} />
                        </div>
                    </div>

                    <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-md border p-3">
                        <Label required>What it was spent on</Label>

                        {data.lines.map((line, index) => (
                            <div key={index} className="grid gap-2">
                                <div className="grid gap-2 sm:grid-cols-[1fr_8rem]">
                                    <SearchSelect
                                        newLedger={{ types: ['expense'], reload: ['expenseAccounts'] }}
                                        aria-label={`Expense account, line ${index + 1}`}
                                        value={line.account_id}
                                        onChange={(e) => setLine(index, 'account_id', e.target.value)}
                                    >
                                        <option value="">Choose an expense account</option>
                                        {expenseAccounts.map((account) => (
                                            <option key={account.id} value={account.id}>
                                                {account.label}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                    <Input
                                        aria-label={`Amount, line ${index + 1}`}
                                        aria-required
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
                                <InputError message={errors[`lines.${index}.amount` as keyof typeof errors]} />
                                <InputError message={errors[`lines.${index}.account` as keyof typeof errors]} />
                            </div>
                        ))}

                        <InputError message={errors.lines} />

                        <div>
                            <Button type="button" variant="ghost" size="sm" onClick={() => setData('lines', [...data.lines, { ...EMPTY_LINE }])}>
                                Add another line
                            </Button>
                        </div>
                    </div>
                </form>

                <DialogFooter className="sm:justify-between">
                    <span className="text-muted-foreground self-center text-sm">
                        Total <span className="text-foreground font-medium tabular-nums">{total.toFixed(2)}</span>
                    </span>
                    <div className="flex gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button disabled={processing} onClick={submit}>
                            {processing ? 'Recording…' : 'Record expense'}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * ⚠ The dates filter the FILE, not the list on screen, so they live here rather
 * than in a visit: picking one must not reload the page.
 */
function ExportDialog() {
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');

    const url = (() => {
        const params = new URLSearchParams();
        if (from) params.set('from', from);
        if (to) params.set('to', to);
        const query = params.toString();

        return `/purchasing/expenses/export${query ? `?${query}` : ''}`;
    })();

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    Export
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>Export expenses</DialogTitle>
                </DialogHeader>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="export-from">From</Label>
                        <Input id="export-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="export-to">To</Label>
                        <Input id="export-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    {/* ⚠ A plain anchor, not a router visit: this is a file download,
                        and Inertia would try to render the CSV as a page. */}
                    <Button asChild>
                        <a href={url}>Download CSV</a>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function ExpensesIndex({ vouchers, filters, canRecord, expenseAccounts, moneyAccounts, suppliers, today, prefill }: Props) {
    // ⚠ The header search links here with `?search=NIKOLA-2026-09`. Until
    // 6 Sep 2026 the controller ignored it AND nothing on the page said the
    // list was filtered, so a search result looked like a broken search. The
    // controller now filters; this is the half that tells the reader why.
    const [search, setSearch] = useState(filters?.search ?? '');

    // ⚠ Read from the SERVER's filters, not from the input box. The box holds
    // whatever is being typed right now; what emptied the table is what the
    // server was actually asked for.
    const isFiltered = (filters?.search ?? '') !== '';

    const applySearch = (value: string) => {
        setSearch(value);
        router.get('/purchasing/expenses', value.trim() === '' ? {} : { search: value.trim() }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expenses" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Expenses"
                    actions={
                        <>
                            <input
                                type="search"
                                value={search}
                                onChange={(e) => applySearch(e.target.value)}
                                placeholder="Search number, reference, payee"
                                aria-label="Search expenses"
                                className="border-input bg-background h-8 w-56 rounded-md border px-2 text-sm"
                            />
                            {/* The engine's rendering: PDF, Excel, CSV and a printable
                                page with letterhead and signature blocks. The quick CSV
                                dialog stays for a fast date-ranged pull. */}
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/hrm/reports/purchasing_expense_register">Full report</Link>
                            </Button>
                            <ExportDialog />
                            {canRecord && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={route('purchasing.expenses.import')}>Import a file</Link>
                                </Button>
                            )}
                            {canRecord && (
                                <RecordExpenseDialog
                                    expenseAccounts={expenseAccounts}
                                    moneyAccounts={moneyAccounts}
                                    suppliers={suppliers}
                                    today={today}
                                    prefill={prefill}
                                />
                            )}
                        </>
                    }
                />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[52rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Voucher</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Paid to</th>
                                <th className="p-3">Paid from</th>
                                <th className="p-3">Spent on</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3">Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            {vouchers.data.map((voucher) => (
                                <tr key={voucher.id} className="border-t align-top">
                                    <td className="p-3 font-medium">
                                        <span className={voucher.reversal ? 'text-muted-foreground line-through' : undefined}>
                                            {voucher.voucher_number}
                                        </span>
                                        <div>
                                            <ReversalCell voucher={voucher} canRecord={canRecord} />
                                        </div>
                                    </td>
                                    <td className="p-3 whitespace-nowrap">{voucher.spent_on ?? '-'}</td>
                                    <td className="p-3">{voucher.payee}</td>
                                    <td className="p-3">{voucher.paid_from ?? '-'}</td>
                                    <td className="text-muted-foreground p-3">
                                        {voucher.lines.map((line) => (
                                            <div key={line.id}>
                                                {line.account ?? '-'}
                                                {line.description ? ` - ${line.description}` : ''}{' '}
                                                <span className="tabular-nums">{formatMoney(line.amount)}</span>
                                            </div>
                                        ))}
                                    </td>
                                    <td className="p-3 text-right font-medium tabular-nums">{formatMoney(voucher.total)}</td>
                                    <td className="p-3">
                                        <EvidenceCell voucher={voucher} canRecord={canRecord} />
                                    </td>
                                </tr>
                            ))}
                            {vouchers.data.length === 0 && (
                                <tr>
                                    {/* ⚠ colSpan matches the SEVEN headers above. It said six,
                                        so the empty message sat short of the table's width. */}
                                    <td colSpan={7} className="text-muted-foreground p-8 text-center">
                                        {/* ⚠ An empty table under a FILTER means "nothing matched",
                                            not "nothing exists". Saying the latter sent somebody
                                            looking for a missing voucher that was simply filtered
                                            out, and made a working search read as lost data. */}
                                        {isFiltered ? (
                                            <>
                                                <p>Nothing matches what you are looking for.</p>
                                                <p className="mt-1 text-xs">Clear the filters to see every expense.</p>
                                            </>
                                        ) : (
                                            <>
                                                <p>No expenses recorded yet.</p>
                                                {canRecord && <p className="mt-1 text-xs">Use “Record expense” above to add the first one.</p>}
                                            </>
                                        )}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={vouchers} />
            </div>
        </AppLayout>
    );
}
