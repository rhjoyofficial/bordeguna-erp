import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface ApprovalRow {
    id: number;
    type: string;
    summary: string;
    requested_by: string | null;
    requested_at: string;
    approve_url: string | null;
    reject_url: string | null;
}

interface ApprovalsIndexProps {
    requests: ApprovalRow[];
    counts: { stock_requests: number; purchase_orders: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Approvals', href: '/approvals' }];

export default function ApprovalsIndex({ requests, counts }: ApprovalsIndexProps) {
    const approve = (row: ApprovalRow) => {
        if (row.approve_url) {
            router.post(row.approve_url);
        }
    };

    const reject = (row: ApprovalRow, review_note: string) => {
        if (row.reject_url) {
            router.post(row.reject_url, { review_note });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Approvals" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Badge variant="secondary">{counts.stock_requests} stock requests</Badge>
                    <Badge variant="secondary">{counts.purchase_orders} purchase orders</Badge>
                </div>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                            <th className="py-2">Type</th>
                            <th className="py-2">Details</th>
                            <th className="py-2">Requested by</th>
                            <th className="py-2">Requested</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {requests.map((row) => (
                            <tr key={`${row.type}-${row.id}`} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    <Badge variant="outline">{row.type}</Badge>
                                </td>
                                <td className="py-2">{row.summary}</td>
                                <td className="py-2 text-muted-foreground">{row.requested_by}</td>
                                <td className="py-2 text-muted-foreground">{row.requested_at}</td>
                                <td className="py-2 text-right whitespace-nowrap">
                                    <Button variant="ghost" size="sm" onClick={() => approve(row)}>
                                        Approve
                                    </Button>
                                    <ConfirmDialog
                                        trigger={
                                            <Button variant="ghost" size="sm">
                                                Reject
                                            </Button>
                                        }
                                        title={`Reject ${row.type.toLowerCase()}`}
                                        description={`${row.summary}. This cannot be undone.`}
                                        confirmLabel="Reject"
                                        destructive
                                        withNote
                                        noteLabel="Reason for rejecting (optional)"
                                        notePlaceholder="Add a note for the requester…"
                                        onConfirm={(note) => reject(row, note)}
                                    />
                                </td>
                            </tr>
                        ))}
                        {requests.length === 0 && (
                            <tr>
                                <td colSpan={5} className="py-4 text-center text-muted-foreground">
                                    Nothing pending — you're all caught up.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
