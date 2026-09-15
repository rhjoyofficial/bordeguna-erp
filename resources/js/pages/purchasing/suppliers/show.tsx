import { type IdentityDocuments, IdentityDocumentsPanel } from '@/components/identity-documents-panel';
import RecordTimeline, { type TimelineEntry } from '@/components/record-timeline';
import { ReportHeading } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface OpenBill {
    id: number;
    bill_number: string;
    bill_date: string | null;
    due_date: string | null;
    total: string;
    outstanding: string;
    days_overdue: number;
}

interface Movement {
    date: string | null;
    kind: string;
    label: string;
    amount: string | null;
}

interface SupplierInfo {
    id: number;
    code: string | null;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    payment_terms: string | null;
    is_active: boolean;
}

/**
 * The supplier 360 (C-360): the payable is the same identity the AP ageing
 * uses, and bank details deliberately stay off this page - they live behind
 * the manage permission, and a profile must not be the side door.
 */
export default function SupplierShow({
    timeline,
    documents,
    supplier,
    payable,
    totals,
    open_bills,
    recent,
}: {
    timeline?: TimelineEntry[];
    documents: IdentityDocuments;
    supplier: SupplierInfo;
    payable: { amount: string; is_credit: boolean };
    totals: { billed: string; paid: string; credited: string; open_pos: number };
    open_bills: OpenBill[];
    recent: Movement[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Suppliers', href: '/purchasing/suppliers' },
        { title: supplier.name, href: `/purchasing/suppliers/${supplier.id}` },
    ];

    const facts: [string, string | null][] = [
        ['Code', supplier.code],
        ['Contact', supplier.contact_name],
        ['Phone', supplier.phone],
        ['Email', supplier.email],
        ['Address', supplier.address],
        ['Payment terms', supplier.payment_terms],
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={supplier.name} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <ReportHeading
                        title={supplier.name}
                        description="Everything this supplier's documents say, in one place. The payable follows the same bills minus payments minus credit notes identity the ageing uses."
                    />
                    {!supplier.is_active && <Badge variant="secondary">Inactive</Badge>}
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">We owe now</p>
                        <p className="mt-1 text-xl font-semibold">
                            {payable.amount}
                            {payable.is_credit && <span className="text-muted-foreground ml-1 text-sm">(they owe us)</span>}
                        </p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Billed, lifetime</p>
                        <p className="mt-1 text-xl font-semibold">{totals.billed}</p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Paid, lifetime</p>
                        <p className="mt-1 text-xl font-semibold">{totals.paid}</p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Open purchase orders</p>
                        <p className="mt-1 text-xl font-semibold">{totals.open_pos}</p>
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
                            <p className="text-muted-foreground mt-3 border-t pt-3 text-xs">
                                Credited to date: {totals.credited}. Bank details live on the supplier list for those who manage suppliers.
                            </p>
                        </div>
                        {/* ⭐ Plan D11: trade licence and NID scans. */}
                        <IdentityDocumentsPanel
                            documents={documents}
                            uploadUrl={`/purchasing/suppliers/${supplier.id}/documents`}
                            removeUrl={(id) => `/purchasing/suppliers/${supplier.id}/documents/${id}`}
                        />
                    </div>

                    <div className="flex flex-col gap-6 lg:col-span-2">
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3" colSpan={5}>
                                            Open bills
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {open_bills.map((bill) => (
                                        <tr key={bill.id} className="border-t">
                                            <td className="p-3 font-mono">{bill.bill_number}</td>
                                            <td className="text-muted-foreground p-3">{bill.bill_date}</td>
                                            <td className="p-3">
                                                {bill.due_date && <span className="text-muted-foreground">due {bill.due_date}</span>}
                                                {bill.days_overdue > 0 && (
                                                    <span className="ml-2 text-xs font-medium text-red-600 dark:text-red-500">
                                                        {bill.days_overdue} day(s) overdue
                                                    </span>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground p-3 text-right">of {bill.total}</td>
                                            <td className="p-3 text-right font-medium">{bill.outstanding}</td>
                                        </tr>
                                    ))}
                                    {open_bills.length === 0 && (
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
                                        <th className="p-3" colSpan={3}>
                                            Recent activity
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent.map((movement, index) => (
                                        <tr key={index} className="border-t">
                                            <td className="text-muted-foreground p-3 whitespace-nowrap">{movement.date}</td>
                                            <td className="p-3">{movement.label}</td>
                                            <td className="p-3 text-right whitespace-nowrap">{movement.amount}</td>
                                        </tr>
                                    ))}
                                    {recent.length === 0 && (
                                        <tr>
                                            <td colSpan={3} className="text-muted-foreground p-4 text-center">
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
