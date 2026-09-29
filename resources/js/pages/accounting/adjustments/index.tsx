import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Option {
    id: number;
    label: string;
}

interface OpenDocument {
    id: number;
    party_id: number;
    number: string;
    date: string | null;
    outstanding: string;
}

interface Row {
    id: number;
    kind: string;
    date: string | null;
    party: string;
    amount: string;
    against: string;
    reason: string;
    by: string | null;
}

interface Props {
    kinds: { value: string; label: string }[];
    adjustments: Row[];
    customers: Option[];
    suppliers: Option[];
    openInvoices: OpenDocument[];
    openBills: OpenDocument[];
    today: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Adjustments', href: '/accounting/adjustments' }];

type FormData = {
    kind: string;
    adjusted_on: string;
    customer_id: string;
    supplier_id: string;
    invoices: Record<string, string>;
    bills: Record<string, string>;
    reason: string;
};

function Documents({
    title,
    rows,
    values,
    onChange,
}: {
    title: string;
    rows: OpenDocument[];
    values: Record<string, string>;
    onChange: (id: number, value: string) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            <Label>{title}</Label>
            {rows.length === 0 ? (
                <p className="text-muted-foreground text-sm">Nothing open.</p>
            ) : (
                <table className="w-full max-w-2xl text-sm">
                    <thead>
                        <tr className="text-muted-foreground border-b text-left">
                            <th className="py-1 pr-3">Number</th>
                            <th className="py-1 pr-3">Date</th>
                            <th className="py-1 pr-3 text-right">Still owed</th>
                            <th className="py-1 text-right">Adjust by</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.id} className="border-b">
                                <td className="py-1 pr-3">{row.number}</td>
                                <td className="py-1 pr-3">{row.date}</td>
                                <td className="py-1 pr-3 text-right tabular-nums">{row.outstanding}</td>
                                <td className="py-1 text-right">
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        className="ml-auto w-32 text-right"
                                        value={values[row.id] ?? ''}
                                        onChange={(e) => onChange(row.id, e.target.value)}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

export default function Adjustments({ kinds, adjustments, customers, suppliers, openInvoices, openBills, today }: Props) {
    const form = useForm<FormData>({
        kind: 'customer_write_off',
        adjusted_on: today,
        customer_id: '',
        supplier_id: '',
        invoices: {},
        bills: {},
        reason: '',
    });

    const needsCustomer = form.data.kind !== 'supplier_discount';
    const needsSupplier = form.data.kind === 'supplier_discount' || form.data.kind === 'set_off';
    const invoices = openInvoices.filter((i) => String(i.party_id) === form.data.customer_id);
    const bills = openBills.filter((b) => String(b.party_id) === form.data.supplier_id);
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('accounting.adjustments.store'), {
            preserveScroll: true,
            onSuccess: () => form.setData({ ...form.data, invoices: {}, bills: {}, reason: '' }),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Adjustments" />
            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-xl font-semibold">Adjustments</h1>

                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col gap-4 rounded-xl border p-4"
                >
                    <div className="flex flex-wrap items-start gap-4">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="kind" required>
                                Kind
                            </Label>
                            <SearchSelect
                                fit
                                id="kind"
                                value={form.data.kind}
                                onChange={(e) => form.setData({ ...form.data, kind: e.target.value, invoices: {}, bills: {} })}
                            >
                                {kinds.map((kind) => (
                                    <option key={kind.value} value={kind.value}>
                                        {kind.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="adjusted_on" required>
                                Date
                            </Label>
                            <Input
                                id="adjusted_on"
                                type="date"
                                className="w-auto"
                                value={form.data.adjusted_on}
                                onChange={(e) => form.setData('adjusted_on', e.target.value)}
                            />
                            <InputError message={errors.adjusted_on} />
                        </div>
                        {needsCustomer && (
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="customer_id" required>
                                    Customer
                                </Label>
                                <SearchSelect
                                    fit
                                    id="customer_id"
                                    value={form.data.customer_id}
                                    onChange={(e) => form.setData({ ...form.data, customer_id: e.target.value, invoices: {} })}
                                >
                                    <option value="">Choose</option>
                                    {customers.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.customer_id} />
                            </div>
                        )}
                        {needsSupplier && (
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="supplier_id" required>
                                    Supplier
                                </Label>
                                <SearchSelect
                                    fit
                                    id="supplier_id"
                                    value={form.data.supplier_id}
                                    onChange={(e) => form.setData({ ...form.data, supplier_id: e.target.value, bills: {} })}
                                >
                                    <option value="">Choose</option>
                                    {suppliers.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.supplier_id} />
                            </div>
                        )}
                    </div>

                    {needsCustomer && form.data.customer_id !== '' && (
                        <Documents
                            title="Open invoices"
                            rows={invoices}
                            values={form.data.invoices}
                            onChange={(id, value) => form.setData('invoices', { ...form.data.invoices, [id]: value })}
                        />
                    )}
                    {needsSupplier && form.data.supplier_id !== '' && (
                        <Documents
                            title="Open bills"
                            rows={bills}
                            values={form.data.bills}
                            onChange={(id, value) => form.setData('bills', { ...form.data.bills, [id]: value })}
                        />
                    )}
                    <InputError message={errors.invoices ?? errors.bills ?? errors.kind} />

                    <div className="flex flex-col gap-1">
                        <Label htmlFor="reason" required>
                            Reason
                        </Label>
                        <Input id="reason" className="max-w-xl" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                        <InputError message={errors.reason} />
                    </div>

                    <div>
                        <Button disabled={form.processing}>Post adjustment</Button>
                    </div>
                </form>

                <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card overflow-x-auto rounded-xl border p-4">
                    {adjustments.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No adjustments yet.</p>
                    ) : (
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-3">Date</th>
                                    <th className="py-2 pr-3">Kind</th>
                                    <th className="py-2 pr-3">Party</th>
                                    <th className="py-2 pr-3">Against</th>
                                    <th className="py-2 pr-3 text-right">Amount</th>
                                    <th className="py-2 pr-3">Reason</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {adjustments.map((row) => (
                                    <tr key={row.id} className="border-b align-top">
                                        <td className="py-2 pr-3">{row.date}</td>
                                        <td className="py-2 pr-3">{row.kind}</td>
                                        <td className="py-2 pr-3">{row.party}</td>
                                        <td className="py-2 pr-3">{row.against}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{row.amount}</td>
                                        <td className="text-muted-foreground py-2 pr-3">{row.reason}</td>
                                        <td className="py-2 text-right">
                                            <ConfirmDialog
                                                trigger={<button className="text-muted-foreground text-xs underline">Remove</button>}
                                                title="Remove this adjustment?"
                                                description="Its journal is reversed and the invoices or bills are open again."
                                                confirmLabel="Remove"
                                                destructive
                                                onConfirm={() =>
                                                    router.delete(route('accounting.adjustments.destroy', row.id), { preserveScroll: true })
                                                }
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
