import { Field, FormRow } from '@/components/form/field';
import { ReportHeading } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Unit {
    id: number;
    name: string;
    abbreviation: string;
    products_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Units of measure', href: '/inventory/units' },
];

/**
 * One unit. Edit turns the row into inputs in place, like warehouses.
 *
 * ⚠ The update route existed and the screen offered only Delete (21 Sep 2026
 * audit), so a misspelt unit already on products could be neither fixed nor
 * removed. Delete is disabled, with the reason, once products use the unit;
 * the server refuses too, so a stale page cannot get past it.
 */
function UnitRow({ unit, canManage }: { unit: Unit; canManage: boolean }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, errors, processing, reset, clearErrors } = useForm({ name: unit.name, abbreviation: unit.abbreviation });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('inventory.units.update', unit.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const cancel = () => {
        reset();
        clearErrors();
        setEditing(false);
    };

    if (editing) {
        return (
            <tr className="border-b">
                <td className="p-2" colSpan={2}>
                    <form id={`unit-${unit.id}`} onSubmit={save}>
                        <FormRow className="gap-3">
                            <Field label="Name" htmlFor={`unit-name-${unit.id}`} size="md" error={errors.name} required>
                                <Input id={`unit-name-${unit.id}`} value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus />
                            </Field>
                            <Field label="Abbreviation" htmlFor={`unit-abbr-${unit.id}`} size="xs" error={errors.abbreviation} required>
                                <Input
                                    id={`unit-abbr-${unit.id}`}
                                    value={data.abbreviation}
                                    onChange={(e) => setData('abbreviation', e.target.value)}
                                />
                            </Field>
                        </FormRow>
                    </form>
                </td>
                <td className="text-muted-foreground p-3 text-right tabular-nums">{unit.products_count}</td>
                <td className="p-2 text-right whitespace-nowrap">
                    <Button type="submit" form={`unit-${unit.id}`} size="sm" disabled={processing}>
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
        <tr className="border-b">
            <td className="p-3">{unit.name}</td>
            <td className="text-muted-foreground p-3 font-mono">{unit.abbreviation}</td>
            <td className="text-muted-foreground p-3 text-right tabular-nums">{unit.products_count}</td>
            <td className="p-3 text-right whitespace-nowrap">
                {canManage && (
                    <>
                        <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
                            Edit
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={unit.products_count > 0}
                            title={unit.products_count > 0 ? 'Products use this unit, so it cannot be deleted. Edit it instead.' : undefined}
                            onClick={() => router.delete(route('inventory.units.destroy', unit.id), { preserveScroll: true })}
                        >
                            Delete
                        </Button>
                    </>
                )}
            </td>
        </tr>
    );
}

export default function UnitsIndex({ units }: { units: Unit[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({ name: '', abbreviation: '' });
    // ⚠ Staff read this page and were offered Add and Delete, both 403.
    const { manageCatalog } = useInventoryAbilities();

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.units.store'), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Units of measure" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Units of measure"
                    description="How each product is counted and sold: pieces, kilograms, litres, jars. Every product needs one."
                />

                {manageCatalog && (
                    <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-3">
                        <FormRow className="gap-3">
                            <Field label="Name" htmlFor="name" size="md" error={errors.name} required>
                                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Kilogram" />
                            </Field>
                            <Field label="Abbreviation" htmlFor="abbreviation" size="xs" error={errors.abbreviation} required>
                                <Input
                                    id="abbreviation"
                                    value={data.abbreviation}
                                    onChange={(e) => setData('abbreviation', e.target.value)}
                                    placeholder="e.g. kg"
                                />
                            </Field>
                            <Button disabled={processing} className="self-end">
                                Add
                            </Button>
                        </FormRow>
                    </form>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Name</th>
                                <th className="p-3">Abbreviation</th>
                                <th className="p-3 text-right">Products</th>
                                <th className="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {units.map((unit) => (
                                <UnitRow key={unit.id} unit={unit} canManage={manageCatalog} />
                            ))}
                            {units.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="text-muted-foreground p-4 text-center">
                                        {manageCatalog
                                            ? 'No units yet. Add the ones you count in above; a product cannot be saved without one.'
                                            : 'No units yet. Ask a manager to add them.'}
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
