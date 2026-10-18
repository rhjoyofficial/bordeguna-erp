import { type CustomerLedgerData, CustomerLedgerView } from '@/components/accounting/customer-ledger-view';
import { FilterBar, ReportHeading, ReportPage } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Customer ledger', href: '/accounting/customer-ledger' },
];

/**
 * ⭐ One customer across Accounts Receivable and Customer Advances (party
 * ledgers, 20 Oct 2026): the view the General Ledger opens for a customer's
 * head, on its own page, reachable from the customer's profile and from
 * Sundry Debtors.
 */
export default function CustomerLedgerIndex({
    from,
    to,
    customers,
    ledger,
}: {
    from: string;
    to: string;
    customers: { id: number; label: string }[];
    ledger: CustomerLedgerData | null;
}) {
    const { t } = useTranslation();
    const [filters, setFilters] = useState({ customer: ledger ? String(ledger.customer.id) : '', from, to });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.customer-ledger'), filters, { preserveState: true });
    };

    const title = ledger ? `${t('pl.customer_ledger')}: ${ledger.customer.name}` : t('pl.customer_ledger');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <ReportPage>
                <ReportHeading
                    title={title}
                    description={ledger ? `${showDate(from)} to ${showDate(to)}` : undefined}
                    actions={
                        ledger ? (
                            <>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={route('accounting.customer-statement', { customer: ledger.customer.id, from, to })}>Statement</Link>
                                </Button>
                                <Button size="sm" onClick={() => window.print()}>
                                    Print
                                </Button>
                            </>
                        ) : undefined
                    }
                />

                <FilterBar onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="customer">Customer</Label>
                        <SearchSelect
                            fit
                            id="customer"
                            value={filters.customer}
                            onChange={(e) => setFilters({ ...filters, customer: e.target.value })}
                        >
                            <option value="">Choose a customer</option>
                            {customers.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
                    </div>
                </FilterBar>

                {ledger === null ? (
                    <p className="text-muted-foreground py-6 text-center text-sm">{t('pl.choose_customer')}</p>
                ) : (
                    <CustomerLedgerView ledger={ledger} />
                )}
            </ReportPage>
        </AppLayout>
    );
}
