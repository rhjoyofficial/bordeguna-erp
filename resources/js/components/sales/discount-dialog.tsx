import { Field, FormRow } from '@/components/form/field';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo } from 'react';

export type DiscountProduct = { id: number; sku: string; name: string; list_price: number | null };

function blank(on: string, groups: { id: number; code: string }[]) {
    return {
        product_id: '',
        // ⚠ A group is required (21 Sep 2026): "Everyone" wrote a price that lost
        // to every Retail price and so never applied. Retail is the consumer.
        price_group_id: String(groups.find((g) => g.code === 'RETAIL')?.id ?? ''),
        unit_price: '',
        min_quantity: '1',
        effective_from: on,
        effective_to: '',
        discount_label: '',
    };
}

/**
 * Set a discount in a popup (Joy, 14 Oct 2026: "click add button pop up will
 * add"). The form that sat open above the list is gone; the list is the page
 * and the form appears only when somebody asks for it. Ending a discount stays
 * on its row.
 *
 * Same fields, same route, same server rules as before: the dialog only
 * changes where the form is drawn. ⚠ The server still refuses a "discount"
 * that is not below the list price; that sentence lands under the price box
 * and the popup stays open. A discount that saves but loses to another rule
 * closes the popup and the warning shows over the list.
 */
export function DiscountDialog({
    open,
    onOpenChange,
    on,
    products,
    groups,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The day the list is read for, and the default start of a new discount. */
    on: string;
    products: DiscountProduct[];
    groups: { id: number; name: string; code: string }[];
}) {
    const { data, setData, post, errors, processing, reset, clearErrors, setDefaults } = useForm(blank(on, groups));

    // Fresh fields every time it opens: Retail, from the day shown, nothing else chosen.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = blank(on, groups);
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const chosen = useMemo(() => products.find((p) => String(p.id) === data.product_id) ?? null, [products, data.product_id]);

    const saving = useMemo(() => {
        if (!chosen?.list_price || !data.unit_price) return null;
        const off = chosen.list_price - Number(data.unit_price);
        if (!Number.isFinite(off) || off <= 0) return null;
        return { amount: off, percent: (off / chosen.list_price) * 100 };
    }, [chosen, data.unit_price]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/sales/discounts', { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                {/* ⚠ "Each input field should be as wide as it needs" (Joy, 30 Aug
                    2026, on this form). The product picker takes the room, a price
                    is a price box, a quantity is a quantity box, dates size
                    themselves. */}
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Set a discount</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Product" htmlFor="discount-product" size="grow" required error={errors.product_id}>
                            <SearchSelect
                                id="discount-product"
                                fit={false}
                                value={data.product_id}
                                onChange={(e) => setData('product_id', e.target.value)}
                            >
                                <option value="">Choose a product</option>
                                {products.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                        {p.list_price !== null ? ` (${p.list_price.toFixed(2)})` : ' (no price yet)'}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field
                            label="Discounted price"
                            htmlFor="discount-unit_price"
                            required
                            size="sm"
                            error={errors.unit_price}
                            hint={
                                chosen?.list_price != null && (
                                    <>
                                        List price is {chosen.list_price.toFixed(2)}
                                        {saving && (
                                            <>
                                                {' '}
                                                &middot; saves {saving.amount.toFixed(2)} ({saving.percent.toFixed(1)}%)
                                            </>
                                        )}
                                    </>
                                )
                            }
                        >
                            <Input
                                id="discount-unit_price"
                                type="number"
                                aria-required
                                step="0.01"
                                min="0"
                                value={data.unit_price}
                                onChange={(e) => setData('unit_price', e.target.value)}
                            />
                        </Field>
                        <Field label="From qty" htmlFor="discount-min_quantity" size="xs" error={errors.min_quantity}>
                            <Input
                                id="discount-min_quantity"
                                placeholder="1"
                                type="number"
                                step="1"
                                min="1"
                                value={data.min_quantity}
                                onChange={(e) => setData('min_quantity', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Customer group" htmlFor="discount-group" required error={errors.price_group_id}>
                            <SearchSelect id="discount-group" value={data.price_group_id} onChange={(e) => setData('price_group_id', e.target.value)}>
                                {groups.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {g.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Starts" htmlFor="discount-from" required error={errors.effective_from}>
                            <Input
                                id="discount-from"
                                type="date"
                                aria-required
                                value={data.effective_from}
                                onChange={(e) => setData('effective_from', e.target.value)}
                            />
                        </Field>
                        <Field label="Ends (optional)" htmlFor="discount-to" error={errors.effective_to}>
                            <Input id="discount-to" type="date" value={data.effective_to} onChange={(e) => setData('effective_to', e.target.value)} />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="What to call it (optional)" htmlFor="discount-label" size="grow" error={errors.discount_label}>
                            <Input
                                id="discount-label"
                                placeholder="Eid offer"
                                value={data.discount_label}
                                onChange={(e) => setData('discount_label', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing || !data.product_id || !data.unit_price}>
                            Save discount
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
