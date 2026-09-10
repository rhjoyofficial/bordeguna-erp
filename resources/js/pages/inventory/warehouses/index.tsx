import { Field, FormRow } from '@/components/form/field';
import { ReportHeading } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Warehouse {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
    is_active: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Warehouses', href: '/inventory/warehouses' },
];

/**
 * One warehouse row. The status is a button (30 Aug 2026 audit, root cause C:
 * `update` accepted `is_active` all along and the screen never sent it), and
 * Edit turns the name and code into inputs in place instead of opening a
 * second form.
 *
 * ⚠ The default warehouse's status button is disabled with the reason in its
 * title; the server refuses too, so a stale page cannot get past it.
 */
function WarehouseRow({ warehouse }: { warehouse: Warehouse }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, errors, processing, reset, clearErrors } = useForm({
        name: warehouse.name,
        code: warehouse.code,
        is_active: warehouse.is_active,
    });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('inventory.warehouses.update', warehouse.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const cancel = () => {
        reset();
        clearErrors();
        setEditing(false);
    };

    const toggle = () => router.patch(route('inventory.warehouses.toggle-active', warehouse.id), {}, { preserveScroll: true });

    if (editing) {
        return (
            <tr className={cn('border-b', !warehouse.is_active && 'opacity-60')}>
                <td className="p-2" colSpan={2}>
                    <form id={`warehouse-${warehouse.id}`} onSubmit={save}>
                        <FormRow className="gap-3">
                            <Field label="Name" htmlFor={`name-${warehouse.id}`} size="md" error={errors.name}>
                                <Input id={`name-${warehouse.id}`} value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus />
                            </Field>
                            <Field label="Code" htmlFor={`code-${warehouse.id}`} size="xs" error={errors.code}>
                                <Input id={`code-${warehouse.id}`} value={data.code} onChange={(e) => setData('code', e.target.value)} />
                            </Field>
                        </FormRow>
                    </form>
                </td>
                <td className="p-2">
                    <StatusBadge warehouse={warehouse} />
                </td>
                <td className="p-2 text-right whitespace-nowrap">
                    <Button type="submit" form={`warehouse-${warehouse.id}`} size="sm" disabled={processing}>
                        Save
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={cancel} disabled={processing}>
                        Cancel
                    </Button>
                </td>
            </tr>
        );
    }

    return (
        <tr className={cn('border-b', !warehouse.is_active && 'opacity-60')}>
            <td className="p-3">{warehouse.name}</td>
            <td className="text-muted-foreground p-3 font-mono">{warehouse.code}</td>
            <td className="p-3">
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={toggle}
                        disabled={warehouse.is_default && warehouse.is_active}
                        title={
                            warehouse.is_default
                                ? 'The default warehouse cannot be switched off'
                                : warehouse.is_active
                                  ? 'Click to switch off'
                                  : 'Click to switch on'
                        }
                        className="rounded-full disabled:cursor-not-allowed"
                    >
                        <StatusBadge warehouse={warehouse} />
                    </button>
                    {warehouse.is_default && <Badge variant="outline">Default</Badge>}
                </div>
            </td>
            <td className="p-3 text-right whitespace-nowrap">
                <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
                    Edit
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={warehouse.is_default}
                    onClick={() => router.delete(route('inventory.warehouses.destroy', warehouse.id), { preserveScroll: true })}
                    title={warehouse.is_default ? 'The default warehouse cannot be deleted' : undefined}
                >
                    Delete
                </Button>
            </td>
        </tr>
    );
}

function StatusBadge({ warehouse }: { warehouse: Warehouse }) {
    return <Badge variant={warehouse.is_active ? 'default' : 'secondary'}>{warehouse.is_active ? 'Active' : 'Inactive'}</Badge>;
}

export default function WarehousesIndex({ warehouses }: { warehouses: Warehouse[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({ name: '', code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.warehouses.store'), { onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Warehouses" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Warehouses"
                    description="Where stock is kept. Switch a warehouse off rather than deleting it once it has held stock; the default warehouse receives every document that does not name one."
                />

                <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-3">
                    <FormRow className="gap-3">
                        <Field label="Name" htmlFor="name" size="md" error={errors.name} required>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Cold Storage" />
                        </Field>
                        <Field label="Code" htmlFor="code" size="xs" error={errors.code} required>
                            <Input id="code" value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. COLD" />
                        </Field>
                        <Button disabled={processing} className="self-end">
                            Add
                        </Button>
                    </FormRow>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Name</th>
                                <th className="p-3">Code</th>
                                <th className="p-3">Status</th>
                                <th className="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {warehouses.map((warehouse) => (
                                <WarehouseRow key={warehouse.id} warehouse={warehouse} />
                            ))}
                            {warehouses.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="text-muted-foreground p-4 text-center">
                                        No warehouses yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
