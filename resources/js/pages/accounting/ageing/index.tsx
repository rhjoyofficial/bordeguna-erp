import { Amount, DateCell, DocNo, PartyText } from '@/components/ledger-text';
import { Chip, Hint, Meter, Pill } from '@/components/sales/sales-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { FormEventHandler, Fragment, useState } from 'react';

interface Bucket {
    key: string;
    label: string;
}

interface AgeingDocument {
    number: string;
    date: string;
    basis_date: string;
    uses_fallback_date: boolean;
    days_overdue: number;
    bucket: string;
    total: string;
    outstanding: string;
}

interface Row {
    party_id: number;
    party_name: string;
    buckets: Record<string, string>;
    on_account: string;
    total: string;
    /** The row is the other way round: we owe this customer, or this supplier owes us. */
    in_credit: boolean;
    total_absolute: string;
    documents: AgeingDocument[];
}

/** A server-formatted figure as a number, for proportions and "is it zero" only; never shown. */
const asNumber = (value: string) => Number(String(value).replace(/,/g, '')) || 0;

/** The five buckets' colours, current first: green, then amber to deep red. */
const BUCKET_BAR = ['bg-primary', 'bg-amber-500', 'bg-orange-600', 'bg-red-700', 'bg-red-900'];

/**
 * Receivables (and payables) ageing, restyled as the Money tab of Sales
 * (15 Oct 2026): three tiles, the buckets as one bar, the parties as a
 * compact table whose row opens its documents in place, and on the
 * receivables side a Statement and a Receive action per customer.
 *
 * ⚠ Presentation only, same contract as before: every figure arrives
 * formatted from AgeingController, and the ledger tie-out (C-GLA-12) is
 * still said every time. The numbers parsed here decide only a bar's width
 * and whether a row is overdue.
 */
