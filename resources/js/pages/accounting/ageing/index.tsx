import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
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
    documents: AgeingDocument[];
}

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
    totals: { buckets: Record<string, string>; on_account: string; total: string };
    control: { account: string | null; balance: string | null; ties_out: boolean; difference: string };
    parties_omitted: number;
    uses_fallback_dates: boolean;
}) {
    const [date, setDate] = useState(as_at);
    const [open, setOpen] = useState<number | null>(null);

    const routeName = kind === 'receivables' ? 'accounting.receivables-ageing' : 'accounting.payables-ageing';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/journals' },
        { title: `${label} ageing`, href: route(routeName) },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route(routeName), { as_at: date }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${label} ageing`} />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">{label} ageing</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">
                        {kind === 'receivables'
                            ? 'What each customer owes, and how long it has been owed. The balance sheet gives one figure for everyone combined, which is the one form in which it cannot be acted on.'
                            : 'What the business owes each supplier, and how long it has been owed.'}
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="as_at">As at</Label>
                        <Input id="as_at" type="date" value={date} onChange={(e) => setDate(e.target.value)} className="w-44" />
                    </div>
                    <Button variant="outline">Show</Button>
                </form>

                {/* ⚠⚠ C-GLA-12. The tie-out to the ledger, stated every time, not left to a nightly command. */}
                {control.balance !== null && (
                    <div
                        className={`rounded-md border px-4 py-3 text-sm ${
                            control.ties_out
                                ? 'border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border'
                                : 'border-destructive/40 bg-destructive/5 text-destructive font-medium'
                        }`}
                    >
                        {control.ties_out
                            ? `Agrees with the ledger. ${control.account} stands at ${control.balance} on this date, and so does this report.`
                            : `This report and the ledger disagree by ${control.difference}. ${control.account} stands at ${control.balance} on this date, while the documents here total ${totals.total}. Run accounting:reconcile.`}
                    </div>
                )}

                {control.balance === null && (
                    <div className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border rounded-md border px-4 py-3 text-sm">
                        No control account is mapped for {label.toLowerCase()}, so this report cannot be checked against the ledger.
                    </div>
                )}

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[64rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="py-2">{party_label}</th>
                                {buckets.map((bucket) => (
                                    <th key={bucket.key} className="py-2 text-right">
                                        {bucket.label}
                                    </th>
                                ))}
                                <th className="py-2 text-right">On account</th>
                                <th className="py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <Fragment key={row.party_id}>
                                    <tr
                                        onClick={() => setOpen(open === row.party_id ? null : row.party_id)}
                                        className="border-sidebar-border/70 hover:bg-muted/40 dark:border-sidebar-border cursor-pointer border-b"
                                    >
                                        <td className="py-2 font-medium">{row.party_name}</td>
                                        {buckets.map((bucket) => (
                                            <td key={bucket.key} className="py-2 text-right tabular-nums">
                                                {row.buckets[bucket.key]}
                                            </td>
                                        ))}
                                        <td className="text-muted-foreground py-2 text-right tabular-nums">{row.on_account}</td>
                                        <td className="py-2 text-right font-medium tabular-nums">{row.total}</td>
                                    </tr>
                                    {open === row.party_id && (
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td colSpan={buckets.length + 3} className="bg-muted/20 px-4 py-3">
                                                {row.documents.length === 0 ? (
                                                    <p className="text-muted-foreground text-sm">
                                                        Nothing outstanding against a document. The balance above is money on account.
                                                    </p>
                                                ) : (
                                                    <table className="w-full text-sm">
                                                        <thead>
                                                            <tr className="text-muted-foreground text-left">
                                                                <th className="py-1">Document</th>
                                                                <th className="py-1">Dated</th>
                                                                <th className="py-1">Due</th>
                                                                <th className="py-1 text-right">Days overdue</th>
                                                                <th className="py-1 text-right">Original</th>
                                                                <th className="py-1 text-right">Outstanding</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {row.documents.map((document) => (
                                                                <tr key={document.number}>
                                                                    <td className="py-1 font-mono">{document.number}</td>
                                                                    <td className="py-1">{document.date}</td>
                                                                    <td className="py-1">
                                                                        {document.basis_date}
                                                                        {document.uses_fallback_date && (
                                                                            <span className="text-muted-foreground ml-1">(no terms)</span>
                                                                        )}
                                                                    </td>
                                                                    <td className="py-1 text-right tabular-nums">{document.days_overdue}</td>
                                                                    <td className="text-muted-foreground py-1 text-right tabular-nums">
                                                                        {document.total}
                                                                    </td>
                                                                    <td className="py-1 text-right tabular-nums">{document.outstanding}</td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                )}
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={buckets.length + 3} className="text-muted-foreground py-6 text-center">
                                        Nothing is outstanding on this date.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                        {rows.length > 0 && (
                            <tfoot>
                                <tr className="font-medium">
                                    <td className="py-2">Total</td>
                                    {buckets.map((bucket) => (
                                        <td key={bucket.key} className="py-2 text-right tabular-nums">
                                            {totals.buckets[bucket.key]}
                                        </td>
                                    ))}
                                    <td className="py-2 text-right tabular-nums">{totals.on_account}</td>
                                    <td className="py-2 text-right tabular-nums">{totals.total}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>

                <div className="text-muted-foreground max-w-3xl space-y-2 text-sm">
                    {/* C-GLA-10, stated only when it actually bit. */}
                    {uses_fallback_dates && (
                        <p>
                            Documents with no due date are treated as due on the day they were raised, because no credit terms have been agreed. Those
                            rows are marked "no terms".
                        </p>
                    )}
                    <p>
                        "On account" is money that changes what a {party_label.toLowerCase()} owes but belongs to no document: a
                        {kind === 'receivables'
                            ? ' receipt or credit note not yet applied to an invoice'
                            : ' payment or credit note not yet applied to a bill'}
                        . It can sit in no age bucket, and leaving it out would make this report disagree with the balance sheet.
                    </p>
                    {parties_omitted > 0 && (
                        <p>
                            {parties_omitted} {parties_omitted === 1 ? 'party is' : 'parties are'} not shown, having nothing outstanding and nothing
                            on account.
                        </p>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
