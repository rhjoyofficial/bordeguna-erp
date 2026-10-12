import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Rate {
    id: number;
    kind: string;
    supplier_type: string | null;
    name: string;
    rate: string;
    effective_from: string | null;
    effective_to: string | null;
    used: boolean;
}

interface Props {
    rates: Rate[];
    kinds: Record<string, string>;
    supplierTypes: Record<string, string>;
    today: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Payments', href: '/purchasing/payments' },
    { title: 'Tax rates', href: '/purchasing/withholding-rates' },
];

export default function WithholdingRates({ rates, kinds, supplierTypes, today }: Props) {
    const empty = { kind: 'tds', supplier_type: '', name: '', rate: '', effective_from: today, effective_to: '' };
    const form = useForm(empty);
    const [editing, setEditing] = useState<Rate | null>(null);

    const edit = (rate: Rate) => {
        setEditing(rate);
        form.clearErrors();
        form.setData({
            kind: rate.kind,
            supplier_type: rate.supplier_type ?? '',
            name: rate.name,
            rate: rate.rate,
            effective_from: rate.effective_from ?? today,
            effective_to: rate.effective_to ?? '',
        });
    };

    const cancel = () => {
        setEditing(null);
        form.setData(empty);
        form.clearErrors();
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) {
            form.put(route('purchasing.withholding-rates.update', editing.id), { preserveScroll: true, onSuccess: cancel });
        } else {
            form.post(route('purchasing.withholding-rates.store'), { preserveScroll: true, onSuccess: cancel });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tax rates" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">TDS and VDS rates</h1>
                    <p className="text-muted-foreground text-sm">
                        When you pay a supplier you can hold one of these back for NBR. The supplier's type decides which rates are offered.
                    </p>
                </div>

                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col gap-4 rounded-xl border p-4"
                >
                    <p className="text-sm font-medium">{editing ? `Editing ${editing.name}` : 'Add a rate'}</p>
                    <div className="flex flex-wrap items-start gap-4">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="kind" required>
                                Tax
                            </Label>
                            <SearchSelect fit id="kind" value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                                {Object.entries(kinds).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="supplier_type">Supplier type</Label>
                            <SearchSelect
                                fit
                                id="supplier_type"
                                value={form.data.supplier_type}
                                onChange={(e) => form.setData('supplier_type', e.target.value)}
                            >
                                <option value="">Any type</option>
                                {Object.entries(supplierTypes).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={form.errors.supplier_type} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="name" required>
                                Name
                            </Label>
                            <Input
                                id="name"
                                required
                                aria-required
                                className="w-64"
                                placeholder="e.g. Supply of goods"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate" required>
                                Rate %
                            </Label>
                            <Input
                                id="rate"
                                required
                                aria-required
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                className="w-28 text-right"
                                value={form.data.rate}
                                onChange={(e) => form.setData('rate', e.target.value)}
                            />
                            <InputError message={form.errors.rate} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="effective_from" required>
                                From
                            </Label>
                            <Input
                                id="effective_from"
                                required
                                aria-required
                                type="date"
                                className="w-auto"
                                value={form.data.effective_from}
                                onChange={(e) => form.setData('effective_from', e.target.value)}
                            />
                            <InputError message={form.errors.effective_from} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="effective_to">Until</Label>
                            <Input
                                id="effective_to"
                                type="date"
                                className="w-auto"
                                value={form.data.effective_to}
                                onChange={(e) => form.setData('effective_to', e.target.value)}
                            />
                            <InputError message={form.errors.effective_to} />
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button disabled={form.processing}>{editing ? 'Save' : 'Add rate'}</Button>
                        {editing && (
                            <Button type="button" variant="outline" onClick={cancel}>
                                Cancel
                            </Button>
                        )}
                    </div>
                </form>

                <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card overflow-x-auto rounded-xl border p-4">
                    {rates.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No rates yet.</p>
                    ) : (
                        <table className="w-full min-w-[44rem] text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-3">Tax</th>
                                    <th className="py-2 pr-3">Supplier type</th>
                                    <th className="py-2 pr-3">Name</th>
                                    <th className="py-2 pr-3 text-right">Rate</th>
                                    <th className="py-2 pr-3">From</th>
                                    <th className="py-2 pr-3">Until</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {rates.map((rate) => (
                                    <tr key={rate.id} className="border-b">
                                        <td className="py-2 pr-3 uppercase">{rate.kind}</td>
                                        <td className="py-2 pr-3">{rate.supplier_type ? supplierTypes[rate.supplier_type] : 'Any type'}</td>
                                        <td className="py-2 pr-3">{rate.name}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{rate.rate}%</td>
                                        <td className="py-2 pr-3">{rate.effective_from}</td>
                                        <td className="py-2 pr-3">{rate.effective_to ?? '-'}</td>
                                        <td className="space-x-3 py-2 text-right whitespace-nowrap">
                                            <button type="button" className="text-xs underline" onClick={() => edit(rate)}>
                                                Edit
                                            </button>
                                            {!rate.used && (
                                                <ConfirmDialog
                                                    trigger={<button className="text-muted-foreground text-xs underline">Delete</button>}
                                                    title="Delete this rate?"
                                                    description="No payment used it, so nothing else changes."
                                                    confirmLabel="Delete"
                                                    destructive
                                                    onConfirm={() =>
                                                        router.delete(route('purchasing.withholding-rates.destroy', rate.id), {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                />
                                            )}
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
