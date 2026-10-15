import { type IdentityDocuments, IdentityDocumentsPanel } from '@/components/identity-documents-panel';
import RecordTimeline, { type TimelineEntry } from '@/components/record-timeline';
import { ReportHeading } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface OpenInvoice {
    id: number;
    invoice_number: string;
    invoice_date: string | null;
    due_date: string | null;
    total: string;
    outstanding: string;
    days_overdue: number;
}

interface Movement {
    date: string;
    kind: string;
    label: string;
    reference: string | null;
    amount: string;
    balance: string;
}

interface CustomerInfo {
    id: number;
    code: string | null;
    name: string;
    type: string | null;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    payment_terms: string | null;
    credit_days: number | null;
    credit_days_effective: number;
    price_group: string;
    is_active: boolean;
    note: string | null;
}

/**
 * The customer 360 (C-360): everything about one customer on one screen,
 * derived from the same documents the statement reads, never stored here.
 */
export default function CustomerShow({
    timeline,
    documents,
    customer,
    balance,
    totals,
    credit,
    open_invoices,
    recent,
    statement_url,
}: {
    timeline?: TimelineEntry[];
    documents: IdentityDocuments;
    customer: CustomerInfo;
    balance: { amount: string; is_credit: boolean; ledger_agrees: boolean | null };
    totals: { invoiced: string; settled: string };
    credit: { limit: string | null; over: boolean };
    open_invoices: OpenInvoice[];
    recent: Movement[];
    statement_url: string;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Customers', href: '/sales/customers' },
        { title: customer.name, href: `/sales/customers/${customer.id}` },
    ];

    const facts: [string, string | null][] = [
        ['Code', customer.code],
        ['Type', customer.type],
        ['Contact', customer.contact_name],
        ['Phone', customer.phone],
        ['Email', customer.email],
        ['Address', customer.address],
        ['Credit days', customer.credit_days === null ? `${customer.credit_days_effective} (default)` : String(customer.credit_days)],
        ['Terms note', customer.payment_terms],
        ['Price group', customer.price_group],
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={customer.name} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <ReportHeading title={customer.name} />
                    <div className="flex items-center gap-2">
                        {!customer.is_active && <Badge variant="secondary">Inactive</Badge>}
                        {/* ⭐ Money in from here, across every unpaid invoice (21 Sep 2026). */}
                        <Button size="sm" asChild>
                            <Link href={`/sales/receipts/new?customer_id=${customer.id}`}>Receive payment</Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={statement_url}>Full statement</Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Owes now</p>
                        <p className="mt-1 text-xl font-semibold">
                            {formatMoney(balance.amount)}
                            {balance.is_credit && <span className="text-muted-foreground ml-1 text-sm">(in credit)</span>}
                        </p>
                        {balance.ledger_agrees === false && (
                            <p className="mt-1 text-xs font-medium text-amber-600 dark:text-amber-500">
                                Ledger disagrees - investigate on the statement
                            </p>
                        )}
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Credit limit</p>
                        <p className="mt-1 text-xl font-semibold">{credit.limit ?? 'No limit set'}</p>
                        {credit.over && <p className="mt-1 text-xs font-medium text-red-600 dark:text-red-500">OVER the limit</p>}
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Invoiced, lifetime</p>
                        <p className="mt-1 text-xl font-semibold">{totals.invoiced}</p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Settled, lifetime</p>
                        <p className="mt-1 text-xl font-semibold">{totals.settled}</p>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-6">
                        <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                            <h2 className="text-sm font-medium">Details</h2>
                            <dl className="mt-3 space-y-2 text-sm">
                                {facts.map(([label, value]) =>
                                    value ? (
                                        <div key={label}>
                                            <dt className="text-muted-foreground text-xs">{label}</dt>
                                            <dd className="whitespace-pre-line">{value}</dd>
                                        </div>
                                    ) : null,
                                )}
                            </dl>
                            {customer.note && <p className="text-muted-foreground mt-3 border-t pt-3 text-sm whitespace-pre-line">{customer.note}</p>}
                        </div>
                        {/* ⭐ Plan D11: trade licence and NID scans. */}
                        <IdentityDocumentsPanel
                            documents={documents}
                            uploadUrl={`/sales/customers/${customer.id}/documents`}
                            removeUrl={(id) => `/sales/customers/${customer.id}/documents/${id}`}
                        />
                    </div>

                    <div className="flex flex-col gap-6 lg:col-span-2">
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3" colSpan={5}>
                                            Open invoices
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {open_invoices.map((invoice) => (
                                        <tr key={invoice.id} className="border-t">
                                            <td className="p-3 font-mono">{invoice.invoice_number}</td>
                                            <td className="text-muted-foreground p-3">{invoice.invoice_date}</td>
                                            <td className="p-3">
                                                {invoice.due_date && <span className="text-muted-foreground">due {invoice.due_date}</span>}
                                                {invoice.days_overdue > 0 && (
                                                    <span className="ml-2 text-xs font-medium text-red-600 dark:text-red-500">
                                                        {invoice.days_overdue} day(s) overdue
                                                    </span>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground p-3 text-right">of {formatMoney(invoice.total)}</td>
                                            <td className="p-3 text-right font-medium">{formatMoney(invoice.outstanding)}</td>
                                        </tr>
                                    ))}
                                    {open_invoices.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="text-muted-foreground p-4 text-center">
                                                Nothing unpaid.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3" colSpan={4}>
                                            Recent activity
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent.map((movement, index) => (
                                        <tr key={index} className="border-t">
                                            <td className="text-muted-foreground p-3 whitespace-nowrap">{movement.date}</td>
                                            <td className="p-3">
                                                {movement.label}
                                                {movement.reference && (
                                                    <span className="text-muted-foreground ml-1 text-xs">({movement.reference})</span>
                                                )}
                                            </td>
                                            <td className="p-3 text-right whitespace-nowrap">{formatMoney(movement.amount)}</td>
                                            <td className="text-muted-foreground p-3 text-right whitespace-nowrap">bal {movement.balance}</td>
                                        </tr>
                                    ))}
                                    {recent.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="text-muted-foreground p-4 text-center">
                                                No documents yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div className="border-border bg-card mx-4 mb-4 rounded-lg border p-4">
                <h2 className="mb-3 text-sm font-semibold">Timeline</h2>
                <RecordTimeline entries={timeline ?? []} />
            </div>
        </AppLayout>
    );
}
