import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Payment reminders (Module 2, Slice 5): who is overdue, how old the debt
 * is, when they were last chased - and the one button that sends the polite
 * letter. Nothing here sends itself; every reminder is a click with a name
 * on it.
 */
interface OverdueInvoice {
    number: string;
    date: string;
    due: string | null;
    days_overdue: number;
    outstanding: number | string;
}

interface OverdueCustomer {
    id: number;
    name: string;
    email: string | null;
    overdue_total: number;
    oldest_days: number;
    last_reminded: string | null;
    invoices: OverdueInvoice[];
}

interface DunningProps {
    customers: OverdueCustomer[];
    can_send: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Payment reminders', href: '/accounting/dunning' }];

const money = (value: number | string) => Number(value).toLocaleString();

export default function Dunning({ customers, can_send }: DunningProps) {
    const [open, setOpen] = useState<number | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payment reminders" />
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Payment reminders</h1>
                </div>

                {customers.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Nobody is overdue. Collections are clean.</p>
                ) : (
                    <div className="space-y-3">
                        {customers.map((customer) => (
                            <section key={customer.id} className="border-border bg-card rounded-lg border p-4">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h2 className="font-semibold">{customer.name}</h2>
                                        <p className="text-muted-foreground text-xs">
                                            {customer.email ?? 'no email on record'} ·{' '}
                                            {customer.last_reminded ? `last reminded ${customer.last_reminded}` : 'never reminded'}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <div className="text-right">
                                            <p className="text-destructive font-semibold tabular-nums">{money(customer.overdue_total)} BDT</p>
                                            <p className="text-muted-foreground text-xs">oldest {customer.oldest_days} days</p>
                                        </div>
                                        <Button variant="outline" size="sm" onClick={() => setOpen(open === customer.id ? null : customer.id)}>
                                            {open === customer.id ? 'Hide' : 'Invoices'}
                                        </Button>
                                        {can_send && (
                                            <Button
                                                size="sm"
                                                disabled={!customer.email}
                                                onClick={() => router.post(`/accounting/dunning/${customer.id}/remind`, {}, { preserveScroll: true })}
                                            >
                                                Send reminder
                                            </Button>
                                        )}
                                    </div>
                                </div>
                                {open === customer.id && (
                                    <table className="mt-3 w-full text-sm">
                                        <thead>
                                            <tr className="text-muted-foreground text-left text-xs">
                                                <th className="py-1 font-medium">Invoice</th>
                                                <th className="py-1 font-medium">Date</th>
                                                <th className="py-1 font-medium">Due</th>
                                                <th className="py-1 text-right font-medium">Days overdue</th>
                                                <th className="py-1 text-right font-medium">Outstanding</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {customer.invoices.map((invoice) => (
                                                <tr key={invoice.number} className="border-t">
                                                    <td className="py-1.5">{invoice.number}</td>
                                                    <td className="py-1.5">{invoice.date}</td>
                                                    <td className="py-1.5">{invoice.due ?? '-'}</td>
                                                    <td className="py-1.5 text-right tabular-nums">{invoice.days_overdue}</td>
                                                    <td className="py-1.5 text-right tabular-nums">{money(invoice.outstanding)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </section>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
