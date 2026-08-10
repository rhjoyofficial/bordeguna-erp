import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

interface OpenBill {
    id: number;
    bill_number: string;
    bill_date: string | null;
    outstanding: string;
}

interface CreditableReturn {
    id: number;
    returned_at: string | null;
    creditable: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Credit notes', href: '/purchasing/credit-notes' },
    { title: 'Record', href: '/purchasing/credit-notes/new' },
];

export default function CreditNoteCreate({
    suppliers,
    selectedSupplierId,
    returns,
    bills,
    today,
}: {
    suppliers: { id: number; name: string }[];
    selectedSupplierId: number | null;
    returns: CreditableReturn[];
    bills: OpenBill[];
    today: string;
}) {
    const { data, setData, post, errors, processing } = useForm({
        supplier_id: selectedSupplierId ? String(selectedSupplierId) : '',
        purchase_return_id: '',
        credit_note_number: '',
        credit_date: today,
        note: '',
        allocations: bills.map((bill) => ({ supplier_bill_id: bill.id, amount: '0' })),
    });

    // ⚠ Choosing the supplier reloads the page so the server sends THEIR
    // returns and open bills - the form never guesses what is creditable.
    const changeSupplier = (id: string) => {
        router.get('/purchasing/credit-notes/new', id ? { supplier_id: id } : {}, { preserveState: false });
    };

    const setAmount = (index: number, amount: string) => {
        const next = data.allocations.map((row, i) => (i === index ? { ...row, amount } : row));
        setData('allocations', next);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/purchasing/credit-notes');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Record Credit Note" />
            <form onSubmit={submit} className="flex max-w-3xl flex-col gap-6 p-4">
                <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="supplier_id">Supplier</Label>
                        <select
                            id="supplier_id"
                            className={selectClass}
                            value={data.supplier_id}
                            onChange={(e) => changeSupplier(e.target.value)}
                        >
                            <option value="">Choose a supplier</option>
                            {suppliers.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.supplier_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="purchase_return_id">Against return</Label>
                        {/* ⚠ Only returns with value left to credit are offered,
                            with the ceiling shown - the action refuses anything
                            beyond it. */}
                        <select
                            id="purchase_return_id"
                            className={selectClass}
                            value={data.purchase_return_id}
                            onChange={(e) => setData('purchase_return_id', e.target.value)}
                        >
                            <option value="">Choose the return this credit settles</option>
                            {returns.map((r) => (
                                <option key={r.id} value={r.id}>
                                    Return #{r.id} of {r.returned_at ?? 'unknown date'} ({r.creditable} creditable)
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.purchase_return_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="credit_note_number">Supplier's credit note number</Label>
                        <Input
                            id="credit_note_number"
                            value={data.credit_note_number}
                            onChange={(e) => setData('credit_note_number', e.target.value)}
                            placeholder="CN-2026-014"
                        />
                        <InputError message={errors.credit_note_number} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="credit_date">Credit date</Label>
                        <Input
                            id="credit_date"
                            type="date"
                            value={data.credit_date}
                            onChange={(e) => setData('credit_date', e.target.value)}
                        />
                        <InputError message={errors.credit_date} />
                    </div>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="note">Note</Label>
                        <textarea
                            id="note"
                            className="min-h-16 rounded-md border border-input bg-background px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Bill</th>
                                <th className="p-3">Date</th>
                                <th className="p-3 text-right">Outstanding</th>
                                <th className="p-3 text-right">Credit against this bill</th>
                            </tr>
                        </thead>
                        <tbody>
                            {bills.map((bill, index) => (
                                <tr key={bill.id} className="border-t">
                                    <td className="p-3 font-medium">{bill.bill_number}</td>
                                    <td className="p-3 text-muted-foreground">{bill.bill_date ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{bill.outstanding}</td>
                                    <td className="p-3 text-right">
                                        <Input
                                            className="ml-auto w-36 text-right"
                                            value={data.allocations[index]?.amount ?? '0'}
                                            onChange={(e) => setAmount(index, e.target.value)}
                                        />
                                        <InputError message={(errors as Record<string, string>)[`allocations.${index}.amount`]} />
                                    </td>
                                </tr>
                            ))}
                            {bills.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="p-6 text-center text-muted-foreground">
                                        {data.supplier_id
                                            ? 'This supplier has no open bills to credit against.'
                                            : 'Choose a supplier to see their open bills.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <InputError message={errors.allocations} />

                <div>
                    <Button type="submit" disabled={processing || bills.length === 0}>
                        Record credit note
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
