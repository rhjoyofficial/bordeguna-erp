import { Field, FormRow } from '@/components/form/field';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogAssetCategory {
    id: number;
    name: string;
    code: string;
    default_tracking: string;
    default_useful_life_months: number | null;
    is_active: boolean;
    items_count: number;
}

interface Option {
    value: string;
    label: string;
}

const EMPTY = {
    name: '',
    code: '',
    default_tracking: 'individual',
    default_useful_life_months: '',
    is_active: true as boolean,
};

function fromCategory(category: DialogAssetCategory): typeof EMPTY {
    return {
        name: category.name,
        code: category.code,
        default_tracking: category.default_tracking,
        default_useful_life_months: category.default_useful_life_months?.toString() ?? '',
        is_active: category.is_active,
    };
}

/**
 * Add a kind of thing the business keeps, or correct one, in one popup (Joy,
 * 14 Oct 2026: "click add button pop up will add, same when edit"). Add was a
 * form above the list and Edit replaced the row; both are this dialog now.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn.
 *
 * ⚠ The code is on every sticker: it is locked once a thing carries it, and
 * the server refuses a change too.
 */
export function AssetCategoryDialog({
    open,
    onOpenChange,
    category,
    trackings,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    category: DialogAssetCategory | null;
    trackings: Option[];
}) {
    const editing = category !== null;
    const codeLocked = category !== null && category.items_count > 0;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new category, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = category ? fromCategory(category) : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, category]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (category) {
            put(`/assets/categories/${category.id}`, { preserveScroll: true, onSuccess: close });

            return;
        }

        post('/assets/categories', { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${category.name}` : 'Add a category'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name" htmlFor="asset-category-name" className="w-56" error={errors.name} required>
                            <Input
                                id="asset-category-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Kitchen equipment"
                            />
                        </Field>
                        <Field
                            label="Code on the tag"
                            htmlFor="asset-category-code"
                            className="w-32"
                            error={errors.code}
                            hint={codeLocked ? 'Already on a tag.' : undefined}
                            required
                        >
                            <Input
                                id="asset-category-code"
                                aria-required
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                className="font-mono uppercase"
                                placeholder="KIT"
                                maxLength={6}
                                disabled={codeLocked}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="New ones are" htmlFor="asset-category-tracking" error={errors.default_tracking} required>
                            <SearchableSelect
                                fit
                                id="asset-category-tracking"
                                value={data.default_tracking}
                                onChange={(v) => setData('default_tracking', v)}
                                options={trackings}
                            />
                        </Field>
                        <Field label="Useful life (months)" htmlFor="asset-category-life" className="w-40" error={errors.default_useful_life_months}>
                            <Input
                                id="asset-category-life"
                                value={data.default_useful_life_months}
                                onChange={(e) => setData('default_useful_life_months', e.target.value)}
                                className="text-right"
                                placeholder="60"
                                inputMode="numeric"
                            />
                        </Field>
                    </FormRow>

                    {/* Switched off, never deleted: things keep their category. */}
                    {editing && (
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="asset-category-active"
                                checked={data.is_active}
                                onCheckedChange={(on) => setData('is_active', on === true)}
                            />
                            <Label htmlFor="asset-category-active" className="font-normal">
                                In use
                            </Label>
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save' : 'Add category'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
