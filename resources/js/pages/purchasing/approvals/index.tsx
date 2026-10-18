import { PendingApprovalBadge, PurchaseApprovalDecision, type PurchaseApprovalRow } from '@/components/purchasing/purchase-approval-decision';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Waiting approval', href: '/purchasing/approvals' },
];

const TABS = [
    { key: 'pending', label: 'Waiting' },
    { key: 'approved', label: 'Approved' },
    { key: 'rejected', label: 'Rejected' },
    { key: 'all', label: 'All' },
] as const;

/**
 * ⭐ Purchasing approvals (ruling P7, Joy 18 Oct 2026): bills, payments,
 * advances and refunds that need a second person. Nothing on this list has
 * touched the books; approving posts it as it was entered.
 */
export default function PurchaseApprovalsIndex({ approvals, status }: { approvals: PurchaseApprovalRow[]; status: string }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Waiting approval" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    {TABS.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => router.get('/purchasing/approvals', { status: tab.key }, { preserveState: true, replace: true })}
                            className={`rounded-full border px-3 py-1 text-sm ${status === tab.key ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Number</th>
                                <th className="p-3">What</th>
                                <th className="p-3">Date</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3">Why it needs approval</th>
                                <th className="p-3">Entered by</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {approvals.map((row) => (
                                <tr key={row.id} className="border-t align-top">
                                    <td className="p-3 font-medium whitespace-nowrap">
                                        <Link href={`/purchasing/approvals/${row.id}`} className="hover:underline">
                                            {row.number}
                                        </Link>
                                        <div className="mt-1">
                                            <PendingApprovalBadge status={row.status} />
                                        </div>
                                    </td>
                                    <td className="p-3">
                                        <div className="font-medium">{row.kind_label}</div>
                                        <div className="text-muted-foreground">{row.summary}</div>
                                    </td>
                                    <td className="p-3 whitespace-nowrap">{showDate(row.document_date)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(row.amount)}</td>
                                    <td className="p-3">
                                        <ul className="list-disc pl-4">
                                            {row.reasons.map((reason) => (
                                                <li key={reason}>{reason}</li>
                                            ))}
                                        </ul>
                                    </td>
                                    <td className="p-3 whitespace-nowrap">{row.created_by ?? '-'}</td>
                                    <td className="p-3">
                                        {row.document_url ? (
                                            <Link href={row.document_url} className="text-sm hover:underline">
                                                Open the posted document
                                            </Link>
                                        ) : (
                                            <PurchaseApprovalDecision approval={row} />
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {approvals.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                        Nothing here.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
