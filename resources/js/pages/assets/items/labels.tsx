import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface LabelItem {
    id: number;
    tag: string;
    name: string;
    category_id: number;
    category: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Things we keep', href: '/assets/items' },
    { title: 'Asset tags', href: '/assets/items/labels' },
];

/**
 * Choose which things get a QR tag sticker, then print (slice A1).
 *
 * ⚠ The sheet opens in a new tab: the browser's print dialog there IS the
 * download, as for product barcode labels.
 */
export default function AssetLabels({
    items,
    categories,
    sizes,
    preselected,
}: {
    items: LabelItem[];
    categories: { id: number; name: string }[];
    sizes: { value: string; label: string }[];
    preselected: number[];
}) {
    const [chosen, setChosen] = useState<Set<number>>(new Set(preselected));
    const [category, setCategory] = useState('');
    const [needle, setNeedle] = useState('');
    const [size, setSize] = useState(sizes[1]?.value ?? sizes[0]?.value ?? 'medium');
    const [copies, setCopies] = useState('1');

    const shown = useMemo(
        () =>
            items.filter(
                (item) =>
                    (category === '' || String(item.category_id) === category) &&
                    (needle === '' || `${item.tag} ${item.name}`.toLowerCase().includes(needle.toLowerCase())),
            ),
        [items, category, needle],
    );

    const toggle = (id: number, on: boolean) =>
        setChosen((current) => {
            const next = new Set(current);
            if (on) {
                next.add(id);
            } else {
                next.delete(id);
            }
            return next;
        });

    const printUrl = () => {
        const query = new URLSearchParams();
        [...chosen].forEach((id) => query.append('ids[]', String(id)));
        query.set('size', size);
        query.set('copies', copies || '1');

        return `/assets/items/labels/print?${query.toString()}`;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Asset tags" />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Asset tags</h1>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <Label required>Sticker size</Label>
                        <SearchableSelect fit value={size} onChange={setSize} options={sizes} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="copies">Copies of each</Label>
                        <Input
                            id="copies"
                            type="number"
                            min={1}
                            max={5}
                            value={copies}
                            onChange={(e) => setCopies(e.target.value)}
                            className="w-20"
                        />
                    </div>
                    <Button disabled={chosen.size === 0} asChild={chosen.size > 0}>
                        {chosen.size > 0 ? (
                            <a href={printUrl()} target="_blank" rel="noreferrer">
                                Print {chosen.size} tag{chosen.size === 1 ? '' : 's'}
                            </a>
                        ) : (
                            <span>Tick something to print</span>
                        )}
                    </Button>
                </div>

                <div className="flex flex-wrap items-end gap-2">
                    <Input value={needle} onChange={(e) => setNeedle(e.target.value)} placeholder="Tag or name" className="w-full sm:w-64" />
                    <SearchableSelect
                        fit
                        value={category}
                        onChange={setCategory}
                        options={[{ value: '', label: 'Every category' }, ...categories.map((c) => ({ value: String(c.id), label: c.name }))]}
                    />
                    <Button variant="outline" size="sm" onClick={() => setChosen(new Set([...chosen, ...shown.map((item) => item.id)]))}>
                        Tick all shown
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setChosen(new Set())}>
                        Clear
                    </Button>
                </div>

                {shown.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">Nothing to tag here.</p>
                ) : (
                    <ul className="grid gap-1 rounded-lg border p-2 text-sm">
                        {shown.map((item) => (
                            <li key={item.id} className="flex items-center gap-3 rounded-md px-2 py-1.5">
                                <Checkbox
                                    id={`item-${item.id}`}
                                    checked={chosen.has(item.id)}
                                    onCheckedChange={(on) => toggle(item.id, on === true)}
                                />
                                <label htmlFor={`item-${item.id}`} className="flex min-w-0 flex-1 flex-wrap gap-x-3">
                                    <span className="font-mono text-xs">{item.tag}</span>
                                    <span className="break-words">{item.name}</span>
                                    <span className="text-muted-foreground">{item.category}</span>
                                </label>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
