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
import { FormEventHandler } from 'react';

interface Statement {
    id: number;
    courier: string | null;
    statement_reference: string;
    statement_date: string;
    status: 'draft' | 'posted';
    source_filename: string | null;
    received_into: string | null;
    deposit_amount: string | null;
    received_on: string | null;
}

interface Line {
    id: number;
    consignment_number: string;
    merchant_reference: string | null;
    // ours: this business's parcel · elsewhere: another business's · unlisted: nobody's in the ERP
    state: 'ours' | 'elsewhere' | 'unlisted';
    resolution: 'mine' | 'not_mine' | null;
    included: boolean;
    problem: string | null;
    consignment_status: string | null;
    collected: string;
    charge: string;
    cod_fee: string;
    payout: string;
}

interface Summary {
    file_total: string;
    share: string;
    collected: string;
    delivery_fees: string;
    return_fees: string;
    additional_charges: string;
    cod_fees: string;
    deposit_expected: boolean;
}

interface JournalLine {
    id: number;
    amount_minor: number;
    memo: string | null;
    account: { id: number; code: string; name: string } | null;
}

const taka = (minor: number) => (minor / 100).toLocaleString('en-BD', { minimumFractionDigits: 2 });

const stateLabel = (line: Line) => {
    if (line.state === 'ours') {
        return `Ours${line.consignment_status ? ` - ${line.consignment_status.toLowerCase()}` : ''}`;
    }
    if (line.state === 'elsewhere') {
        return 'Another business';
    }
    return line.resolution === 'mine' ? 'Booked by hand, ours' : line.resolution === 'not_mine' ? 'Not ours' : 'Not in the ERP';
};

export default function CourierStatementShow({
    statement,
    lines,
    summary,
    problems,
    can_post,
    moneyAccounts,
    journal,
}: {
    statement: Statement;
    lines: Line[];
    summary: Summary;
    problems: string[];
    can_post: boolean;
    moneyAccounts: { id: number; name: string }[];
    journal: { journal_number: string; lines: JournalLine[] } | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Courier payouts', href: '/sales/courier-statements' },
        { title: statement.statement_reference, href: `/sales/courier-statements/${statement.id}` },
    ];
    const isDraft = statement.status === 'draft';
    const pageErrors = usePage().props.errors as Record<string, string | undefined>;

    const { data, setData, post, errors, processing } = useForm({
        received_money_account_id: moneyAccounts.length === 1 ? String(moneyAccounts[0].id) : '',
        deposit_amount: summary.deposit_expected ? summary.file_total : '',
        received_on: new Date().toLocaleDateString('en-CA'),
    });

    const resolve = (line: Line, resolution: 'mine' | 'not_mine') =>
        router.post(route('sales.courier-statements.resolve', [statement.id, line.id]), { resolution }, { preserveScroll: true });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.courier-statements.post', statement.id), { preserveScroll: true });
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
                            {statement.source_filename ? ` - read from ${statement.source_filename}` : ''}
                        </p>
                    </div>
                    <Badge variant={isDraft ? 'secondary' : 'default'}>{isDraft ? 'Draft' : 'Posted'}</Badge>
                </div>

                <div className="grid gap-3 text-sm sm:grid-cols-3">
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                        <p className="text-muted-foreground">The whole payout</p>
                        <p className="text-lg font-medium tabular-nums">{summary.file_total}</p>
                        <p className="text-muted-foreground text-xs">Every parcel in the file, whichever business it belongs to.</p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                        <p className="text-muted-foreground">This business&apos;s share</p>
                        <p className="text-lg font-medium tabular-nums">{summary.share}</p>
                        <p className="text-muted-foreground text-xs">
                            Collected {summary.collected}, less delivery {summary.delivery_fees}, returns {summary.return_fees}, extra charges{' '}
                            {summary.additional_charges} and COD fee {summary.cod_fees}.
                        </p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                        <p className="text-muted-foreground">Money</p>
                        {isDraft ? (
                            <p className="text-xs">
                                {summary.deposit_expected
                                    ? 'Enter where the payout arrived below. It must equal the whole payout.'
                                    : 'This statement pays nothing out, so nothing arrives; the share stays with the courier.'}
                            </p>
                        ) : statement.received_into ? (
                            <p className="text-sm">
                                {statement.deposit_amount} arrived in {statement.received_into} on {statement.received_on}
                            </p>
                        ) : (
                            <p className="text-sm">Nothing arrived; the share stays with the courier.</p>
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

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Consignment</th>
                                <th className="p-3">Whose</th>
                                <th className="p-3 text-right">Collected</th>
                                <th className="p-3 text-right">Charge</th>
                                <th className="p-3 text-right">COD fee</th>
                                <th className="p-3 text-right">Payout</th>
                                {isDraft && <th className="p-3"></th>}
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line) => (
                                <tr key={line.id} className={`border-t ${line.included ? '' : 'text-muted-foreground'}`}>
                                    <td className="p-3">
                                        <span className="font-medium">{line.consignment_number}</span>
                                        {line.merchant_reference && (
                                            <span className="text-muted-foreground block text-xs">{line.merchant_reference}</span>
                                        )}
                                        {line.problem && <span className="text-destructive block text-xs">{line.problem}</span>}
                                    </td>
                                    <td className="p-3">{stateLabel(line)}</td>
                                    <td className="p-3 text-right tabular-nums">{line.collected}</td>
                                    <td className="p-3 text-right tabular-nums">{line.charge}</td>
                                    <td className="p-3 text-right tabular-nums">{line.cod_fee}</td>
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
                        </tbody>
                    </table>
                </div>
                {isDraft && (
                    <p className="text-muted-foreground text-xs">
                        A parcel not in the ERP was booked by hand in the courier&apos;s panel. Mark it ours only if it belongs to this business and
                        collected nothing: its charge is then a cost here. One that collected money is a sale the ERP does not have, and has to be
                        recorded first.
                    </p>
                )}

                {isDraft && (
                    <form
                        onSubmit={submit}
                        className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-end gap-3 rounded-md border p-4"
                    >
                        {summary.deposit_expected && (
                            <>
                                <div className="grid gap-1">
                                    <Label>Arrived in</Label>
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
                                    <Label>Amount that arrived</Label>
                                    <Input className="w-36" value={data.deposit_amount} onChange={(e) => setData('deposit_amount', e.target.value)} />
                                    <InputError message={errors.deposit_amount} />
                                </div>
                                <div className="grid gap-1">
                                    <Label>On</Label>
                                    <Input
                                        type="date"
                                        className="w-40"
                                        value={data.received_on}
                                        onChange={(e) => setData('received_on', e.target.value)}
                                    />
                                    <InputError message={errors.received_on} />
                                </div>
                            </>
                        )}
                        <Button disabled={processing || !can_post}>Post statement</Button>
                        <ConfirmDialog
                            trigger={
                                <Button type="button" variant="ghost">
                                    Discard draft
                                </Button>
                            }
                            title={`Discard ${statement.statement_reference}`}
                            description="The draft and its decisions are removed. Nothing had been posted, so nothing else changes."
                            confirmLabel="Discard"
                            destructive
                            onConfirm={() => router.delete(route('sales.courier-statements.destroy', statement.id))}
                        />
                    </form>
                )}

                {journal && (
                    <div className="overflow-x-auto rounded-lg border">
                        <p className="p-3 font-medium">What this did to the books ({journal.journal_number})</p>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Account</th>
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
