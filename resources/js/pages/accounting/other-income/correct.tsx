import { ActionDialog } from '@/components/action-dialog';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

// A type, not an interface: it travels in an Inertia payload (index signature).
type FormLine = {
    account_id: string;
    amount: string;
    description: string;
};

interface Props {
    voucher: {
        id: number;
        voucher_number: string;
        received_on: string;
        money_account_id: string;
        customer_id: string;
        received_from: string;
        reference: string;
        note: string;
        total: string;
        lines: FormLine[];
    };
    availability: {
        allowed: boolean;
        requestable: boolean;
        blockers: { code: string; message: string; url: string | null }[];
        date: { rule: string; sentence: string } | null;
    };
    correctionReasons: { id: number; name: string }[];
    type: string;
    incomeAccounts: { id: number; label: string }[];
    moneyAccounts: { id: number; name: string; type: string }[];
    customers: { id: number; name: string }[];
    today: string;
}

const EMPTY_LINE: FormLine = { account_id: '', amount: '', description: '' };

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Correct a posted Other income voucher (edit, delete and correct, Phase 0,
 * 17 Oct 2026, PHASE-0-CONTRACT 3.2).
 *
 * The record form, pre-filled with every field. Nothing is saved from this
 * page: "Preview and correct" opens the action dialog, which shows the date,
 * the effect and what stays, then reverses the original on its own date and
 * records the new voucher in one go. The original stays, marked Corrected.
 */
export default function CorrectOtherIncome({
    voucher,
    availability,
    correctionReasons,
    type,
    incomeAccounts,
    moneyAccounts,
    customers,
    today,
}: Props) {
    const [form, setForm] = useState({
        received_on: voucher.received_on,
        money_account_id: voucher.money_account_id,
        customer_id: voucher.customer_id,
        received_from: voucher.received_from,
        reference: voucher.reference,
        note: voucher.note,
        lines: voucher.lines.length > 0 ? voucher.lines : [{ ...EMPTY_LINE }],
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/overview' },
        { title: 'Other income', href: '/accounting/other-income' },
        { title: `Correct ${voucher.voucher_number}`, href: `/accounting/other-income/${voucher.id}/correct` },
    ];

    const set = (field: keyof typeof form, value: string) => setForm((previous) => ({ ...previous, [field]: value }));
    const setLine = (index: number, field: keyof FormLine, value: string) =>
        setForm((previous) => ({ ...previous, lines: previous.lines.map((line, i) => (i === index ? { ...line, [field]: value } : line)) }));

    const total = form.lines.reduce((sum, line) => sum + (Number.parseFloat(line.amount) || 0), 0);
    const usable = availability.allowed || availability.requestable;
    const base = `/records/${type}/${voucher.id}`;

    // Memoised: the dialog previews again whenever this changes, and only then.
    const replacement = useMemo(
        () => ({
            ...form,
            customer_id: form.customer_id === '' ? null : form.customer_id,
            lines: form.lines.filter((line) => line.account_id !== '' || line.amount !== ''),
        }),
        [form],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Correct ${voucher.voucher_number}`} />

            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <ReportHeading title={`Correct ${voucher.voucher_number}`} />

                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                    You are correcting {voucher.voucher_number} (BDT {money(Number(voucher.total))}). Change what was wrong. {voucher.voucher_number}{' '}
                    stays, marked Corrected, and a new voucher takes its place.
                    {availability.date?.sentence ? ` ${availability.date.sentence}` : ''}
                </div>

                {!usable &&
                    availability.blockers.map((blocker) => (
                        <p key={blocker.code} className="text-destructive text-sm">
                            {blocker.message}
                        </p>
                    ))}

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="received_on" required>
                            Date the money arrived
                        </Label>
                        <Input
                            id="received_on"
                            type="date"
                            className="w-auto"
                            max={today}
                            value={form.received_on}
                            onChange={(e) => set('received_on', e.target.value)}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="money_account_id" required>
                            Received into
                        </Label>
                        <SearchSelect id="money_account_id" value={form.money_account_id} onChange={(e) => set('money_account_id', e.target.value)}>
                            <option value="">Choose an account</option>
                            {moneyAccounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="customer_id">Customer</Label>
                        <SearchSelect id="customer_id" value={form.customer_id} onChange={(e) => set('customer_id', e.target.value)}>
                            <option value="">Not a customer</option>
                            {customers.map((customer) => (
                                <option key={customer.id} value={customer.id}>
                                    {customer.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="received_from">Received from</Label>
                        <Input id="received_from" value={form.received_from} onChange={(e) => set('received_from', e.target.value)} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input id="reference" value={form.reference} onChange={(e) => set('reference', e.target.value)} />
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-md border p-3">
                    <Label required>What the money was for</Label>

                    {form.lines.map((line, index) => (
                        <div key={index} className="grid gap-2">
                            <div className="grid gap-2 sm:grid-cols-[1fr_8rem]">
                                <SearchSelect
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
                                {form.lines.length > 1 && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setForm((previous) => ({ ...previous, lines: previous.lines.filter((_, i) => i !== index) }))}
                                    >
                                        Remove
                                    </Button>
                                )}
                            </div>
                        </div>
                    ))}

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setForm((previous) => ({ ...previous, lines: [...previous.lines, { ...EMPTY_LINE }] }))}
                        >
                            Add another line
                        </Button>
                        <p className="text-sm">
                            <span className="text-muted-foreground">New total </span>
                            <span className="font-semibold">BDT {money(total)}</span>
                        </p>
                    </div>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="note">Note</Label>
                    {/* ⛔ The one control that IS full width, because a note is prose. */}
                    <textarea
                        id="note"
                        className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                        value={form.note}
                        onChange={(e) => set('note', e.target.value)}
                    />
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href="/accounting/other-income">Go back</Link>
                    </Button>
                    <ActionDialog
                        action="correct"
                        recordLabel={voucher.voucher_number}
                        previewUrl={`${base}/correct/preview`}
                        submitUrl={`${base}/correct`}
                        reasons={correctionReasons}
                        replacement={replacement}
                        redirectTo="/accounting/other-income"
                        trigger={<Button disabled={!usable}>Preview and correct</Button>}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