export default function AgeingIndex({
    kind,
    label,
    party_label,
    as_at,
    buckets,
    rows,
    totals,
    control,
    parties_omitted,
    uses_fallback_dates,
}: {
    kind: 'receivables' | 'payables';
    label: string;
    party_label: string;
    as_at: string;
    buckets: Bucket[];
    rows: Row[];
    totals: { buckets: Record<string, string>; on_account: string; total: string; owing: string; in_credit: string };
    control: { account: string | null; balance: string | null; ties_out: boolean; difference: string };
    parties_omitted: number;
    uses_fallback_dates: boolean;
}) {
    const [date, setDate] = useState(as_at);
    const [open, setOpen] = useState<number | null>(null);
    const [overdueOnly, setOverdueOnly] = useState(false);

    const routeName = kind === 'receivables' ? 'accounting.receivables-ageing' : 'accounting.payables-ageing';

    // A Manager reaches this screen from Sales and cannot open the ledger (Q19).
    const { canViewAccounting, canRecordReceipts } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        canViewAccounting ? { title: 'Accounting', href: '/accounting/journals' } : { title: 'Sales', href: '/sales/overview' },
        { title: `${label} ageing`, href: route(routeName) },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route(routeName), { as_at: date }, { preserveState: true });
    };

    const currentKey = buckets[0]?.key;
    const isOverdue = (row: Row) => buckets.some((bucket) => bucket.key !== currentKey && asNumber(row.buckets[bucket.key]) > 0);
    const shown = overdueOnly ? rows.filter(isOverdue) : rows;
    const overdueCount = rows.filter(isOverdue).length;
    const bucketTotal = buckets.reduce((sum, bucket) => sum + Math.max(asNumber(totals.buckets[bucket.key]), 0), 0);
    const receivables = kind === 'receivables';
    const columns = buckets.length + 3 + (receivables ? 1 : 0);
    // F33: a party in credit is owed by us (customers) or owes us (suppliers),
    // said in words and shown positive, never as a red minus.
    const creditLabel = receivables ? 'We owe customers' : 'Suppliers owe us';
    const creditWord = receivables ? 'we owe' : 'owes us';
    const hasCredit = asNumber(totals.in_credit) !== 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${label} ageing`} />
            <div className="flex flex-col gap-4 p-4">
                {/* Three tiles: what is owed by age, what sits on account, and the tie-out. */}
                <div className="grid gap-3 md:grid-cols-3">
                    <section className="bg-card text-card-foreground rounded-xl border p-4">
                        <p className="text-muted-foreground inline-flex items-center gap-1.5 text-xs">
                            {receivables ? 'From customers' : 'To suppliers'}, as at <DateCell className="font-medium">{as_at}</DateCell>
                            <Hint text="Open documents from posted journals, less what was paid or credited against them, by days past the due date. Five buckets always, so an empty one reads as empty." />
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            <Amount value={totals.owing} tone="total" className="text-2xl" />
                        </p>
                        {hasCredit && (
                            <p className="text-muted-foreground mt-0.5 inline-flex items-center gap-1.5 text-xs tabular-nums">
                                {creditLabel} <span className="text-foreground font-medium">{totals.in_credit}</span>
                                <Hint
                                    text={
                                        receivables
                                            ? 'Customers whose returns or receipts exceed what they owe. It is money we owe them, not money owed to us.'
                                            : 'Suppliers paid more than their bills. It is money they owe us, not money we owe them.'
                                    }
                                />
                            </p>
                        )}
                        <Meter
                            className="mt-2"
                            label={buckets.map((bucket) => `${bucket.label} ${totals.buckets[bucket.key]}`).join('; ')}
                            segments={buckets.map((bucket, index) => ({
                                share: bucketTotal > 0 ? (Math.max(asNumber(totals.buckets[bucket.key]), 0) / bucketTotal) * 100 : 0,
                                className: BUCKET_BAR[index] ?? 'bg-muted-foreground/50',
                            }))}
                        />
                        <div className="text-muted-foreground mt-1.5 flex flex-wrap justify-between gap-x-3 gap-y-1 text-[11.5px] tabular-nums">
                            {buckets.map((bucket, index) => (
                                <span key={bucket.key} className="inline-flex items-center gap-1">
                                    <span aria-hidden="true" className={cn('inline-block size-2 rounded-sm', BUCKET_BAR[index])} />
                                    {bucket.label} {totals.buckets[bucket.key]}
                                </span>
                            ))}
                        </div>
                    </section>

                    <section className="bg-card text-card-foreground rounded-xl border p-4">
                        <p className="text-muted-foreground inline-flex items-center gap-1.5 text-xs">
                            On account
                            <Hint text="Money received and not yet applied to a document. It is not in the buckets." />
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">{totals.on_account}</p>
                        <p className="text-muted-foreground mt-2 text-xs tabular-nums">
                            {rows.length} {rows.length === 1 ? party_label.toLowerCase() : party_label.toLowerCase() + 's'} listed
                            {overdueCount > 0 && (
                                <>
                                    {' '}
                                    · <span className="text-destructive">{overdueCount} overdue</span>
                                </>
                            )}
                            {parties_omitted > 0 && ` · ${parties_omitted} with nothing outstanding not shown`}
                        </p>
                    </section>

                    {/* ⚠⚠ C-GLA-12. The tie-out to the ledger, stated every time, not left to a nightly command. */}
                    <section
                        className={cn(
                            'rounded-xl border p-4',
                            control.balance !== null && !control.ties_out ? 'border-destructive/40 bg-destructive/5' : 'bg-card text-card-foreground',
                        )}
                    >
                        <p className="text-muted-foreground text-xs">Against the ledger</p>
                        {control.balance === null ? (
                            <p className="text-muted-foreground mt-1 text-sm">
                                No control account is mapped for {label.toLowerCase()}, so this report cannot be checked against the ledger.
                            </p>
                        ) : control.ties_out ? (
                            <>
                                <p className="mt-1 flex items-center gap-2">
                                    <Pill tone="ok">Agrees</Pill>
                                    <span className="text-sm font-semibold tabular-nums">{control.balance}</span>
                                </p>
                                <p className="text-muted-foreground mt-2 text-xs">
                                    {control.account} stands at this figure on this date, and so does this report.
                                </p>
                            </>
                        ) : (
                            <>
                                <p className="mt-1 flex items-center gap-2">
                                    <Pill tone="danger">Disagrees by {control.difference}</Pill>
                                </p>
                                <p className="text-destructive mt-2 text-xs font-medium">
                                    {control.account} stands at {control.balance} on this date, while the documents here total {totals.total}. Run
                                    accounting:reconcile.
                                </p>
                            </>
                        )}
                    </section>
                </div>

                {/* The filter chips and the date, only as wide as their content. */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Chip active={!overdueOnly} count={rows.length} onClick={() => setOverdueOnly(false)}>
                            All {party_label.toLowerCase()}s
                        </Chip>
                        <Chip
                            active={overdueOnly}
                            count={overdueCount}
                            tone={overdueCount > 0 ? 'danger' : undefined}
                            onClick={() => setOverdueOnly(true)}
                        >
                            Overdue only
                        </Chip>
                    </div>
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
                        <div className="grid gap-1">
                            <Label htmlFor="as_at" className="text-xs">
                                As at
                            </Label>
                            <Input id="as_at" type="date" className="h-8 w-40" value={date} onChange={(e) => setDate(e.target.value)} />
                        </div>
                        <Button variant="outline" size="sm">
                            Show
                        </Button>
                    </form>
                </div>

                <section className="bg-card text-card-foreground overflow-hidden rounded-xl border">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[60rem] text-[13px] [&_tbody_td]:px-3.5 [&_tbody_td]:py-2 [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th>{party_label}</th>
                                    {buckets.map((bucket) => (
                                        <th key={bucket.key} className="text-right">
                                            {bucket.label}
                                        </th>
                                    ))}
                                    <th className="text-right">
                                        <span className="inline-flex items-center gap-1">
                                            On account
                                            <Hint text="Money received and not yet applied to a document." />
                                        </span>
                                    </th>
                                    <th className="text-right">Total</th>
                                    {receivables && <th />}
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((row) => {
                                    const expanded = open === row.party_id;

                                    return (
                                        <Fragment key={row.party_id}>
                                            <tr
                                                onClick={() => setOpen(expanded ? null : row.party_id)}
                                                aria-expanded={expanded}
                                                className="hover:bg-muted/40 cursor-pointer border-b"
                                            >
                                                <td>
                                                    <span className="inline-flex items-center gap-1.5">
                                                        {expanded ? (
                                                            <ChevronDown className="text-muted-foreground size-3.5" />
                                                        ) : (
                                                            <ChevronRight className="text-muted-foreground size-3.5" />
                                                        )}
                                                        <PartyText className="font-semibold">{row.party_name}</PartyText>
                                                    </span>
                                                    <span className="text-muted-foreground ml-5 block text-xs tabular-nums">
                                                        {row.documents.length} {row.documents.length === 1 ? 'document' : 'documents'}
                                                    </span>
                                                </td>
                                                {buckets.map((bucket, index) => {
                                                    const figure = row.buckets[bucket.key];
                                                    const zero = asNumber(figure) === 0;

                                                    return (
                                                        <td key={bucket.key} className="text-right tabular-nums">
                                                            {zero ? (
                                                                <span className="text-muted-foreground/50">{figure}</span>
                                                            ) : (
                                                                <Amount
                                                                    value={figure}
                                                                    className={cn(
                                                                        index === 1 && 'text-amber-700 dark:text-amber-400',
                                                                        index >= 2 && 'text-red-700 dark:text-red-400',
                                                                    )}
                                                                />
                                                            )}
                                                        </td>
                                                    );
                                                })}
                                                <td className="text-muted-foreground text-right tabular-nums">{row.on_account}</td>
                                                <td className="text-right">
                                                    {row.in_credit ? (
                                                        <span className="tabular-nums">
                                                            {row.total_absolute}
                                                            <span className="text-muted-foreground ml-1 text-xs">{creditWord}</span>
                                                        </span>
                                                    ) : (
                                                        <Amount value={row.total} tone="balance" />
                                                    )}
                                                </td>
                                                {receivables && (
                                                    <td className="whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
                                                        <Link
                                                            href={`/accounting/customer-statement?customer=${row.party_id}`}
                                                            className="text-primary text-xs underline-offset-4 hover:underline"
                                                        >
                                                            Statement
                                                        </Link>
                                                        {canRecordReceipts && (
                                                            <>
                                                                <span className="text-muted-foreground mx-1.5">·</span>
                                                                <Link
                                                                    href={`/sales/receipts/new?customer_id=${row.party_id}`}
                                                                    className="text-primary text-xs underline-offset-4 hover:underline"
                                                                >
                                                                    Receive
                                                                </Link>
                                                            </>
                                                        )}
                                                    </td>
                                                )}
                                            </tr>
                                            {expanded && (
                                                <tr className="border-b">
                                                    <td colSpan={columns} className="bg-muted/20 !py-3 !pl-9">
                                                        {row.documents.length === 0 ? (
                                                            <p className="text-muted-foreground text-sm">
                                                                Nothing outstanding against a document. The balance above is money on account.
                                                            </p>
                                                        ) : (
                                                            <div className="grid max-w-3xl grid-cols-[minmax(10rem,1.4fr)_6rem_6rem_7rem_7rem_7rem] items-center gap-x-4 gap-y-1 text-[12.5px]">
                                                                <span className="text-muted-foreground text-xs">Document</span>
                                                                <span className="text-muted-foreground text-xs">Dated</span>
                                                                <span className="text-muted-foreground text-xs">Due</span>
                                                                <span className="text-muted-foreground text-right text-xs">Days overdue</span>
                                                                <span className="text-muted-foreground text-right text-xs">Original</span>
                                                                <span className="text-muted-foreground text-right text-xs">Outstanding</span>
                                                                {row.documents.map((document) => (
                                                                    <Fragment key={document.number}>
                                                                        <DocNo>{document.number}</DocNo>
                                                                        <DateCell className="font-normal">{document.date}</DateCell>
                                                                        <span>
                                                                            <DateCell className="font-normal">{document.basis_date}</DateCell>
                                                                            {document.uses_fallback_date && (
                                                                                <span className="text-muted-foreground ml-1 text-xs">(no terms)</span>
                                                                            )}
                                                                        </span>
                                                                        <span
                                                                            className={cn(
                                                                                'text-right tabular-nums',
                                                                                document.days_overdue > 0 &&
                                                                                    'font-semibold text-red-700 dark:text-red-400',
                                                                            )}
                                                                        >
                                                                            {document.days_overdue}
                                                                        </span>
                                                                        <span className="text-muted-foreground text-right tabular-nums">
                                                                            {document.total}
                                                                        </span>
                                                                        <span className="text-right">
                                                                            <Amount value={document.outstanding} tone="balance" />
                                                                        </span>
                                                                    </Fragment>
                                                                ))}
                                                            </div>
                                                        )}
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })}
                                {shown.length === 0 && (
                                    <tr>
                                        <td colSpan={columns} className="text-muted-foreground py-6 text-center">
                                            {rows.length === 0 ? 'Nothing is outstanding on this date.' : 'Nothing is overdue on this date.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                            {rows.length > 0 && (
                                <tfoot>
                                    <tr className="border-t-2 font-bold">
                                        <td className="px-3.5 py-2">
                                            Total, {rows.length} {rows.length === 1 ? party_label.toLowerCase() : party_label.toLowerCase() + 's'}
                                        </td>
                                        {buckets.map((bucket) => (
                                            <td key={bucket.key} className="px-3.5 py-2 text-right">
                                                <Amount value={totals.buckets[bucket.key]} tone="total" />
                                            </td>
                                        ))}
                                        <td className="px-3.5 py-2 text-right tabular-nums">{totals.on_account}</td>
                                        <td className="px-3.5 py-2 text-right">
                                            <Amount value={totals.total} tone="total" />
                                        </td>
                                        {receivables && <td />}
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                    <p className="text-muted-foreground border-t px-4 py-2.5 text-xs">
                        A row opens its documents in place.
                        {receivables &&
                            ' Statement opens the customer statement with this customer selected; Receive opens the receipt form for them.'}
                    </p>
                </section>

                <div className="text-muted-foreground max-w-3xl space-y-2 text-sm">
                    {/* C-GLA-10, stated only when it actually bit. */}
                    {uses_fallback_dates && (
                        <p>
                            Documents with no due date are treated as due on the day they were raised, because no credit terms have been agreed. Those
                            rows are marked "no terms".
                        </p>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
