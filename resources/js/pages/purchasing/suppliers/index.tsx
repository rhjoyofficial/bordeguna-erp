import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Supplier {
    id: number;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    payment_terms: string | null;
    is_active: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Suppliers', href: '/purchasing/suppliers' },
];

export default function SuppliersIndex({ suppliers }: { suppliers: Supplier[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({
        name: '',
        contact_name: '',
        phone: '',
        email: '',
        payment_terms: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('purchasing.suppliers.store'), { onSuccess: () => reset() });
    };

    const destroy = (supplier: Supplier) => {
        router.delete(route('purchasing.suppliers.destroy', supplier.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Suppliers" />
            <div className="flex flex-col gap-6 p-4">
                <form onSubmit={submit} className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="contact_name">Contact person</Label>
                        <Input id="contact_name" value={data.contact_name} onChange={(e) => setData('contact_name', e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="phone">Phone</Label>
                        <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="email">Email</Label>
                        <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        <InputError message={errors.email} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="payment_terms">Payment terms</Label>
                        <Input
                            id="payment_terms"
                            placeholder="e.g. Net 30"
                            value={data.payment_terms}
                            onChange={(e) => setData('payment_terms', e.target.value)}
                        />
                    </div>
                    <Button disabled={processing} className="col-span-2 w-fit sm:col-span-5">
                        Add supplier
                    </Button>
                </form>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                            <th className="py-2">Name</th>
                            <th className="py-2">Contact</th>
                            <th className="py-2">Phone</th>
                            <th className="py-2">Email</th>
                            <th className="py-2">Terms</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {suppliers.map((supplier) => (
                            <tr key={supplier.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">{supplier.name}</td>
                                <td className="py-2 text-muted-foreground">{supplier.contact_name ?? '—'}</td>
                                <td className="py-2 text-muted-foreground">{supplier.phone ?? '—'}</td>
                                <td className="py-2 text-muted-foreground">{supplier.email ?? '—'}</td>
                                <td className="py-2 text-muted-foreground">{supplier.payment_terms ?? '—'}</td>
                                <td className="py-2 text-right">
                                    <Button variant="ghost" size="sm" onClick={() => destroy(supplier)}>
                                        Deactivate
                                    </Button>
                                </td>
                            </tr>
                        ))}
                        {suppliers.length === 0 && (
                            <tr>
                                <td colSpan={6} className="py-4 text-center text-muted-foreground">
                                    No suppliers yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
