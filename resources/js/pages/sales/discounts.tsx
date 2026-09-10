import { Field, FormRow } from '@/components/form/field';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

type Discount = {
    id: number;
    product: string | null;
    sku: string | null;
    label: string | null;
    group: string;
    min_quantity: number;
    unit_price: number;
    effective_from: string | null;
    effective_to: string | null;
    is_winning: boolean;
};

type ProductRow = { id: number; sku: string; name: string; list_price: number | null };

type PageProps = {
    on: string;
    discounts: Discount[];
    products: ProductRow[];
    groups: { id: number; name: string; code: string }[];
};

export default function Discounts() {
    const { on, discounts, products, groups } = usePage<PageProps>().props;

    const form = useForm({
        product_id: '',
        price_group_id: '',
        unit_price: '',
        min_quantity: '1',
        effective_from: on,
        effective_to: '',
        discount_label: '',
    });

    const chosen = useMemo(() => products.find((p) => String(p.id) === form.data.product_id) ?? null, [products, form.data.product_id]);

    const saving = useMemo(() => {
        if (!chosen?.list_price || !form.data.unit_price) return null;
        const off = chosen.list_price - Number(form.data.unit_price);
        if (!Number.isFinite(off) || off <= 0) return null;
        return { amount: off, percent: (off / chosen.list_price) * 100 };
    }, [chosen, form.data.unit_price]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/sales/discounts', {
            preserveScroll: true,
            onSuccess: () => form.reset('unit_price', 'discount_label', 'effective_to'),
        });
    };

    return (
        <AppLayout>
            <Head title="Discounts" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Discounts" description="Sell something below its list price for a period, or from a quantity up." />

                <div className="border-border bg-muted/40 text-muted-foreground rounded-md border p-4 text-sm">
                    A discount is an ordinary price rule with a lower number on it, so nothing else in the system has to learn a new idea. To sell 500
                    g of a 125 g pack, set a discount from <strong>4 packs</strong> up rather than creating a 500 g product.
                </div>

                {/* ⚠ "Each input field should be as wide as it needs" (Joy, 30 Aug
                    2026, on this page). The product picker takes the room, a price
                    is a price box, a quantity is a quantity box, dates size
                    themselves. */}
                <form onSubmit={submit} className="border-border flex flex-col gap-4 rounded-lg border p-4">
                    <FormRow>
                        <Field label="Product" htmlFor="product" size="grow" error={form.errors.product_id}>
                            <SearchSelect
                                id="product"
                                fit={false}
                                value={form.data.product_id}
                                onChange={(e) => form.setData('product_id', e.target.value)}
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
                            htmlFor="unit_price"
                            required
                            size="sm"
                            error={form.errors.unit_price}
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
                                id="unit_price"
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.data.unit_price}
                                onChange={(e) => form.setData('unit_price', e.target.value)}
                            />
                        </Field>
                        <Field label="From qty" htmlFor="min_quantity" size="xs" hint="1 means every sale. 4 means four or more.">
                            <Input
                                id="min_quantity"
                                type="number"
                                step="1"
                                min="1"
                                value={form.data.min_quantity}
                                onChange={(e) => form.setData('min_quantity', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Customer group" htmlFor="group">
                            <SearchSelect
                                id="group"
                                value={form.data.price_group_id}
                                onChange={(e) => form.setData('price_group_id', e.target.value)}
                            >
                                <option value="">Everyone</option>
                                {groups.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {g.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Starts" htmlFor="from" error={form.errors.effective_from}>
                            <Input
                                id="from"
                                type="date"
                                value={form.data.effective_from}
                                onChange={(e) => form.setData('effective_from', e.target.value)}
                            />
                        </Field>
                        <Field label="Ends (optional)" htmlFor="to" hint="Leave empty to run until you end it.">
                            <Input
                                id="to"
                                type="date"
                                value={form.data.effective_to}
                                onChange={(e) => form.setData('effective_to', e.target.value)}
                            />
                        </Field>
                        <Field label="What to call it (optional)" htmlFor="label" size="grow">
                            <Input
                                id="label"
                                placeholder="Eid offer"
                                value={form.data.discount_label}
                                onChange={(e) => form.setData('discount_label', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <div>
                        <Button type="submit" disabled={form.processing || !form.data.product_id || !form.data.unit_price}>
                            Save discount
                        </Button>
                    </div>
                </form>

                <div>
                    <h3 className="mb-3 text-sm font-medium">Discounts set</h3>
                    <div className="border-border overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Product</th>
                                    <th className="p-3 font-medium">Name</th>
                                    <th className="p-3 font-medium">Who</th>
                                    <th className="p-3 text-right font-medium">From qty</th>
                                    <th className="p-3 text-right font-medium">Price</th>
                                    <th className="p-3 font-medium">Period</th>
                                    <th className="p-3 font-medium">Applying today</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {discounts.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="text-muted-foreground p-6 text-center">
                                            No discounts set. Everything sells at its list price.
                                        </td>
                                    </tr>
                                )}
                                {discounts.map((d) => (
                                    <tr key={d.id} className="border-border border-t">
                                        <td className="p-3">
                                            <div className="font-medium">{d.product}</div>
                                            <div className="text-muted-foreground text-xs">{d.sku}</div>
                                        </td>
                                        <td className="p-3">{d.label ?? <span className="text-muted-foreground">-</span>}</td>
                                        <td className="p-3">{d.group}</td>
                                        <td className="p-3 text-right">{d.min_quantity}</td>
                                        <td className="p-3 text-right font-medium">{d.unit_price.toFixed(2)}</td>
                                        <td className="p-3 whitespace-nowrap">
                                            {d.effective_from}
                                            {d.effective_to ? ` to ${d.effective_to}` : ' onwards'}
                                        </td>
                                        <td className="p-3">
                                            {d.is_winning ? (
                                                <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">
                                                    Yes
                                                </span>
                                            ) : (
                                                <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs">No</span>
                                            )}
                                        </td>
                                        <td className="p-3 text-right">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => router.post(`/sales/discounts/${d.id}/close`, {}, { preserveScroll: true })}
                                            >
                                                End it
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <p className="text-muted-foreground mt-3 text-xs">
                        "Applying today" is answered by the pricing engine itself, not guessed here. A discount can be saved and still lose to another
                        rule, which is why the column exists.
                    </p>
                </div>
            </div>
        </AppLayout>
    );
}
