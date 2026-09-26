import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface Line {
    id: number;
    customer_id: number;
    code: string | null;
    name: string | null;
    type: string | null;
    current_limit: string;
    proposed_limit: string;
    limit_changes: boolean;
    current_days: string;
    proposed_days: string;
    days_changes: boolean;
    now_limit: string;
    now_days: string;
    drifted: boolean;
}

interface CreditTermsRequestView {
    id: number;
    request_number: string;
    requested_by: string | null;
    requested_at: string | null;
    line_count: number;
    status: 'pending' | 'approved' | 'rejected' | 'returned';
    status_label: string;
    applied_at: string | null;
    applied_by: string | null;
    note: string | null;
    source_file: string | null;
    review_note: string | null;
    lines: Line[];
}

const tone: Record<CreditTermsRequestView['status'], 'secondary' | 'default' | 'destructive' | 'outline'> = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
    returned: 'outline',
};

/**
 * One credit terms request: every customer, what they had when it was asked,
 * what is proposed, and what they have right now. The authority approves or
 * rejects here; approval applies every line in one transaction.
 */
export default function CreditTermsShow({ request, mayApprove }: { request: CreditTermsRequestView; mayApprove: boolean }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/customers' },
        { title: 'Credit terms', href: '/sales/credit-terms' },
        { title: request.request_number, href: `/sales/credit-terms/${request.id}` },
    ];

    const approve = (note: string) => router.post(route('sales.credit-terms.approve', request.id), { note });
    const reject = (review_note: string) => router.post(route('sales.credit-terms.reject', request.id), { review_note });
    const drifted = request.lines.filter((line) => line.drifted).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Credit terms ${request.request_number}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-semibold">
                            Credit terms {request.request_number}
                            <Badge variant={tone[request.status]}>{request.status_label}</Badge>
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Asked by {request.requested_by ?? '-'} on {request.requested_at ?? '-'}
                            {request.source_file ? ` from ${request.source_file}` : ''}. {request.line_count} customer(s).
                            {request.applied_at ? ` Applied ${request.applied_at} by ${request.applied_by ?? '-'}.` : ''}
                        </p>
                        {request.note && <p className="mt-1 text-sm">Note: {request.note}</p>}
                        {request.review_note && <p className="mt-1 text-sm">Reviewer: {request.review_note}</p>}
                    </div>
                    {request.status === 'pending' && mayApprove && (
                        <div className="flex gap-2">
                            <ConfirmDialog
                                trigger={<Button size="sm">Approve and apply</Button>}
                                title={`Approve ${request.request_number}`}
                                description={`Every one of the ${request.line_count} customer(s) below gets the proposed limit and days, all at once. ${drifted > 0 ? `${drifted} customer(s) changed since the request was made; the request's figures are applied and the record keeps what they overwrote.` : ''}`}
                                confirmLabel="Approve and apply"
                                withNote
                                noteLabel="Note (optional)"
                                notePlaceholder="Kept with the approval"
                                onConfirm={approve}
                            />
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" variant="ghost">
                                        Reject
                                    </Button>
                                }
                                title={`Reject ${request.request_number}`}
                                description="No customer changes. The person who asked sees your note and can send a corrected file."
                                confirmLabel="Reject"
                                destructive
                                withNote
                                noteLabel="Why (optional)"
                                notePlaceholder="e.g. Rahim Traders is over 90 days on two invoices"
                                onConfirm={reject}
                            />
                        </div>
                    )}
                    {request.status === 'pending' && !mayApprove && (
                        <p className="text-muted-foreground text-sm">Waiting for the authority to approve.</p>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Customer</th>
                                <th className="p-3">Type</th>
                                <th className="p-3">Credit limit</th>
                                <th className="p-3">Credit days</th>
                                <th className="p-3">Right now</th>
                            </tr>
                        </thead>
                        <tbody>
                            {request.lines.map((line) => (
                                <tr key={line.id} className="border-t align-top">
                                    <td className="p-3">
                                        <Link href={`/sales/customers/${line.customer_id}`} className="underline-offset-2 hover:underline">
                                            {line.code ? `${line.code} ` : ''}
                                            {line.name}
                                        </Link>
                                    </td>
                                    <td className="text-muted-foreground p-3">{line.type}</td>
                                    <td className="p-3 tabular-nums">
                                        {line.limit_changes ? (
                                            <>
                                                <span className="text-muted-foreground line-through">{line.current_limit}</span> <strong>{line.proposed_limit}</strong>
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">{line.current_limit} (unchanged)</span>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        {line.days_changes ? (
                                            <>
                                                <span className="text-muted-foreground line-through">{line.current_days}</span> <strong>{line.proposed_days}</strong>
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">{line.current_days} (unchanged)</span>
                                        )}
                                    </td>
                                    <td className={`p-3 text-xs ${line.drifted ? 'text-destructive' : 'text-muted-foreground'}`}>
                                        {line.now_limit}, {line.now_days}
                                        {line.drifted ? ' (changed since the request)' : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
