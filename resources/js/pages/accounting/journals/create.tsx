import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo } from 'react';

interface AccountOption {
    id: number;
    label: string;
    type_label: string;
    /** Owned by Payroll, Inventory, Purchasing or Fixed Assets. */
    system_owned: boolean;
}

interface TypeOption {
    value: string;
    label: string;
}

/**
 * The index signature is what lets Inertia's useForm accept an array of these:
 * it requires every value in the form to be convertible to form data, and a
 * plain interface has no index signature to satisfy that with.
 */
interface Line {
    account_id: string;
    debit: string;
    credit: string;
    memo: string;
    [key: string]: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Journals', href: '/accounting/journals' },
    { title: 'New journal', href: '/accounting/journals/new/manual' },
];

/**
 * Declared explicitly rather than inferred. Left to infer from the initial
 * value, `lines` widens to Inertia's FormDataConvertible union and every read of
 * it stops type-checking.
 */
interface JournalForm {
    journal_date: string;
    memo: string;
    journal_type: string;
    lines: Line[];
    [key: string]: string | Line[];
}

const emptyLine = (): Line => ({ account_id: '', debit: '', credit: '', memo: '' });

/**
 * Parses only well enough to show a running total. The server does the real
 * parsing in whole paisa, and it is the server's answer that decides whether
 * the journal balances.
 */
function toNumber(value: string): number {
    const parsed = Number(value.replace(/,/g, '').trim());
    return Number.isFinite(parsed) ? parsed : 0;
}

