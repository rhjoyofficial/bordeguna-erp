import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The authority's side of the Staff Product Benefit: approve or reject
 * pending applications, and hand over approved ones - which creates the
 * real sale. Nothing leaves the store without an approved application.
 */
interface RequestRow {
    id: number;
    number: string;
    employee: string | null;
    employee_code: string | null;
    payment_mode: string;
    status: string;
    applied_at: string;
    total_staff_price: number;
    sales_invoice_id: number | null;
    lines: { product: string | null; staff_price: number; mrp: number }[];
}

interface StaffPurchasesProps {
    requests: RequestRow[];
    moneyAccounts: { id: number; name: string; type: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Staff purchases', href: '/hrm/staff-purchases' }];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });

function RequestCard({ row, moneyAccounts }: { row: RequestRow; moneyAccounts: StaffPurchasesProps['moneyAccounts'] }) {
    const [rejectNote, setRejectNote] = useState('');
    const [account, setAccount] = useState('');
    const needsAccount = row.payment_mode !== 'salary_deduction';

    return (
        <div className="border-border bg-card space-y-2 rounded-lg border p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">
                    {row.number} · {row.employee} ({row.employee_code})
                    <span className="text-muted-foreground font-normal"> · {row.applied_at?.slice(0, 10)}</span>
                </p>
                <span className="text-muted-foreground text-xs capitalize">
                    {row.status} · {row.payment_mode.replace('_', ' ')}
                </span>
            </div>
            <p className="text-muted-foreground">
                {row.lines.map((line) => `${line.product} (${money(line.staff_price)}, MRP ${money(line.mrp)})`).join(' · ')} ={' '}
                <strong>{money(row.total_staff_price)}</strong>
            </p>

            {row.status === 'pending' && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        className="bg-emerald-600 text-white hover:bg-emerald-700"
                        onClick={() => router.post(`/hrm/staff-purchases/${row.id}/approve`, {}, { preserveScroll: true })}
                    >
                        Approve
                    </Button>
                    <Input
                        className="h-8 w-56"
                        placeholder="reason if rejecting"
                        value={rejectNote}
                        onChange={(e) => setRejectNote(e.target.value)}
                    />
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive"
                        disabled={rejectNote.trim() === ''}
                        onClick={() => router.post(`/hrm/staff-purchases/${row.id}/reject`, { decision_note: rejectNote }, { preserveScroll: true })}
                    >
                        Reject
                    </Button>
                </div>
            )}

            {row.status === 'approved' && (
                <div className="flex flex-wrap items-center gap-2">
                    {needsAccount && (
                        <Select value={account} onValueChange={setAccount}>
                            <SelectTrigger className="h-8 w-48">
                                <SelectValue placeholder="Paid into…" />
                            </SelectTrigger>
                            <SelectContent>
                                {moneyAccounts.map((item) => (
                                    <SelectItem key={item.id} value={String(item.id)}>
                                        {item.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <Button
                        size="sm"
                        disabled={needsAccount && account === ''}
                        onClick={() =>
                            router.post(`/hrm/staff-purchases/${row.id}/fulfill`, needsAccount ? { money_account_id: account } : {}, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Hand over
                    </Button>
                    {!needsAccount && <span className="text-muted-foreground text-xs">salary deduction posts to the next payroll</span>}
                </div>
            )}

            {row.status === 'fulfilled' && row.sales_invoice_id && (
                <Button variant="outline" size="sm" asChild>
                    <Link href={`/sales/invoices/${row.sales_invoice_id}`}>Open the sale</Link>
                </Button>
            )}
        </div>
    );
}

export default function StaffPurchases({ requests, moneyAccounts }: StaffPurchasesProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Staff purchases" />
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Staff purchases</h1>
                </div>

                {requests.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No applications yet.</p>
                ) : (
                    requests.map((row) => <RequestCard key={row.id} row={row} moneyAccounts={moneyAccounts} />)
                )}
            </div>
        </AppLayout>
    );
}
