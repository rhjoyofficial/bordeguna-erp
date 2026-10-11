import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Statement {
    id: number;
    courier: string | null;
    provider: string;
    format: string | null;
    statement_reference: string;
    statement_date: string;
    status: 'draft' | 'posted';
    source_filename: string | null;
    has_source: boolean;
    received_into: string | null;
    deposit_amount: string | null;
    received_on: string | null;
    bank_reference: string | null;
    deposit_difference_reason: string | null;
    payable_settled_amount: string | null;
    proof: { name: string; url: string } | null;
}

interface Line {
    id: number;
    row_number: number;
    consignment_number: string;
    merchant_reference: string | null;
    // ours: this business's parcel · elsewhere: another business's · unlisted: nobody's in the ERP
    state: 'ours' | 'elsewhere' | 'unlisted';
    resolution: 'mine' | 'not_mine' | null;
    included: boolean;
    problem: string | null;
    note: string | null;
    suggestion: string | null;
    match_method: 'consignment' | 'merchant_reference' | null;
    erp_consignment: string | null;
    consignment_status: string | null;
    courier_status: string | null;
    hub_weight_grams: number | null;
    collected: string;
    erp_collected: string | null;
    delivery_fee: string;
    discount: string;
    charge: string;
    cod_fee: string;
    additional: string;
    compensation: string;
    adjustment: string;
    payout: string;
}

interface RowError {
    row: number;
    consignment: string | null;
    message: string;
}

interface Summary {
    file_total: string;
    share: string;
    collected: string;
    delivery_fees: string;
    return_fees: string;
    additional_charges: string;
    cod_fees: string;
    compensation: string;
    adjustment: string;
    open_payable: string;
    deposit_expected: boolean;
}

interface JournalLine {
    id: number;
    amount_minor: number;
    memo: string | null;
    account: { id: number; code: string; name: string } | null;
    money_account: { id: number; name: string } | null;
}

type Tab = 'all' | 'matched' | 'differences' | 'unmatched';

const taka = (minor: number) => (minor / 100).toLocaleString('en-BD', { minimumFractionDigits: 2 });
const minor = (value: string) => Math.round(parseFloat(value || '0') * 100);
const nonZero = (value: string) => minor(value) !== 0;

const stateLabel = (line: Line) => {
    if (line.state === 'ours') {
        return `Ours${line.consignment_status ? `, ${line.consignment_status.toLowerCase()}` : ''}`;
    }
    if (line.state === 'elsewhere') {
        return 'Another business';
    }
    return line.resolution === 'mine' ? 'Booked by hand, ours' : line.resolution === 'not_mine' ? 'Not ours' : 'Not in the ERP';
};

const isDifference = (line: Line) => line.state === 'ours' && (line.problem !== null || line.note !== null);

