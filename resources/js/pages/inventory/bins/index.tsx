import { Field, FormRow } from '@/components/form/field';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Bin {
    id: number;
    warehouse_id: number;
    warehouse: string | null;
    code: string;
    name: string | null;
    is_active: boolean;
    occupied_count: number;
}

interface Warehouse {
    id: number;
    name: string;
    code: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Bins', href: '/inventory/bins' },
];

/**
 * One bin row. Mirrors the warehouses screen: the status is a control, Edit
 * turns code and name into inputs in place, and the delete refusal for a bin
 * with history arrives as a readable sentence from the server.
 */
function BinRow({ bin }: { bin: Bin }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, errors, processing, reset, clearErrors } = useForm({
        code: bin.code,
        name: bin.name ?? '',
    });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('inventory.bins.update', bin.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const cancel = () => {
        reset();
        clearErrors();
        setEditing(false);
    };

    const toggle = () => router.patch(route('inventory.bins.toggle-active', bin.id), {}, { preserveScroll: true });

    if (editing) {
        return (
            <tr className={cn('border-b', !bin.is_active && 'opacity-60')}>
                <td className="text-muted-foreground p-3">{bin.warehouse}</td>
                <td className="p-2" colSpan={2}>
                    <form id={`bin-${bin.id}`} onSubmit={save}>
                        <FormRow className="gap-3">
                            <Field label="Code" htmlFor={`code-${bin.id}`} size="xs" error={errors.code}>
                                <Input id={`code-${bin.id}`} value={data.code} onChange={(e) => setData('code', e.target.value)} autoFocus />
                            </Field>
                            <Field label="Name" htmlFor={`name-${bin.id}`} size="md" error={errors.name}>
                                <Input id={`name-${bin.id}`} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            </Field>
                        </FormRow>
                    </form>
                </td>
                <td className="p-2">
                    <StatusBadge bin={bin} />
                </td>
                <td className="p-2 text-right whitespace-nowrap">
                    <Button type="submit" form={`bin-${bin.id}`} size="sm" disabled={processing}>
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
        <tr className={cn('border-b', !bin.is_active && 'opacity-60')}>
            <td className="text-muted-foreground p-3">{bin.warehouse}</td>
            <td className="p-3 font-mono">{bin.code}</td>
            <td className="text-muted-foreground p-3">
                {bin.name}
                {bin.occupied_count > 0 && (
                    <span className="text-muted-foreground/70 ml-2 text-xs">
                        {bin.occupied_count} batch{bin.occupied_count === 1 ? '' : 'es'} placed
                    </span>
                )}
            </td>
            <td className="p-3">
                <button type="button" onClick={toggle} title={bin.is_active ? 'Click to switch off' : 'Click to switch on'} className="rounded-full">
                    <StatusBadge bin={bin} />
                </button>
            </td>
            <td className="p-3 text-right whitespace-nowrap">
                <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
                    Edit
                </Button>
                <Button variant="ghost" size="sm" onClick={() => router.delete(route('inventory.bins.destroy', bin.id), { preserveScroll: true })}>
                    Delete
                </Button>
            </td>
        </tr>
    );
}

function StatusBadge({ bin }: { bin: Bin }) {
    return <Badge variant={bin.is_active ? 'default' : 'secondary'}>{bin.is_active ? 'Active' : 'Inactive'}</Badge>;
}

export default function BinsIndex({ bins, warehouses }: { bins: Bin[]; warehouses: Warehouse[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({
        warehouse_id: warehouses.length === 1 ? String(warehouses[0].id) : '',
        code: '',
        name: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.bins.store'), { onSuccess: () => reset('code', 'name') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bins" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Bins"
                    description="Named shelf locations inside each warehouse. Place batches on them from the Bin placement screen, and the pick list will print the shelf beside the batch. Switch a bin off rather than deleting it once stock has sat in it."
                />

                <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-3">
                    <FormRow className="gap-3">
                        <Field label="Warehouse" htmlFor="warehouse_id" size="md" error={errors.warehouse_id} required>
                            <SearchSelect id="warehouse_id" value={data.warehouse_id} onChange={(e) => setData('warehouse_id', e.target.value)}>
                                <option value="">Choose a warehouse</option>
                                {warehouses.map((warehouse) => (
                                    <option key={warehouse.id} value={warehouse.id}>
                                        {warehouse.name} ({warehouse.code})
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Code" htmlFor="code" size="xs" error={errors.code} required>
                            <Input id="code" value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. A-1" />
                        </Field>
                        <Field label="Name (optional)" htmlFor="name" size="md" error={errors.name}>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Top rack, left" />
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
                                <th className="p-3">Warehouse</th>
                                <th className="p-3">Code</th>
                                <th className="p-3">Name</th>
                                <th className="p-3">Status</th>
                                <th className="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {bins.map((bin) => (
                                <BinRow key={bin.id} bin={bin} />
                            ))}
                            {bins.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-4 text-center">
                                        No bins yet. Add the shelf locations of a warehouse above.
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
