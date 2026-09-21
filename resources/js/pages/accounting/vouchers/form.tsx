import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useMemo, useRef } from 'react';

export interface VoucherTypeProps {
    value: 'payment' | 'receipt' | 'contra' | 'journal';
    label: string;
    plural: string;
    prefix: string;
    fixed_side: 'debit' | 'credit' | null;
    uses_money_account: boolean;
    has_lines: boolean;
    counterparty_label: string | null;
}

/** Every head of the chart; the ones a line cannot use come greyed, with where they are posted instead. */
interface AccountOption {
    id: number;
    label: string;
    party: 'supplier' | 'customer' | null;
    usable: boolean;
    heading: boolean;
    hint: string | null;
}

/** Every account in Bank & cash; `hint` says why one cannot be chosen here, null when it can. */
interface MoneyOption {
    id: number;
    label: string;
    type: string;
    hint: string | null;
}

interface Option {
    id: number;
    label: string;
}

/** The index signature lets Inertia's useForm accept an array of these. */
interface LineForm {
    [key: string]: string;
    account_id: string;
    side: string;
    amount: string;
    narration: string;
    party_id: string;
}

type VoucherForm = {
    voucher_date: string;
    money_account_id: string;
    to_money_account_id: string;
    counterparty: string;
    narration: string;
    instrument_type: string;
    instrument_number: string;
    instrument_date: string;
    instrument_bank: string;
    amount: string;
    lines: LineForm[];
    post: boolean;
};

interface ExistingVoucher {
    id: number;
    voucher_date: string;
    money_account_id: number | null;
    to_money_account_id: number | null;
    counterparty: string | null;
    narration: string | null;
    instrument_type: string | null;
    instrument_number: string | null;
    instrument_date: string | null;
    instrument_bank: string | null;
    amount: string;
    copied_from: string | null;
    lines: { account_id: number; side: string; amount: string; narration: string | null; party_id: number | null }[];
}

const INSTRUMENT_LABEL: Record<string, string> = {
    cash: 'Cash',
    cheque: 'Cheque',
    bank_transfer: 'Bank transfer',
    mobile: 'bKash / Nagad / Rocket',
    card: 'Card',
    other: 'Other',
};

const MONEY_LABEL: Record<string, [string, string]> = {
    payment: ['Paid from', 'Which cash box, bank or wallet the money left.'],
    receipt: ['Received into', 'Which cash box, bank or wallet the money came into.'],
    contra: ['Moved from', 'The account the money left.'],
};

const blankLine = (side: string): LineForm => ({ account_id: '', side, amount: '', narration: '', party_id: '' });

const toNumber = (value: string) => Number.parseFloat(value) || 0;

