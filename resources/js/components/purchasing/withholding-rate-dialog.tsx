import InputError from '@/components/input-error';
import { pickableSupplierTypes, supplierTypeLabel, type SupplierTypeOption } from '@/components/purchasing/supplier-types';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogWithholdingRate {
    id: number;
    kind: string;
    supplier_type: string | null;
    name: string;
    rate: string;
    effective_from: string | null;
    effective_to: string | null;
    used: boolean;
}

// A new rate starts as TDS, for any supplier type, in force from today.
const blank = (today: string) => ({ kind: 'tds', supplier_type: '', name: '', rate: '', effective_from: today, effective_to: '' });

function fromRate(rate: DialogWithholdingRate, today: string): ReturnType<typeof blank> {
    return {
        kind: rate.kind,
        supplier_type: rate.supplier_type ?? '',
        name: rate.name,
        rate: rate.rate,
        effective_from: rate.effective_from ?? today,
        effective_to: rate.effective_to ?? '',
    };
}

/**
 * One popup to add a TDS or VDS rate or correct one (Joy, 14 Oct 2026: "click
 * add button pop up will add ... same when edit"). The form that sat open
 * above the list is gone; the list is the page.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn.
 */
export function WithholdingRateDialog({
    open,
    onOpenChange,
    rate,
    kinds,
    supplierTypes,
    today,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The rate being edited; null for a new one. */
    rate: DialogWithholdingRate | null;
    kinds: Record<string, string>;
    /** The business's own types (17 Oct 2026); see supplier-types.ts. */
    supplierTypes: SupplierTypeOption[];
    today: string;
}) {
    const editing = rate !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(blank(today));

    // Fresh fields every time it opens: blank for a new rate, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = rate ? fromRate(rate, today) : blank(today);
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, rate]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (rate) {
            put(route('purchasing.withholding-rates.update', rate.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('purchasing.withholding-rates.store'), { preserveScroll: true, onSuccess: close });
    };

    // ⚠ A rate a payment was made under keeps its percentage (the controller
    // refuses a change), so the box is locked rather than left to fail on save.
    // The stored figure still posts; the name and the dates stay editable.
    const rateLocked = Boolean(rate?.used);

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${rate.name}` : 'Add a rate'}</DialogTitle>
                    </DialogHeader>

                    <div className="flex flex-wrap items-start gap-4">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate-kind" required>
                                Tax
                            </Label>
                            <SearchSelect fit id="rate-kind" value={data.kind} onChange={(e) => setData('kind', e.target.value)}>
                                {Object.entries(kinds).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.kind} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate-supplier_type">Supplier type</Label>
                            <SearchSelect
                                fit
                                id="rate-supplier_type"
                                value={data.supplier_type}
                                onChange={(e) => setData('supplier_type', e.target.value)}
                            >
                                <option value="">Any type</option>
                                {pickableSupplierTypes(supplierTypes, rate?.supplier_type).map((type) => (
                                    <option key={type.key} value={type.key}>
                                        {supplierTypeLabel(type)}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.supplier_type} />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-start gap-4">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate-name" required>
                                Name
                            </Label>
                            <Input
                                id="rate-name"
                                required
                                aria-required
                                className="w-64"
                                placeholder="e.g. Supply of goods"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate-rate" required>
                                Rate %
                            </Label>
                            <Input
                                id="rate-rate"
                                required
                                aria-required
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                className="w-28 text-right"
                                disabled={rateLocked}
                                value={data.rate}
                                onChange={(e) => setData('rate', e.target.value)}
                            />
                            {rateLocked && (
                                <p className="text-muted-foreground max-w-48 text-xs">Payments were made at this rate. End it and add a new one.</p>
                            )}
                            <InputError message={errors.rate} />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-start gap-4">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate-effective_from" required>
                                From
                            </Label>
                            <Input
                                id="rate-effective_from"
                                required
                                aria-required
                                type="date"
                                className="w-auto"
                                value={data.effective_from}
                                onChange={(e) => setData('effective_from', e.target.value)}
                            />
                            <InputError message={errors.effective_from} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate-effective_to">Until</Label>
                            <Input
                                id="rate-effective_to"
                                type="date"
                                className="w-auto"
                                value={data.effective_to}
                                onChange={(e) => setData('effective_to', e.target.value)}
                            />
                            <InputError message={errors.effective_to} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? `Save ${rate.name}` : 'Add rate'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