export default function CreateJournal({ accounts, types, today }: { accounts: AccountOption[]; types: TypeOption[]; today: string }) {
    const { data, setData, post, errors, processing } = useForm<JournalForm>({
        journal_date: today,
        memo: '',
        journal_type: 'manual',
        lines: [emptyLine(), emptyLine()],
    });

    const totals = useMemo(() => {
        const debit = data.lines.reduce((sum, line) => sum + toNumber(line.debit), 0);
        const credit = data.lines.reduce((sum, line) => sum + toNumber(line.credit), 0);
        return { debit, credit, difference: debit - credit };
    }, [data.lines]);

    const balanced = Math.abs(totals.difference) < 0.005 && totals.debit > 0;

    const setLine = (index: number, field: keyof Line, value: string) => {
        const lines = data.lines.map((line, i) => (i === index ? { ...line, [field]: value } : line));
        setData('lines', lines);
    };

    const addLine = () => setData('lines', [...data.lines, emptyLine()]);

    const removeLine = (index: number) => {
        if (data.lines.length <= 2) return;
        setData(
            'lines',
            data.lines.filter((_, i) => i !== index),
        );
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('accounting.journals.store'));
    };

    const money = (value: number) => value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New journal" />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">New journal (direct)</h1>
                    <p className="mt-1 rounded-md border border-dashed px-3 py-2 text-sm">
                        Since 26 Sep 2026 the everyday way is a{' '}
                        <Link href="/accounting/vouchers/journal/create" className="underline">
                            Journal voucher
                        </Link>
                        : drafted, numbered when posted, printable, reversed and copied, and it names the employee, customer or supplier on a party
                        ledger. This screen posts straight to the ledger and is kept for the Owner.
                    </p>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="journal_date" required>
                            Date
                        </Label>
                        <Input id="journal_date" type="date" value={data.journal_date} onChange={(e) => setData('journal_date', e.target.value)} />
                        <InputError message={errors.journal_date} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="journal_type" required>
                            Kind
                        </Label>
                        <SearchSelect fit id="journal_type" value={data.journal_type} onChange={(e) => setData('journal_type', e.target.value)}>
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.journal_type} />
                    </div>
                    <div className="grid flex-1 gap-2">
                        <Label htmlFor="memo">What this is for</Label>
                        <Input
                            id="memo"
                            value={data.memo}
                            onChange={(e) => setData('memo', e.target.value)}
                            placeholder="e.g. Capital introduced by the Founder"
                        />
                        <InputError message={errors.memo} />
                    </div>
                </div>

                <div className="overflow-x-auto">
                    {/* ⚠ table-fixed + colgroup: an auto table sized each column from its
                        content, so the amount boxes sat at the left of wide cells under
                        right-aligned headings and the account picker shrank to its
                        placeholder (seen live 12 Oct 2026). */}
                    <table className="w-full min-w-[46rem] table-fixed text-sm">
                        <colgroup>
                            <col className="w-[38%]" />
                            <col />
                            <col className="w-40" />
                            <col className="w-40" />
                            <col className="w-24" />
                        </colgroup>
                        <thead>
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="py-2 pr-3 font-medium">
                                    Account<span className="text-destructive ml-0.5 font-semibold">*</span>
                                </th>
                                <th className="py-2 pr-3 font-medium">Note</th>
                                <th className="py-2 pr-3 text-right font-medium">Debit</th>
                                <th className="py-2 pr-3 text-right font-medium">Credit</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.lines.map((line, index) => (
                                <tr key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                                    <td className="py-2 pr-3">
                                        <SearchSelect
                                            newLedger={{ types: ['asset', 'liability', 'equity', 'income', 'expense'], reload: ['accounts'] }}
                                            aria-label={`Account for line ${index + 1}`}
                                            value={line.account_id}
                                            onChange={(e) => setLine(index, 'account_id', e.target.value)}
                                        >
                                            <option value="">Choose an account</option>
                                            {accounts.map((account) => (
                                                <option
                                                    key={account.id}
                                                    value={account.id}
                                                    // Owned accounts stay selectable for an opening
                                                    // balance, which is the one entry that
                                                    // legitimately sets them by hand.
                                                    disabled={account.system_owned && data.journal_type !== 'opening_balance'}
                                                >
                                                    {account.label}
                                                    {account.system_owned ? ' - maintained by another module' : ''}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                        <InputError message={errors[`lines.${index}.account_id` as keyof typeof errors]} />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <Input
                                            aria-label={`Note for line ${index + 1}`}
                                            value={line.memo}
                                            onChange={(e) => setLine(index, 'memo', e.target.value)}
                                            placeholder="Optional"
                                            className="h-9 w-full"
                                        />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <Input
                                            aria-label={`Debit for line ${index + 1}`}
                                            inputMode="decimal"
                                            value={line.debit}
                                            onChange={(e) => setLine(index, 'debit', e.target.value)}
                                            placeholder="0.00"
                                            className="h-9 w-full text-right tabular-nums"
                                        />
                                        <InputError message={errors[`lines.${index}.debit` as keyof typeof errors]} />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <Input
                                            aria-label={`Credit for line ${index + 1}`}
                                            inputMode="decimal"
                                            value={line.credit}
                                            onChange={(e) => setLine(index, 'credit', e.target.value)}
                                            placeholder="0.00"
                                            className="h-9 w-full text-right tabular-nums"
                                        />
                                        <InputError message={errors[`lines.${index}.credit` as keyof typeof errors]} />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Button
                                            className="text-muted-foreground"
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={data.lines.length <= 2}
                                            onClick={() => removeLine(index)}
                                            title={data.lines.length <= 2 ? 'A journal needs at least two lines' : undefined}
                                        >
                                            Remove
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="font-semibold">
                                <td className="py-3 pr-3" colSpan={2}>
                                    Total
                                </td>
                                {/* pr-6 = the column's pr-3 plus the input's own px-3, so the
                                    figures end exactly where the typed amounts end. */}
                                <td className="py-3 pr-6 text-right tabular-nums">{money(totals.debit)}</td>
                                <td className="py-3 pr-6 text-right tabular-nums">{money(totals.credit)}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div className="flex flex-wrap items-center gap-4">
                    <Button type="button" variant="outline" onClick={addLine}>
                        Add a line
                    </Button>

                    {/* Nothing typed yet is not an error: red only once the two sides differ. */}
                    <span
                        className={`text-sm font-medium tabular-nums ${
                            balanced
                                ? 'text-emerald-700 dark:text-emerald-400'
                                : totals.debit === 0 && totals.credit === 0
                                  ? 'text-muted-foreground'
                                  : 'text-destructive'
                        }`}
                    >
                        {balanced
                            ? 'Balanced'
                            : totals.debit === 0 && totals.credit === 0
                              ? 'Enter the amounts'
                              : `Out by ${money(Math.abs(totals.difference))}`}
                    </span>

                    <Button disabled={processing}>Post journal</Button>
                </div>

                {/* The server's refusals land here: unbalanced, a closed month, a
                    heading, a deactivated account, a future date. */}
                <InputError message={errors.lines} className="text-sm" />
            </form>
        </AppLayout>
    );
}
