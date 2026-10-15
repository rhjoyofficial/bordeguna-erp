import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface ProductRow {
    id: number;
    sku: string;
    name: string;
    barcode: string | null;
}

const breadcrumbs = inventoryCrumbs('Catalogue', 'Products', { title: 'Barcode labels', href: '/inventory/labels' });

export default function LabelsIndex({
    products,
    with_barcode,
    without_barcode,
    sizes,
}: {
    products: ProductRow[];
    with_barcode: number;
    without_barcode: number;
    sizes: { value: string; label: string }[];
}) {
    const [size, setSize] = useState(sizes[0]?.value ?? 'small');
    const [search, setSearch] = useState('');
    const [qty, setQty] = useState<Record<number, string>>({});

    const shown = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (!needle) {
            return products;
        }

        return products.filter(
            (p) => p.name.toLowerCase().includes(needle) || p.sku.toLowerCase().includes(needle) || (p.barcode ?? '').includes(needle),
        );
    }, [products, search]);

    const total = Object.values(qty).reduce((sum, n) => sum + (Number(n) || 0), 0);

    const print = () => {
        const params = new URLSearchParams({ size });

        Object.entries(qty).forEach(([id, n]) => {
            if (Number(n) > 0) {
                params.set(`qty[${id}]`, String(Number(n)));
            }
        });

        router.get(`/inventory/labels/print?${params.toString()}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Barcode labels" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-medium">Barcode labels</h1>
                </div>

                {without_barcode > 0 && (
                    <div className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
                        <p className="font-medium">
                            {without_barcode} of {with_barcode + without_barcode} active products have no barcode yet.
                        </p>
                        <p className="text-muted-foreground">
                            They are listed below and cannot be printed until one is entered on the product. Nothing is invented for them.
                        </p>
                    </div>
                )}

                <div className="flex flex-wrap items-end gap-4 rounded-lg border p-4">
                    <div className="grid gap-2">
                        <Label htmlFor="size">Label size</Label>
                        <SearchSelect id="size" value={size} onChange={(e) => setSize(e.target.value)}>
                            {sizes.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid flex-1 gap-2">
                        <Label htmlFor="search">Find a product</Label>
                        <Input
                            id="search"
                            className="w-80"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Name, SKU or barcode"
                        />
                    </div>
                    <Button onClick={print} disabled={total === 0}>
                        Print {total > 0 ? `${total} label${total === 1 ? '' : 's'}` : 'labels'}
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3">SKU</th>
                                <th className="p-3">Barcode</th>
                                <th className="w-32 p-3">Labels</th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.length === 0 && (
                                <tr>
                                    <td className="text-muted-foreground p-3" colSpan={4}>
                                        Nothing matches that.
                                    </td>
                                </tr>
                            )}
                            {shown.map((p) => (
                                <tr key={p.id} className="border-t">
                                    <td className="p-3">{p.name}</td>
                                    <td className="p-3 font-mono text-xs">{p.sku}</td>
                                    <td className="p-3 font-mono text-xs">
                                        {p.barcode ?? <span className="text-muted-foreground font-sans">No barcode yet</span>}
                                    </td>
                                    <td className="p-3">
                                        <Input
                                            inputMode="numeric"
                                            aria-label={`Labels for ${p.name}`}
                                            value={qty[p.id] ?? ''}
                                            disabled={!p.barcode}
                                            onChange={(e) => setQty({ ...qty, [p.id]: e.target.value })}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
