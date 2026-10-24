import { PartyCard, type PartyCardProps } from '@/components/accounting/party-card';
import { type PartyRecord, PartyRecordActions } from '@/components/corrections/party-record-actions';
import { type PartyDocuments, PartyDocumentsPanel } from '@/components/documents/party-documents-panel';
import { type IdentityDocuments, IdentityDocumentsPanel } from '@/components/identity-documents-panel';
import { useTermsLabel } from '@/components/purchasing/payment-terms-fields';
import { SupplierCreditPanel, type SupplierCreditView } from '@/components/purchasing/supplier-credit-panel';
import RecordTimeline, { type TimelineEntry } from '@/components/record-timeline';
import { ReportHeading } from '@/components/report/report-shell';
import { type SupplierCertification, SupplierCertificationsPanel } from '@/components/supplier-certifications-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

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
    certifications,
    certification_types,
    can_manage_certifications,
    supplier,
    payable,
    totals,
    open_bills,
    all_documents,
    party,
    advances_open = '0.00',
    credit,
    record,
}: {
    timeline?: TimelineEntry[];
    documents: IdentityDocuments;
    certifications: SupplierCertification[];
    certification_types: { value: string; label: string }[];
    can_manage_certifications: boolean;
    supplier: SupplierInfo;
    payable: { amount: string; is_credit: boolean };
    totals: { billed: string; paid: string; credited: string; open_pos: number };
    open_bills: OpenBill[];
    /** Kept for older readers; the page shows `all_documents`, a superset. */
    recent?: Movement[];
    all_documents: PartyDocuments;
    // ⭐ D-F (18 Oct 2026): the same firm on the other side of the books.
    party: PartyCardProps;
    /** ⭐ Supplier advances (ruling P2): what this supplier holds of ours, apart from what we owe. */
    advances_open?: string;
    /** ⭐ Ruling P6: what we stand to owe them, in parts, beside the credit limit. */
    credit?: SupplierCreditView;
    /** ⭐ B13 (24 Oct 2026): Reactivate, Delete to the bin. */
    record?: PartyRecord;
}) {
    const { t } = useTranslation();
    const { canViewAccounting } = usePage<SharedData>().props;
    const termsLabel = useTermsLabel();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Suppliers', href: '/purchasing/suppliers' },
        { title: supplier.name, href: `/purchasing/suppliers/${supplier.id}` },
    ];

    const facts: [string, string | null][] = [
        ['Code', supplier.code],
        ['Contact', supplier.contact_name],
        ['Phone', supplier.phone],
        ['Email', supplier.email],
        ['Address', supplier.address],
        // ⭐ Ruling P6: the structured terms bills are due on, then the old note.
        [t('terms.title'), credit?.terms ? termsLabel(credit.terms.type, credit.terms.days) : null],
        [t('terms.note'), supplier.payment_terms],
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={supplier.name} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <ReportHeading
                        title={supplier.name}
                        actions={
                            // ⭐ 18 Oct 2026 (A1): payable, received-not-billed and advances in one view.
                            canViewAccounting ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={route('accounting.supplier-ledger', { supplier: supplier.id })}>Ledger</Link>
                                </Button>
                            ) : undefined
                        }
                    />
                    {!supplier.is_active && <Badge variant="secondary">Inactive</Badge>}
                </div>

                {/* ── Supplier advances (ruling P2) ── */}
                <p className="text-sm">
                    <span className="text-muted-foreground">Advances with this supplier: </span>
                    <span className="font-medium tabular-nums">{formatMoney(advances_open)}</span>
                    {' · '}
                    <Link href={`/purchasing/suppliers/${supplier.id}/advances`} className="hover:underline">
                        Advances, apply to bills, refunds
                    </Link>
                </p>
                {/* ── end supplier advances ── */}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">We owe now</p>
                        <p className="mt-1 text-xl font-semibold">
                            {formatMoney(payable.amount)}
                            {payable.is_credit && <span className="text-muted-foreground ml-1 text-sm">(they owe us)</span>}
                        </p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">Billed, lifetime</p>
                        <p className="mt-1 text-xl font-semibold">{formatMoney(totals.billed)}</p>
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

                <PartyCard party={party} side="supplier" selfId={supplier.id} />

                {credit && <SupplierCreditPanel credit={credit} />}

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
                            <p className="text-muted-foreground mt-3 border-t pt-3 text-xs">Credited to date: {totals.credited}</p>
                        </div>
                        {/* ⭐ Plan D11: trade licence and NID scans. */}
                        <IdentityDocumentsPanel
                            documents={documents}
                            uploadUrl={`/purchasing/suppliers/${supplier.id}/documents`}
                            removeUrl={(id) => `/purchasing/suppliers/${supplier.id}/documents/${id}`}
                        />
                        {/* ⭐ Joy's answer 18: certifications, only when relevant. */}
                        <SupplierCertificationsPanel
                            supplierId={supplier.id}
                            certifications={certifications}
                            types={certification_types}
                            canManage={can_manage_certifications}
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
                                            <td className="p-3 font-mono">
                                                <Link href={`/purchasing/bills/${bill.id}`} className="underline-offset-2 hover:underline">
                                                    {bill.bill_number}
                                                </Link>
                                            </td>
                                            <td className="text-muted-foreground p-3">{showDate(bill.bill_date)}</td>
                                            <td className="p-3">
                                                {bill.due_date && <span className="text-muted-foreground">due {showDate(bill.due_date)}</span>}
                                                {bill.days_overdue > 0 && (
                                                    <span className="ml-2 text-xs font-medium text-red-600 dark:text-red-500">
                                                        {bill.days_overdue} day(s) overdue
                                                    </span>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground p-3 text-right">of {formatMoney(bill.total)}</td>
                                            <td className="p-3 text-right font-medium">{formatMoney(bill.outstanding)}</td>
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

                        {/* ⭐ PR5 (18 Oct 2026): every document with this supplier, each one opening. */}
                        <PartyDocumentsPanel documents={all_documents} baseUrl={`/purchasing/suppliers/${supplier.id}`} />
                    </div>
                </div>
            </div>
            {record && (
                <div className="mx-4 mb-4">
                    <PartyRecordActions record={record} name={supplier.name} />
                </div>
            )}
            <div className="border-border bg-card mx-4 mb-4 rounded-lg border p-4">
                <h2 className="mb-3 text-sm font-semibold">Timeline</h2>
                <RecordTimeline entries={timeline ?? []} />
            </div>
        </AppLayout>
    );
}
