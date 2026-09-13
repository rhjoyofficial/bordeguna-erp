import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Statement {
    id: number;
    courier: string | null;
    statement_reference: string;
    statement_date: string;
    status: 'draft' | 'posted';
    lines_count: number;
    share_amount: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Courier payouts', href: '/sales/courier-statements' },
];

/*
 * A courier's payout statement is read into a draft, and nothing moves until it
 * is posted from its own page.
 */
export default function CourierStatementsIndex({
    statements,
    couriers,
}: {
    statements: Paginated<Statement>;
    couriers: { id: number; name: string; provider: string | null }[];
}) {
    const { data, setData, post, errors, processing } = useForm<{
        money_account_id: string;
        statement_reference: string;
        statement_date: string;
        file: File | null;
    }>({
        money_account_id: couriers.length === 1 ? String(couriers[0].id) : '',
        statement_reference: '',
        statement_date: new Date().toLocaleDateString('en-CA'),
        file: null,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.courier-statements.store'), { forceFormData: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Courier payouts" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Courier payouts" />

                {couriers.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No courier accounts yet. Add one under Accounting, Bank &amp; cash, as a Courier account.
                    </p>
                ) : (
                    <form
                        onSubmit={submit}
                        className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-end gap-3 rounded-md border p-4"
                    >
                        <div className="grid gap-1">
                            <Label>Courier</Label>
                            <SearchableSelect
                                value={data.money_account_id}
                                onChange={(v) => setData('money_account_id', v)}
                                options={couriers.map((c) => ({ value: String(c.id), label: c.name }))}
                                placeholder="Select courier"
                                searchPlaceholder="Search couriers…"
                            />
                            <InputError message={errors.money_account_id} />
                        </div>
                        <div className="grid gap-1">
                            <Label>Invoice id</Label>
                            <Input
                                className="w-44"
                                value={data.statement_reference}
                                placeholder="e.g. 230826BXGIYJJ"
                                onChange={(e) => setData('statement_reference', e.target.value)}
                            />
                            <InputError message={errors.statement_reference} />
                        </div>
                        <div className="grid gap-1">
                            <Label>Invoice date</Label>
                            <Input
                                type="date"
                                className="w-40"
                                value={data.statement_date}
                                onChange={(e) => setData('statement_date', e.target.value)}
                            />
                            <InputError message={errors.statement_date} />
                        </div>
                        <div className="grid gap-1">
                            <Label>The courier&apos;s CSV</Label>
                            <Input type="file" accept=".csv,text/csv" onChange={(e) => setData('file', e.target.files?.[0] ?? null)} />
                            <InputError message={errors.file} />
                        </div>
                        <Button disabled={processing}>Read statement</Button>
                        <p className="text-muted-foreground w-full text-xs">
                            The courier&apos;s file has no invoice id or date, so copy them from its invoices screen. Reading it changes nothing: you
                            review the parcels and enter the money that arrived before posting. Names and phone numbers in the file are not kept.
                        </p>
                    </form>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Invoice</th>
                                <th className="p-3">Courier</th>
                                <th className="p-3">Date</th>
                                <th className="p-3 text-right">Parcels</th>
                                <th className="p-3 text-right">This business&apos;s share</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {statements.data.map((statement) => (
                                <tr key={statement.id} className="border-t">
                                    <td className="p-3">
                                        <Link
                                            href={route('sales.courier-statements.show', statement.id)}
                                            className="font-medium underline underline-offset-4"
                                        >
                                            {statement.statement_reference}
                                        </Link>
                                    </td>
                                    <td className="p-3">{statement.courier}</td>
                                    <td className="p-3">{statement.statement_date}</td>
                                    <td className="p-3 text-right tabular-nums">{statement.lines_count}</td>
                                    <td className="p-3 text-right tabular-nums">{statement.share_amount ?? '-'}</td>
                                    <td className="p-3">
                                        <Badge variant={statement.status === 'posted' ? 'default' : 'secondary'}>
                                            {statement.status === 'posted' ? 'Posted' : 'Draft'}
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                            {statements.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                        No statements yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={statements} />
            </div>
        </AppLayout>
    );
}