const money = (value: number) => value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function VoucherFormPage({
    type,
    voucher,
    today,
    moneyAccounts,
    accounts,
    suppliers,
    customers,
    instrumentTypes,
}: {
    type: VoucherTypeProps;
    voucher: ExistingVoucher | null;
    today: string;
    moneyAccounts: MoneyOption[];
    accounts: AccountOption[];
    suppliers: Option[];
    customers: Option[];
    instrumentTypes: string[];
}) {
    const { canPostVouchers } = usePage<SharedData>().props;
    const defaultSide = type.fixed_side ?? 'debit';

    const { data, setData, post, put, processing, errors, transform } = useForm<VoucherForm>({
        voucher_date: voucher?.voucher_date ?? today,
        money_account_id: voucher?.money_account_id ? String(voucher.money_account_id) : '',
        to_money_account_id: voucher?.to_money_account_id ? String(voucher.to_money_account_id) : '',
        counterparty: voucher?.counterparty ?? '',
        narration: voucher?.narration ?? '',
        instrument_type: voucher?.instrument_type ?? '',
        instrument_number: voucher?.instrument_number ?? '',
        instrument_date: voucher?.instrument_date ?? '',
        instrument_bank: voucher?.instrument_bank ?? '',
        amount: voucher && !type.has_lines ? voucher.amount : '',
        lines:
            voucher && voucher.lines.length > 0
                ? voucher.lines.map((line) => ({
                      account_id: String(line.account_id),
                      side: line.side,
                      amount: line.amount,
                      narration: line.narration ?? '',
                      party_id: line.party_id ? String(line.party_id) : '',
                  }))
                : type.value === 'journal'
                  ? [blankLine('debit'), blankLine('credit')]
                  : [blankLine(defaultSide)],
        post: false,
    });

    const accountById = useMemo(() => new Map(accounts.map((account) => [String(account.id), account])), [accounts]);

    const setLine = (index: number, patch: Partial<LineForm>) =>
        setData(
            'lines',
            data.lines.map((line, i) => (i === index ? ({ ...line, ...patch } as LineForm) : line)),
        );

    const debits = data.lines.filter((l) => l.side === 'debit').reduce((sum, l) => sum + toNumber(l.amount), 0);
    const credits = data.lines.filter((l) => l.side === 'credit').reduce((sum, l) => sum + toNumber(l.amount), 0);
    const total = type.has_lines ? (type.value === 'journal' ? debits : debits + credits) : toNumber(data.amount);
    const gap = Math.round((debits - credits) * 100) / 100;

    // Which button was pressed decides draft or post; the form has one submit path.
    const intent = useRef(false);

    const submit = (andPost: boolean) => {
        intent.current = andPost;
        transform((form) => ({ ...form, post: intent.current }));

        if (voucher) {
            put(route('accounting.vouchers.update', voucher.id), { preserveScroll: true });
        } else {
            post(route('accounting.vouchers.store', type.value), { preserveScroll: true });
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/day-book' },
        { title: type.plural, href: route('accounting.vouchers.index', type.value) },
        { title: voucher ? 'Edit draft' : 'New', href: '#' },
    ];

    const lineError = (index: number, field: string) => (errors as Record<string, string>)[`lines.${index}.${field}`];
    const [moneyLabel, moneyHint] = MONEY_LABEL[type.value] ?? ['', ''];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={voucher ? `Edit draft ${type.label.toLowerCase()}` : `New ${type.label.toLowerCase()}`} />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    submit(false);
                }}
                className="flex max-w-5xl flex-col gap-6 p-4"
            >
                <div>
                    <h1 className="text-lg font-semibold">
                        {voucher ? `Edit draft ${type.label.toLowerCase()}` : `New ${type.label.toLowerCase()}`}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        A draft changes nothing in the books and can be edited or deleted. Posting gives it a {type.prefix} number and writes it to
                        the ledger; after that it can only be reversed.
                        {voucher?.copied_from ? ` Copied from ${voucher.copied_from}.` : ''}
                    </p>
                    <InputError message={(errors as Record<string, string>).voucher} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor="voucher_date" required>
                            Date
                        </Label>
                        <Input id="voucher_date" type="date" value={data.voucher_date} onChange={(e) => setData('voucher_date', e.target.value)} />
                        <InputError message={errors.voucher_date} />
                    </div>

                    {type.uses_money_account && (
                        <div className="grid gap-2">
                            <Label htmlFor="money_account_id" required>
                                {moneyLabel}
                            </Label>
                            <SearchSelect
                                id="money_account_id"
                                value={data.money_account_id}
                                onChange={(e) => setData('money_account_id', e.target.value)}
                            >
                                <option value="">Choose an account</option>
                                {moneyAccounts.map((account) => (
                                    <option key={account.id} value={account.id} disabled={account.hint !== null} data-hint={account.hint}>
                                        {account.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <p className="text-muted-foreground text-xs">
                                {moneyAccounts.every((account) => account.hint !== null) ? (
                                    <>
                                        No cash or bank account can be used yet. Add one in{' '}
                                        <Link href="/accounting/money-accounts" className="underline">
                                            Bank &amp; cash
                                        </Link>
                                        , with "Reports under" set.
                                    </>
                                ) : (
                                    <>
                                        {moneyHint} The list comes from{' '}
                                        <Link href="/accounting/money-accounts" className="underline">
                                            Bank &amp; cash
                                        </Link>
                                        .
                                    </>
                                )}
                            </p>
                            <InputError message={errors.money_account_id} />
                        </div>
                    )}

                    {type.value === 'contra' && (
                        <div className="grid gap-2">
                            <Label htmlFor="to_money_account_id" required>
                                Moved to
                            </Label>
                            <SearchSelect
                                id="to_money_account_id"
                                value={data.to_money_account_id}
                                onChange={(e) => setData('to_money_account_id', e.target.value)}
                            >
                                <option value="">Choose an account</option>
                                {moneyAccounts
                                    .filter((account) => String(account.id) !== data.money_account_id)
                                    .map((account) => {
                                        // Money never moves INTO a gateway by hand: the gateway fills itself from sales.
                                        const hint =
                                            account.hint ??
                                            (account.type === 'gateway' ? 'Online-sale money: it only leaves a gateway, never enters one.' : null);

                                        return (
                                            <option key={account.id} value={account.id} disabled={hint !== null} data-hint={hint}>
                                                {account.label}
                                            </option>
                                        );
                                    })}
                            </SearchSelect>
                            <p className="text-muted-foreground text-xs">
                                Cash deposited, cash withdrawn, or bank to bank. The list comes from{' '}
                                <Link href="/accounting/money-accounts" className="underline">
                                    Bank &amp; cash
                                </Link>
                                .
                            </p>
                            <InputError message={errors.to_money_account_id} />
                        </div>
                    )}

                    {type.counterparty_label && (
                        <div className="grid gap-2">
                            <Label htmlFor="counterparty">{type.counterparty_label}</Label>
                            <Input
                                id="counterparty"
                                value={data.counterparty}
                                onChange={(e) => setData('counterparty', e.target.value)}
                                placeholder="Name as it should print on the voucher"
                            />
                            <InputError message={errors.counterparty} />
                        </div>
                    )}

                    {type.value === 'contra' && (
                        <div className="grid gap-2">
                            <Label htmlFor="amount" required>
                                Amount
                            </Label>
                            <Input
                                id="amount"
                                inputMode="decimal"
                                className="text-right tabular-nums"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                                placeholder="0.00"
                            />
                            <InputError message={errors.amount} />
                        </div>
                    )}
                </div>

                {type.has_lines && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[46rem] text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left">
                                        <th className="px-3 py-2 font-medium">Particulars (ledger head)</th>
                                        <th className="px-3 py-2 font-medium">Narration</th>
                                        {type.value === 'journal' ? (
                                            <>
                                                <th className="w-36 px-3 py-2 text-right font-medium">Debit</th>
                                                <th className="w-36 px-3 py-2 text-right font-medium">Credit</th>
                                            </>
                                        ) : (
                                            <th className="w-40 px-3 py-2 text-right font-medium">Amount</th>
                                        )}
                                        <th className="w-10" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.lines.map((line, index) => {
                                        const party = accountById.get(line.account_id)?.party ?? null;

                                        return (
                                            <tr key={index} className="border-b align-top last:border-b-0">
                                                <td className="px-3 py-2">
                                                    <SearchSelect
                                                        newLedger={{
                                                            types: ['expense', 'asset', 'liability', 'equity', 'income'],
                                                            reload: ['accounts'],
                                                        }}
                                                        value={line.account_id}
                                                        onChange={(e) => setLine(index, { account_id: e.target.value, party_id: '' })}
                                                        invalid={Boolean(lineError(index, 'account_id'))}
                                                    >
                                                        <option value="">Choose a ledger head</option>
                                                        {accounts.map((account) => (
                                                            <option
                                                                key={account.id}
                                                                value={account.id}
                                                                disabled={!account.usable}
                                                                data-heading={account.heading}
                                                                data-hint={account.heading ? null : account.hint}
                                                            >
                                                                {account.label}
                                                            </option>
                                                        ))}
                                                    </SearchSelect>
                                                    <InputError message={lineError(index, 'account_id')} />

                                                    {party && (
                                                        <div className="mt-2">
                                                            <SearchSelect
                                                                value={line.party_id}
                                                                onChange={(e) => setLine(index, { party_id: e.target.value })}
                                                                invalid={Boolean(lineError(index, 'party_id'))}
                                                            >
                                                                <option value="">
                                                                    {party === 'supplier' ? 'Which supplier?' : 'Which customer?'}
                                                                </option>
                                                                {(party === 'supplier' ? suppliers : customers).map((option) => (
                                                                    <option key={option.id} value={option.id}>
                                                                        {option.label}
                                                                    </option>
                                                                ))}
                                                            </SearchSelect>
                                                            <InputError message={lineError(index, 'party_id')} />
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <Input
                                                        value={line.narration}
                                                        onChange={(e) => setLine(index, { narration: e.target.value })}
                                                        placeholder="What this line is for"
                                                    />
                                                </td>
                                                {type.value === 'journal' ? (
                                                    <>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                inputMode="decimal"
                                                                className="text-right tabular-nums"
                                                                value={line.side === 'debit' ? line.amount : ''}
                                                                onChange={(e) => setLine(index, { side: 'debit', amount: e.target.value })}
                                                                placeholder="0.00"
                                                                aria-label={`Debit, line ${index + 1}`}
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                inputMode="decimal"
                                                                className="text-right tabular-nums"
                                                                value={line.side === 'credit' ? line.amount : ''}
                                                                onChange={(e) => setLine(index, { side: 'credit', amount: e.target.value })}
                                                                placeholder="0.00"
                                                                aria-label={`Credit, line ${index + 1}`}
                                                            />
                                                            <InputError message={lineError(index, 'amount') ?? lineError(index, 'side')} />
                                                        </td>
                                                    </>
                                                ) : (
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            inputMode="decimal"
                                                            className="text-right tabular-nums"
                                                            value={line.amount}
                                                            onChange={(e) => setLine(index, { amount: e.target.value })}
                                                            placeholder="0.00"
                                                            aria-label={`Amount, line ${index + 1}`}
                                                        />
                                                        <InputError message={lineError(index, 'amount')} />
                                                    </td>
                                                )}
                                                <td className="px-1 py-2">
                                                    {data.lines.length > (type.value === 'journal' ? 2 : 1) && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Remove line ${index + 1}`}
                                                            onClick={() =>
                                                                setData(
                                                                    'lines',
                                                                    data.lines.filter((_, i) => i !== index),
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t font-semibold">
                                        <td className="px-3 py-2" colSpan={2}>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setData('lines', [...data.lines, blankLine(gap > 0 ? 'credit' : defaultSide)])}
                                            >
                                                Add line
                                            </Button>
                                        </td>
                                        {type.value === 'journal' ? (
                                            <>
                                                <td className="px-3 py-2 text-right tabular-nums">{money(debits)}</td>
                                                <td className="px-3 py-2 text-right tabular-nums">{money(credits)}</td>
                                            </>
                                        ) : (
                                            <td className="px-3 py-2 text-right tabular-nums">{money(total)}</td>
                                        )}
                                        <td />
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        {type.value === 'journal' && gap !== 0 && (
                            <p className="border-t px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                                {gap > 0 ? 'Debits' : 'Credits'} are ahead by {money(Math.abs(gap))}. A journal voucher must balance before it can be
                                posted; it can still be saved as a draft.
                            </p>
                        )}
                        <div className="px-3 pb-2">
                            <InputError message={errors.lines} />
                        </div>
                    </div>
                )}

                {type.uses_money_account && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="instrument_type">Paid by</Label>
                            <SearchSelect
                                id="instrument_type"
                                value={data.instrument_type}
                                onChange={(e) => setData('instrument_type', e.target.value)}
                            >
                                <option value="">Not stated</option>
                                {instrumentTypes.map((value) => (
                                    <option key={value} value={value}>
                                        {INSTRUMENT_LABEL[value] ?? value}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="instrument_number">Cheque / transaction no.</Label>
                            <Input
                                id="instrument_number"
                                value={data.instrument_number}
                                onChange={(e) => setData('instrument_number', e.target.value)}
                            />
                            <InputError message={errors.instrument_number} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="instrument_date">Cheque date</Label>
                            <Input
                                id="instrument_date"
                                type="date"
                                value={data.instrument_date}
                                onChange={(e) => setData('instrument_date', e.target.value)}
                            />
                            <InputError message={errors.instrument_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="instrument_bank">Bank / branch</Label>
                            <Input id="instrument_bank" value={data.instrument_bank} onChange={(e) => setData('instrument_bank', e.target.value)} />
                            <InputError message={errors.instrument_bank} />
                        </div>
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="narration">Narration</Label>
                    <textarea
                        id="narration"
                        rows={2}
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        value={data.narration}
                        onChange={(e) => setData('narration', e.target.value)}
                        placeholder="Being ..."
                    />
                    <InputError message={errors.narration} />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" variant="outline" disabled={processing}>
                        Save draft
                    </Button>
                    {canPostVouchers && (
                        <Button type="button" disabled={processing} onClick={() => submit(true)}>
                            Save and post
                        </Button>
                    )}
                    <Button asChild variant="ghost">
                        <Link href={voucher ? route('accounting.vouchers.show', voucher.id) : route('accounting.vouchers.index', type.value)}>
                            Cancel
                        </Link>
                    </Button>
                    <span className="text-muted-foreground ml-auto text-sm">
                        Total <span className="text-foreground font-semibold tabular-nums">{money(total)}</span>
                    </span>
                </div>
            </form>
        </AppLayout>
    );
}
