import { Field, FormRow } from '@/components/form/field';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef } from 'react';

export interface DialogBin {
    id: number;
    warehouse_id: number;
    warehouse: string | null;
    code: string;
    name: string | null;
}

export interface DialogBinWarehouse {
    id: number;
    name: string;
    code: string;
}

/**
 * Add a bin or correct its code and name in one popup (14 Oct 2026). Before
 * this, Add was a form above the list and Edit turned the row itself into
 * inputs; both are this dialog now. Same fields, same routes, same server
 * rules.
 *
 * ⚠ The warehouse is chosen once, when the bin is made: update() never reads
 * it, so editing shows it greyed out. "A-1" may exist in every warehouse, never
 * twice in one, and the server says so under the code.
 *
 * ⚠ The old form kept the warehouse after each Add so a run of shelves (A-1,
 * A-2, A-3...) did not mean choosing it every time. The popup closes after each
 * Add, so it remembers the last warehouse used instead.
 */
export function BinDialog({
    open,
    onOpenChange,
    bin,
    warehouses,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    bin: DialogBin | null;
    warehouses: DialogBinWarehouse[];
}) {
    const editing = bin !== null;
    const lastWarehouse = useRef('');
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm({ warehouse_id: '', code: '', name: '' });

    // Fresh fields every time it opens: blank for a new bin, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const remembered = warehouses.some((warehouse) => String(warehouse.id) === lastWarehouse.current) ? lastWarehouse.current : '';
        const next = bin
            ? { warehouse_id: String(bin.warehouse_id), code: bin.code, name: bin.name ?? '' }
            : { warehouse_id: remembered || (warehouses.length === 1 ? String(warehouses[0].id) : ''), code: '', name: '' };
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, bin]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (bin) {
            put(route('inventory.bins.update', bin.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        const chosen = data.warehouse_id;
        post(route('inventory.bins.store'), {
            preserveScroll: true,
            onSuccess: () => {
                lastWarehouse.current = chosen;
                close();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit bin ${bin.code}` : 'Add a bin'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        {editing ? (
                            <Field label="Warehouse" htmlFor="bin-warehouse" size="md" hint="A bin stays in its warehouse.">
                                <Input id="bin-warehouse" disabled value={bin.warehouse ?? ''} />
                            </Field>
                        ) : (
                            <Field label="Warehouse" htmlFor="bin-warehouse" size="md" error={errors.warehouse_id} required>
                                <SearchSelect id="bin-warehouse" value={data.warehouse_id} onChange={(e) => setData('warehouse_id', e.target.value)}>
                                    <option value="">Choose a warehouse</option>
                                    {warehouses.map((warehouse) => (
                                        <option key={warehouse.id} value={warehouse.id}>
                                            {warehouse.name} ({warehouse.code})
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                        )}
                        <Field label="Code" htmlFor="bin-code" size="xs" error={errors.code} required>
                            <Input
                                id="bin-code"
                                aria-required
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                placeholder="e.g. A-1"
                            />
                        </Field>
                        <Field label="Name (optional)" htmlFor="bin-name" size="md" error={errors.name}>
                            <Input
                                id="bin-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Top rack, left"
                            />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save bin' : 'Add bin'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
