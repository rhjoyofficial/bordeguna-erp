import { PendingApprovalBadge, PurchaseApprovalDecision, type PurchaseApprovalRow } from '@/components/purchasing/purchase-approval-decision';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

type Approval = PurchaseApprovalRow & {
    details: { label: string; value: string }[];
    decision_note: string | null;
};

/**
 * ⭐ Purchasing approvals (ruling P7, Joy 18 Oct 2026): one bill, payment,
 * advance or refund waiting for a second person, with the reasons it needs one.
 */
export default function PurchaseApprovalShow({ approval }: { approval: Approval }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Waiting approval', href: '/purchasing/approvals' },
        { title: approval.number, href: `/purchasing/approvals/${approval.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={approval.number} />
            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-lg font-semibold">
                        {approval.kind_label} {approval.number}
                    </h1>
                    <PendingApprovalBadge status={approval.status} />
                </div>
                <p className="text-sm">{approval.summary}</p>

                <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950">
                    <div className="mb-1 font-medium">Why it needs a second person</div>
                    <ul className="list-disc pl-5">
                        {approval.reasons.map((reason) => (
                            <li key={reason}>{reason}</li>
                        ))}
                    </ul>
                    {approval.status === 'pending' && <p className="text-muted-foreground mt-2">Nothing is posted until it is approved.</p>}
                </div>

                <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1 text-sm">
                    <dt className="text-muted-foreground">Supplier</dt>
                    <dd>{approval.supplier}</dd>
                    <dt className="text-muted-foreground">Date</dt>
                    <dd>{showDate(approval.document_date)}</dd>
                    <dt className="text-muted-foreground">Amount</dt>
                    <dd className="tabular-nums">{formatMoney(approval.amount)}</dd>
                    {approval.details.map((row, index) => (
                        <div key={index} className="contents">
                            <dt className="text-muted-foreground">{row.label}</dt>
                            <dd>{row.value}</dd>
                        </div>
                    ))}
                    <dt className="text-muted-foreground">Entered by</dt>
                    <dd>{approval.created_by}</dd>
                    {approval.decided_by && (
                        <>
                            <dt className="text-muted-foreground">{approval.status === 'approved' ? 'Approved by' : 'Rejected by'}</dt>
                            <dd>
                                {approval.decided_by}
                                {approval.decision_note ? `: ${approval.decision_note}` : ''}
                            </dd>
                        </>
                    )}
                </dl>

                {approval.document_url && (
                    <Link href={approval.document_url} className="text-sm font-medium hover:underline">
                        Open the posted document
                    </Link>
                )}

                <PurchaseApprovalDecision approval={approval} />
            </div>
        </AppLayout>
    );
}
