import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { ReportHeading } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface RequestRow {
    id: number;
    request_number: string;
    requested_by: string | null;
    requested_at: string | null;
    line_count: number;
    status: 'pending' | 'approved' | 'rejected' | 'returned';
    status_label: string;
    applied_at: string | null;
    applied_by: string | null;
    url: string;
}

interface Plan {
    rowCount: number;
    changes: { line: number; code: string; name: string; limit: string | null; days: string | null }[];
    unchanged: number;
    errors: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/customers' },
    { title: 'Credit terms', href: '/sales/credit-terms' },
];

const tone: Record<RequestRow['status'], 'secondary' | 'default' | 'destructive' | 'outline'> = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
    returned: 'outline',
};

/**
 * Credit limits and days, granted by file and applied only after approval
 * (Joy, 26 Sep 2026, Q16). Download the customers with what they have, fill
 * the two new_ columns, upload, read the review, send. The authority approves
 * from here or from the approvals inbox; then every line lands at once.
 */
export default function CreditTermsIndex({
    requests,
    mayPrepare,
    mayApprove,
}: {
    requests: Paginated<RequestRow>;
    mayPrepare: boolean;
    mayApprove: boolean;
}) {
    const page = usePage().props as unknown as { creditTermsPlan?: Plan; errors?: Record<string, string> };
    const plan = page.creditTermsPlan;
    const review = useForm<{ file: File | null }>({ file: null });
    const submit = useForm({ note: '' });

    const sendReview: FormEventHandler = (e) => {
        e.preventDefault();
        review.post(route('sales.credit-terms.review'), { preserveScroll: true, forceFormData: true });
    };

    const sendSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        submit.post(route('sales.credit-terms.submit'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Credit terms" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Credit terms" />

                {mayPrepare && (
                    <section className="border-sidebar-border/70 dark:border-sidebar-border grid gap-4 rounded-md border p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-base font-semibold">1. Download, fill the two new_ columns, upload</h2>
                            </div>
                            <Button asChild variant="outline" size="sm">
                                {/* A plain link: this is a file download. */}
                                <a href="/sales/credit-terms/template">Download the credit terms file</a>
                            </Button>
                        </div>

                        <form onSubmit={sendReview} className="flex flex-wrap items-end gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="credit_terms_file" required>Filled file (CSV)</Label>
                                <Input
                                    id="credit_terms_file"
                                    type="file"
                                    accept=".csv,text/csv,text/plain"
                                    onChange={(e) => review.setData('file', e.target.files?.[0] ?? null)}
                                />
                                <InputError message={review.errors.file ?? page.errors?.file} />
                            </div>
                            <Button type="submit" variant="outline" disabled={review.processing || review.data.file === null}>
                                Review what would change
                            </Button>
                        </form>

                        {plan && (
                            <div className="grid gap-3 rounded-md border border-dashed p-3">
                                <h3 className="text-sm font-semibold">
                                    2. Review: {plan.changes.length} change(s), {plan.unchanged} unchanged, {plan.errors.length} error(s) in {plan.rowCount}{' '}
                                    row(s)
                                </h3>
                                {plan.errors.length > 0 && (
                                    <ul className="text-destructive grid gap-1 text-sm">
                                        {plan.errors.map((error, index) => (
                                            <li key={index}>{error}</li>
                                        ))}
                                    </ul>
                                )}
                                {plan.changes.length > 0 && (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead className="text-muted-foreground text-left">
                                                <tr>
                                                    <th className="py-1 pr-3">Code</th>
                                                    <th className="py-1 pr-3">Customer</th>
                                                    <th className="py-1 pr-3">Credit limit</th>
                                                    <th className="py-1">Credit days</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {plan.changes.map((change) => (
                                                    <tr key={change.line} className="border-t">
                                                        <td className="py-1 pr-3 font-mono text-xs">{change.code}</td>
                                                        <td className="py-1 pr-3">{change.name}</td>
                                                        <td className="py-1 pr-3 tabular-nums">{change.limit ?? '-'}</td>
                                                        <td className="py-1">{change.days ?? '-'}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                                {plan.errors.length === 0 && plan.changes.length > 0 && (
                                    <form onSubmit={sendSubmit} className="flex flex-wrap items-end gap-3">
                                        <div className="grid flex-1 gap-2">
                                            <Label htmlFor="credit_terms_note">Note for the approver (optional)</Label>
                                            <Input
                                                id="credit_terms_note"
                                                value={submit.data.note}
                                                onChange={(e) => submit.setData('note', e.target.value)}
                                                placeholder="e.g. terms agreed by the sales lead on the September visit"
                                            />
                                            <InputError message={submit.errors.note} />
                                        </div>
                                        <Button type="submit" disabled={submit.processing}>
                                            3. Send for approval
                                        </Button>
                                    </form>
                                )}
                            </div>
                        )}
                    </section>
                )}

                <section className="grid gap-2">
                    <h2 className="text-base font-semibold">Requests</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Request</th>
                                    <th className="p-3">Asked by</th>
                                    <th className="p-3">When</th>
                                    <th className="p-3 text-right">Customers</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3">Applied</th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.data.length === 0 && (
                                    <tr>
                                        <td className="text-muted-foreground p-3" colSpan={6}>
                                            No credit terms request yet.{mayApprove ? '' : ' Approval sits with the authority.'}
                                        </td>
                                    </tr>
                                )}
                                {requests.data.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="p-3 font-medium">
                                            <Link href={row.url} className="underline-offset-2 hover:underline">
                                                {row.request_number}
                                            </Link>
                                        </td>
                                        <td className="p-3">{row.requested_by ?? '-'}</td>
                                        <td className="text-muted-foreground p-3">{row.requested_at ?? '-'}</td>
                                        <td className="p-3 text-right tabular-nums">{row.line_count}</td>
                                        <td className="p-3">
                                            <Badge variant={tone[row.status]}>{row.status_label}</Badge>
                                        </td>
                                        <td className="text-muted-foreground p-3">{row.applied_at ? `${row.applied_at} by ${row.applied_by ?? '-'}` : '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination meta={requests} />
                </section>
            </div>
        </AppLayout>
    );
}
