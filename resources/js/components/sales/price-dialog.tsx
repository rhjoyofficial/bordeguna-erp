import { Field, FormRow } from '@/components/form/field';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { dhakaToday } from '@/lib/dates';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

function blank(priceGroups: { id: number; code: string }[]) {
    return {
        product_id: '',
        unit_price: '',
        effective_from: dhakaToday(),
        effective_to: '',
        customer_id: '',
        // ⚠ Retail by default (21 Sep 2026): a price for nobody in particular is
        // no longer accepted, and Retail is who "everyone" always meant.
        price_group_id: String(priceGroups.find((g) => g.code === 'RETAIL')?.id ?? ''),
        min_quantity: '0',
        note: '',
    };
}

/**
 * Set a selling price in a popup (Joy, 14 Oct 2026: "click add button pop up
 * will add"). The eight-box form that sat open above the list, drawn even for
 * people who could only read prices, is gone; the list is the page and the
 * form appears only when somebody who sets prices asks for it.
 *
 * Same fields, same route, same server rules as before: the dialog only
 * changes where the form is drawn.
 *
 * ⚠⚠ A price is never edited, only superseded: set a new one from a date, and
 * the old row stays so an old invoice can still be explained. That is why this
 * popup only ever adds.
 */
export function PriceDialog({
    open,
    onOpenChange,
    products,
    customers,
    priceGroups,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    products: { id: number; name: string; sku: string }[];
    customers: { id: number; name: string }[];
    priceGroups: { id: number; code: string; name: string }[];
}) {
    const { data, setData, post, errors, processing, reset, clearErrors, setDefaults } = useForm(blank(priceGroups));

    // Fresh fields every time it opens: today, Retail, nothing else chosen.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = blank(priceGroups);
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.prices.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Set a selling price</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Product" htmlFor="price-product_id" error={errors.product_id} required>
                            <SearchSelect id="price-product_id" value={data.product_id} onChange={(e) => setData('product_id', e.target.value)}>
                                <option value="">Choose a product</option>
                                {products.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} ({p.sku})
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Selling price" htmlFor="price-unit_price" size="sm" error={errors.unit_price} required>
                            <Input
                                id="price-unit_price"
                                inputMode="decimal"
                                aria-required
                                value={data.unit_price}
                                onChange={(e) => setData('unit_price', e.target.value)}
                            />
                        </Field>
                        <Field label="From quantity" htmlFor="price-min_quantity" size="xs" error={errors.min_quantity}>
                            <Input
                                id="price-min_quantity"
                                inputMode="decimal"
                                value={data.min_quantity}
                                onChange={(e) => setData('min_quantity', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="In force from" htmlFor="price-effective_from" error={errors.effective_from} required>
                            <Input
                                id="price-effective_from"
                                type="date"
                                aria-required
                                value={data.effective_from}
                                onChange={(e) => setData('effective_from', e.target.value)}
                            />
                        </Field>
                        <Field label="Until (optional)" htmlFor="price-effective_to" error={errors.effective_to}>
                            <Input
                                id="price-effective_to"
                                type="date"
                                value={data.effective_to}
                                onChange={(e) => setData('effective_to', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="For one customer" htmlFor="price-customer_id" error={errors.customer_id} required={!data.price_group_id}>
                            <SearchSelect id="price-customer_id" value={data.customer_id} onChange={(e) => setData('customer_id', e.target.value)}>
                                <option value="">No particular customer</option>
                                {customers.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        {/* ⚠ Rows, never typed: the free-text version of this field
                            is how a distributor gets silently charged retail. */}
                        <Field label="Or a price group" htmlFor="price-price_group_id" error={errors.price_group_id} required={!data.customer_id}>
                            <SearchSelect
                                id="price-price_group_id"
                                value={data.price_group_id}
                                onChange={(e) => setData('price_group_id', e.target.value)}
                            >
                                <option value="">Choose a group</option>
                                {priceGroups.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {g.code}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>Set price</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