export default function CourierStatementShow({
    statement,
    lines,
    rowErrors,
    summary,
    problems,
    can_post,
    can_download_source,
    moneyAccounts,
    journal,
}: {
    statement: Statement;
    lines: Line[];
    rowErrors: RowError[];
    summary: Summary;
    problems: string[];
    can_post: boolean;
    can_download_source: boolean;
    moneyAccounts: { id: number; name: string }[];
    journal: { journal_number: string; lines: JournalLine[] } | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Courier payouts', href: '/sales/courier-statements' },
        { title: statement.statement_reference, href: `/sales/courier-statements/${statement.id}` },
    ];
    const isDraft = statement.status === 'draft';
    const pageErrors = usePage().props.errors as Record<string, string | undefined>;
    const [tab, setTab] = useState<Tab>('all');

    const openPayable = minor(summary.open_payable);
    const shareMinor = minor(summary.share);

    const { data, setData, post, errors, processing } = useForm<{
        received_money_account_id: string;
        deposit_amount: string;
        received_on: string;
        settle_payable: string;
        deposit_difference_reason: string;
        bank_reference: string;
        proof: File | null;
    }>({
        received_money_account_id: moneyAccounts.length === 1 ? String(moneyAccounts[0].id) : '',
        deposit_amount: summary.deposit_expected ? summary.file_total : '',
        received_on: new Date().toLocaleDateString('en-CA'),
        settle_payable: '',
        deposit_difference_reason: '',
        bank_reference: '',
        proof: null,
    });

    // What the bank should show once the courier nets an earlier shortfall.
    const expectedMinor = minor(summary.file_total) - minor(data.settle_payable);
    const depositExpected = expectedMinor > 0;
    const differs = depositExpected && data.deposit_amount !== '' && minor(data.deposit_amount) !== expectedMinor;

    const counts = useMemo(
        () => ({
            all: lines.length,
            matched: lines.filter((l) => l.state === 'ours').length,
            differences: lines.filter(isDifference).length,
            unmatched: lines.filter((l) => l.state !== 'ours').length,
        }),
        [lines],
    );

    const shown = lines.filter((line) =>
        tab === 'matched' ? line.state === 'ours' : tab === 'differences' ? isDifference(line) : tab === 'unmatched' ? line.state !== 'ours' : true,
    );

    const resolve = (line: Line, resolution: 'mine' | 'not_mine') =>
        router.post(route('sales.courier-statements.resolve', [statement.id, line.id]), { resolution }, { preserveScroll: true });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.courier-statements.post', statement.id), { preserveScroll: true, forceFormData: true });
    };

    const settleAll = () => {
        const amount = Math.min(openPayable, Math.max(shareMinor, 0));
        setData((current) => ({
            ...current,
            settle_payable: (amount / 100).toFixed(2),
            deposit_amount: ((minor(summary.file_total) - amount) / 100).toFixed(2),
        }));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Statement ${statement.statement_reference}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-medium">
                            {statement.courier} statement {statement.statement_reference}
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Dated {statement.statement_date}
                            {statement.source_filename ? `, read from ${statement.source_filename}` : ''}
                            {statement.format ? ` (${statement.format})` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('sales.courier-statements.workbook', statement.id)}>Exception workbook</a>
                        </Button>
                        {can_download_source && (
                            <Button variant="ghost" size="sm" asChild>
                                <a href={route('sales.courier-statements.source', statement.id)}>Original file</a>
                            </Button>
                        )}
                        <Badge variant={isDraft ? 'secondary' : 'default'}>{isDraft ? 'Draft' : 'Posted'}</Badge>
                    </div>
                </div>

                <div className="grid gap-3 text-sm sm:grid-cols-3">
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                        <p className="text-muted-foreground">The whole payout</p>
                        <p className="text-lg font-medium tabular-nums">{summary.file_total}</p>
                        <p className="text-muted-foreground text-xs">
                            {lines.length} parcels read
                            {rowErrors.length > 0 ? `, ${rowErrors.length} ${rowErrors.length === 1 ? 'row' : 'rows'} could not be read` : ''}
                        </p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                        <p className="text-muted-foreground">This business&apos;s share</p>
                        <p className="text-lg font-medium tabular-nums">{summary.share}</p>
                        <p className="text-muted-foreground text-xs">
                            Collected {summary.collected}, less delivery {summary.delivery_fees} and returns {summary.return_fees} (net of the
                            courier&apos;s discount), extra charges {summary.additional_charges} and COD fee {summary.cod_fees}
                            {nonZero(summary.compensation) ? `, plus compensation ${summary.compensation}` : ''}
                            {nonZero(summary.adjustment) ? `, adjustments ${summary.adjustment}` : ''}.
                        </p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                        <p className="text-muted-foreground">Money</p>
                        {isDraft ? (
                            <>
                                {!summary.deposit_expected && (
                                    <p className="text-xs">
                                        This statement pays nothing out.{' '}
                                        {shareMinor < 0
                                            ? 'The shortfall is recorded as owed to the courier (Payable to Couriers).'
                                            : 'The share stays with the courier.'}
                                    </p>
                                )}
                                {openPayable > 0 && (
                                    <p className="text-xs">
                                        The books show {summary.open_payable} owed to {statement.courier} from earlier statements.
                                    </p>
                                )}
                            </>
                        ) : statement.received_into ? (
                            <div className="text-sm">
                                <p>
                                    {statement.deposit_amount} arrived in {statement.received_into} on {statement.received_on}
                                    {statement.bank_reference ? `, reference ${statement.bank_reference}` : ''}
                                </p>
                                {statement.payable_settled_amount && (
                                    <p className="text-muted-foreground text-xs">
                                        {statement.payable_settled_amount} of an earlier shortfall was netted off by the courier.
                                    </p>
                                )}
                                {statement.deposit_difference_reason && (
                                    <p className="text-muted-foreground text-xs">Differed from the file: {statement.deposit_difference_reason}</p>
                                )}
                                {statement.proof && (
                                    <a href={statement.proof.url} className="text-xs underline underline-offset-4" target="_blank" rel="noreferrer">
                                        Proof: {statement.proof.name}
                                    </a>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm">
                                {shareMinor < 0
                                    ? 'Nothing arrived; the shortfall is owed to the courier.'
                                    : 'Nothing arrived; the share stays with the courier.'}
                            </p>
                        )}
                    </div>
                </div>

                {isDraft && problems.length > 0 && (
                    <div className="border-destructive/50 bg-destructive/10 rounded-md border p-3 text-sm">
                        <p className="font-medium">Before this can be posted</p>
                        <ul className="mt-1 list-disc pl-5">
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    </div>
                )}
                <InputError message={pageErrors.statement} />

                {rowErrors.length > 0 && (
                    <div className="overflow-x-auto rounded-lg border">
                        <div className="flex flex-wrap items-center justify-between gap-2 p-3">
                            <p className="font-medium">Rows the file could not be read on</p>
                            <Button variant="outline" size="sm" asChild>
                                <a href={route('sales.courier-statements.errors', statement.id)}>Download error report</a>
                            </Button>
                        </div>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Row</th>
                                    <th className="p-3">Consignment</th>
                                    <th className="p-3">Problem</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rowErrors.map((error) => (
                                    <tr key={`${error.row}-${error.consignment}`} className="border-t">
                                        <td className="p-3 tabular-nums">{error.row}</td>
                                        <td className="p-3">{error.consignment ?? '-'}</td>
                                        <td className="text-destructive p-3">{error.message}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="flex flex-wrap gap-2" role="tablist">
                    {(
                        [
                            ['all', 'All'],
                            ['matched', 'Matched'],
                            ['differences', 'Differences'],
                            ['unmatched', 'Not matched'],
                        ] as [Tab, string][]
                    ).map(([key, label]) => (
                        <Button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={tab === key}
                            size="sm"
                            variant={tab === key ? 'default' : 'outline'}
                            onClick={() => setTab(key)}
                        >
                            {label} ({counts[key]})
                        </Button>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Consignment</th>
                                <th className="p-3">Whose</th>
                                <th className="p-3 text-right">Collected</th>
                                <th className="p-3 text-right">Fee</th>
                                <th className="p-3 text-right">Net fee</th>
                                <th className="p-3 text-right">COD fee</th>
                                <th className="p-3 text-right">Other</th>
                                <th className="p-3 text-right">Payout</th>
                                {isDraft && <th className="p-3"></th>}
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((line) => (
                                <tr key={line.id} className={`border-t align-top ${line.included ? '' : 'text-muted-foreground'}`}>
                                    <td className="p-3">
                                        <span className="font-medium">{line.consignment_number}</span>
                                        {line.merchant_reference && (
                                            <span className="text-muted-foreground block text-xs">{line.merchant_reference}</span>
                                        )}
                                        {(line.courier_status || line.hub_weight_grams !== null) && (
                                            <span className="text-muted-foreground block text-xs">
                                                {line.courier_status ?? ''}
                                                {line.courier_status && line.hub_weight_grams !== null ? ', ' : ''}
                                                {line.hub_weight_grams !== null ? `hub weight ${line.hub_weight_grams} g` : ''}
                                            </span>
                                        )}
                                        {line.problem && <span className="text-destructive block text-xs">{line.problem}</span>}
                                        {line.note && <span className="block text-xs text-amber-700 dark:text-amber-400">{line.note}</span>}
                                        {line.suggestion && <span className="text-muted-foreground block text-xs italic">{line.suggestion}</span>}
                                    </td>
                                    <td className="p-3">
                                        {stateLabel(line)}
                                        {line.match_method === 'merchant_reference' && (
                                            <span className="text-muted-foreground block text-xs">matched on order reference</span>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {line.collected}
                                        {line.erp_collected !== null && line.erp_collected !== line.collected && (
                                            <span className="text-destructive block text-xs">ERP {line.erp_collected}</span>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {line.delivery_fee}
                                        {nonZero(line.discount) && <span className="text-muted-foreground block text-xs">less {line.discount}</span>}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{line.charge}</td>
                                    <td className="p-3 text-right tabular-nums">{line.cod_fee}</td>
                                    <td className="p-3 text-right text-xs tabular-nums">
                                        {nonZero(line.additional) && <span className="block">extra {line.additional}</span>}
                                        {nonZero(line.compensation) && <span className="block">compensation {line.compensation}</span>}
                                        {nonZero(line.adjustment) && <span className="block">adjustment {line.adjustment}</span>}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{line.payout}</td>
                                    {isDraft && (
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {line.state === 'unlisted' && (
                                                <>
                                                    <Button
                                                        size="sm"
                                                        variant={line.resolution === 'mine' ? 'default' : 'ghost'}
                                                        onClick={() => resolve(line, 'mine')}
                                                    >
                                                        Ours
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant={line.resolution === 'not_mine' ? 'default' : 'ghost'}
                                                        onClick={() => resolve(line, 'not_mine')}
                                                    >
                                                        Not ours
                                                    </Button>
                                                </>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {shown.length === 0 && (
                                <tr>
                                    <td colSpan={isDraft ? 9 : 8} className="text-muted-foreground p-6 text-center">
                                        Nothing here.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {isDraft && (
                    <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-md border p-4">
                        {openPayable > 0 && shareMinor > 0 && (
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid gap-1">
                                    <Label>Settle against what we owe the courier</Label>
                                    <Input
                                        className="w-36"
                                        value={data.settle_payable}
                                        placeholder="0.00"
                                        onChange={(e) => setData('settle_payable', e.target.value)}
                                    />
                                    <span className="text-muted-foreground text-xs">
                                        Up to {taka(Math.min(openPayable, shareMinor))}, when the courier took it out of this payout.
                                    </span>
                                    <InputError message={errors.settle_payable} />
                                </div>
                                <Button type="button" variant="outline" size="sm" onClick={settleAll}>
                                    Settle in full
                                </Button>
                            </div>
                        )}

                        {depositExpected && (
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid gap-1">
                                    <Label required>Arrived in</Label>
                                    <SearchableSelect
                                        value={data.received_money_account_id}
                                        onChange={(v) => setData('received_money_account_id', v)}
                                        options={moneyAccounts.map((a) => ({ value: String(a.id), label: a.name }))}
                                        placeholder="Select account"
                                        searchPlaceholder="Search accounts…"
                                    />
                                    <InputError message={errors.received_money_account_id} />
                                </div>
                                <div className="grid gap-1">
                                    <Label required>Amount that arrived</Label>
                                    <Input className="w-36" value={data.deposit_amount} onChange={(e) => setData('deposit_amount', e.target.value)} />
                                    <span className="text-muted-foreground text-xs">Expected {taka(expectedMinor)}</span>
                                    <InputError message={errors.deposit_amount} />
                                </div>
                                <div className="grid gap-1">
                                    <Label required>On</Label>
                                    <Input
                                        type="date"
                                        className="w-40"
                                        value={data.received_on}
                                        onChange={(e) => setData('received_on', e.target.value)}
                                    />
                                    <InputError message={errors.received_on} />
                                </div>
                                <div className="grid gap-1">
                                    <Label>Bank reference</Label>
                                    <Input className="w-44" value={data.bank_reference} onChange={(e) => setData('bank_reference', e.target.value)} />
                                    <InputError message={errors.bank_reference} />
                                </div>
                                <div className="grid gap-1">
                                    <Label>Proof (optional)</Label>
                                    <Input
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                                        onChange={(e) => setData('proof', e.target.files?.[0] ?? null)}
                                    />
                                    <InputError message={errors.proof} />
                                </div>
                            </div>
                        )}

                        {differs && (
                            <div className="grid max-w-xl gap-1">
                                <Label required>Why the amount differs from the file</Label>
                                <Input
                                    value={data.deposit_difference_reason}
                                    placeholder="e.g. courier held back 340 for an earlier invoice"
                                    onChange={(e) => setData('deposit_difference_reason', e.target.value)}
                                />
                                <span className="text-muted-foreground text-xs">
                                    Less than expected stays as owed by the courier; more is recorded as owed back to it.
                                </span>
                                <InputError message={errors.deposit_difference_reason} />
                            </div>
                        )}

                        <div className="flex flex-wrap items-center gap-3">
                            <Button disabled={processing || !can_post}>Post statement</Button>
                            <ConfirmDialog
                                trigger={
                                    <Button type="button" variant="ghost">
                                        Discard draft
                                    </Button>
                                }
                                title={`Discard ${statement.statement_reference}`}
                                description="The draft, its file and its decisions are removed. Nothing had been posted, so nothing else changes."
                                confirmLabel="Discard"
                                destructive
                                onConfirm={() => router.delete(route('sales.courier-statements.destroy', statement.id))}
                            />
                        </div>
                    </form>
                )}

                {journal && (
                    <div className="overflow-x-auto rounded-lg border">
                        <p className="p-3 font-medium">What this did to the books ({journal.journal_number})</p>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Account</th>
                                    <th className="p-3">Courier or bank</th>
                                    <th className="p-3">Memo</th>
                                    <th className="p-3 text-right">Debit</th>
                                    <th className="p-3 text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                {journal.lines.map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="p-3">
                                            {line.account?.code} {line.account?.name}
                                        </td>
                                        <td className="text-muted-foreground p-3">{line.money_account?.name ?? ''}</td>
                                        <td className="text-muted-foreground p-3">{line.memo}</td>
                                        <td className="p-3 text-right tabular-nums">{line.amount_minor > 0 ? taka(line.amount_minor) : ''}</td>
                                        <td className="p-3 text-right tabular-nums">{line.amount_minor < 0 ? taka(-line.amount_minor) : ''}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
