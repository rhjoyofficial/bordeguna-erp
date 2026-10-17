import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogSupplierType {
    id: number;
    key: string;
    name: string;
    is_active: boolean;
    /** How many suppliers (active or not) and TDS/VDS rates carry this type. */
    suppliers: number;
    rates: number;
}

const blank = { name: '', key: '' };

/** What the server makes from a name when the key is left blank (SupplierTypes::keyFromName). */
const keyFrom = (name: string) =>
    name
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 28);

/**
 * Add a supplier type or correct one (17 Oct 2026), in a popup like the tax
 * rates beside it.
 *
 * ⚠ The key is what suppliers and rates store. Left blank it is made from the
 * name; once anything uses the type it is locked (the server refuses a change
 * too), while the name stays free to change.
 */
export function SupplierTypeDialog({
    open,
    onOpenChange,
    type,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The type being edited; null for a new one. */
    type: DialogSupplierType | null;
}) {
    const editing = type !== null;
    const keyLocked = Boolean(type && (type.suppliers > 0 || type.rates > 0));
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(blank);

    // Fresh fields every time it opens: blank for a new type, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = type ? { name: type.name, key: type.key } : blank;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, type]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (type) {
            put(route('purchasing.supplier-types.update', type.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('purchasing.supplier-types.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${type.name}` : 'Add a supplier type'}</DialogTitle>
                    </DialogHeader>

                    <div className="flex flex-col gap-1">
                        <Label htmlFor="supplier-type-name" required>
                            Name
                        </Label>
                        <Input
                            id="supplier-type-name"
                            required
                            aria-required
                            autoFocus
                            maxLength={100}
                            className="w-64 max-w-full"
                            placeholder="e.g. C&F agent"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="flex flex-col gap-1">
                        <Label htmlFor="supplier-type-key">Key</Label>
                        <Input
                            id="supplier-type-key"
                            maxLength={32}
                            className="w-64 max-w-full font-mono"
                            placeholder={keyFrom(data.name) || 'made from the name'}
                            disabled={keyLocked}
                            value={data.key}
                            onChange={(e) => setData('key', e.target.value)}
                        />
                        <p className="text-muted-foreground max-w-72 text-xs">
                            {keyLocked
                                ? 'Suppliers or tax rates use this type, so the key stays. The name can still change.'
                                : 'What the supplier file uses. Left blank, it is made from the name.'}
                        </p>
                        <InputError message={errors.key} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save' : 'Add type'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
