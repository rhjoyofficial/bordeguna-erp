import { Field, FormRow } from '@/components/form/field';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

const EMPTY = {
    name: '',
    buy_product_id: '',
    buy_quantity: '',
    get_product_id: '',
    get_quantity: '1',
    starts_on: '',
    ends_on: '',
};

/**
 * Start a Buy X Get Y free deal in a popup (Joy, 14 Oct 2026: "click add
 * button pop up will add"). The form that sat open above the list is gone; the
 * list is the page and the form appears only when somebody asks for it.
 * Pausing and resuming a deal stays on its row.
 *
 * Same fields, same route, same server rules as before: the dialog only
 * changes where the form is drawn. Whether a cart earned the deal is still
 * decided server-side at the counter, never claimed by a screen.
 */
export function PromotionDialog({
    open,
    onOpenChange,
    products,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    products: { id: number; name: string; sku: string | null }[];
}) {
    const { data, setData, post, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens.
    useEffect(() => {
        if (!open) {
            return;
        }

        setDefaults(EMPTY);
        setData(EMPTY);
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
        post('/sales/promotions', { preserveScroll: true, onSuccess: close });
    };

    const options = products.map((product) => ({ value: String(product.id), label: product.name }));

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Start a promotion: Buy X Get Y free</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name of the deal" htmlFor="promotion-name" size="grow" error={errors.name} required>
                            <Input
                                id="promotion-name"
                                aria-required
                                placeholder="e.g. Eid honey deal"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Customer buys" htmlFor="promotion-buy_product_id" size="lg" error={errors.buy_product_id} required>
                            <SearchableSelect
                                id="promotion-buy_product_id"
                                fit={false}
                                required
                                value={data.buy_product_id}
                                onChange={(value) => setData('buy_product_id', value)}
                                options={options}
                                placeholder="Product"
                                searchPlaceholder="Search products…"
                            />
                        </Field>
                        <Field label="This many" htmlFor="promotion-buy_quantity" size="xs" error={errors.buy_quantity} required>
                            <Input
                                id="promotion-buy_quantity"
                                type="number"
                                step="0.0001"
                                min="0"
                                aria-required
                                placeholder="e.g. 3"
                                value={data.buy_quantity}
                                onChange={(e) => setData('buy_quantity', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Gets free" htmlFor="promotion-get_product_id" size="lg" error={errors.get_product_id} required>
                            <SearchableSelect
                                id="promotion-get_product_id"
                                fit={false}
                                required
                                value={data.get_product_id}
                                onChange={(value) => setData('get_product_id', value)}
                                options={options}
                                placeholder="Product"
                                searchPlaceholder="Search products…"
                            />
                        </Field>
                        <Field label="Free quantity" htmlFor="promotion-get_quantity" size="xs" error={errors.get_quantity} required>
                            <Input
                                id="promotion-get_quantity"
                                type="number"
                                step="0.0001"
                                min="0"
                                aria-required
                                value={data.get_quantity}
                                onChange={(e) => setData('get_quantity', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="From" htmlFor="promotion-starts_on" error={errors.starts_on} required>
                            <Input
                                id="promotion-starts_on"
                                type="date"
                                aria-required
                                value={data.starts_on}
                                onChange={(e) => setData('starts_on', e.target.value)}
                            />
                        </Field>
                        <Field label="To (last day)" htmlFor="promotion-ends_on" error={errors.ends_on} required>
                            <Input
                                id="promotion-ends_on"
                                type="date"
                                aria-required
                                value={data.ends_on}
                                onChange={(e) => setData('ends_on', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Start the promotion
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
