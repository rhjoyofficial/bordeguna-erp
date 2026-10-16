import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The group product book (Module 6, Slice 2): one product, several company
 * rows, with stock and price side by side for the companies you belong to.
 */
interface Member {
    business_id: number;
    business: string;
    product_id: number;
    name: string;
    sku: string | null;
    stock?: number;
    price?: number | null;
}

interface LinkRow {
    id: number;
    name: string;
    members: Member[];
    total_stock: number;
}

interface Suggestion {
    sku: string;
    members: Member[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'All businesses', href: '/group/cockpit' },
    { title: 'Products', href: '/group/products' },
];

const qty = (value: number) => value.toLocaleString('en-BD', { maximumFractionDigits: 4 });
const money = (value: number | null | undefined) =>
    value === null || value === undefined ? '-' : value.toLocaleString('en-BD', { minimumFractionDigits: 2 });

function SuggestionCard({ suggestion }: { suggestion: Suggestion }) {
    const [name, setName] = useState(suggestion.members[0]?.name ?? '');

    return (
        <div className="border-border bg-card space-y-2 rounded-lg border p-3">
            <p className="text-sm">
                SKU <span className="font-medium">{suggestion.sku}</span>
                <span className="text-muted-foreground"> lives in {suggestion.members.length} companies:</span>
            </p>
            <ul className="text-muted-foreground space-y-0.5 text-sm">
                {suggestion.members.map((member) => (
                    <li key={`${member.business_id}-${member.product_id}`}>
                        {member.business}: {member.name}
                    </li>
                ))}
            </ul>
            <div className="flex gap-2">
                <Input className="h-8 flex-1" value={name} onChange={(e) => setName(e.target.value)} placeholder="Group name for this product" />
                <Button
                    size="sm"
                    disabled={name.trim() === ''}
                    onClick={() =>
                        router.post(
                            '/group/products/link',
                            {
                                name: name.trim(),
                                members: suggestion.members.map((member) => ({
                                    business_id: member.business_id,
                                    product_id: member.product_id,
                                })),
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    Link as one product
                </Button>
            </div>
        </div>
    );
}

export default function GroupProducts({ links, suggestions }: { links: LinkRow[]; suggestions: Suggestion[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Products, all businesses" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Products</h1>
                </div>

                {suggestions.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="font-semibold">Same SKU, different companies</h2>
                        <div className="grid gap-3 md:grid-cols-2">
                            {suggestions.map((suggestion) => (
                                <SuggestionCard key={suggestion.sku} suggestion={suggestion} />
                            ))}
                        </div>
                    </div>
                )}

                <div className="space-y-2">
                    <h2 className="font-semibold">Linked products</h2>
                    {links.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing linked yet.</p>
                    ) : (
                        <div className="grid gap-3 lg:grid-cols-2">
                            {links.map((link) => (
                                <div key={link.id} className="border-border bg-card space-y-2 rounded-lg border p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="font-medium">{link.name}</p>
                                        <div className="text-right">
                                            <p className="text-muted-foreground text-xs">Stock across the group</p>
                                            <p className="font-semibold tabular-nums">{qty(link.total_stock)}</p>
                                        </div>
                                    </div>
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="text-muted-foreground text-left text-xs">
                                                <th className="py-1 font-medium">Company</th>
                                                <th className="py-1 font-medium">Product</th>
                                                <th className="py-1 text-right font-medium">Stock</th>
                                                <th className="py-1 text-right font-medium">List price</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {link.members.map((member) => (
                                                <tr key={`${member.business_id}-${member.product_id}`} className="border-t">
                                                    <td className="py-1.5 pr-2">{member.business}</td>
                                                    <td className="text-muted-foreground py-1.5 pr-2">
                                                        {member.name} ({member.sku})
                                                    </td>
                                                    <td className="py-1.5 text-right tabular-nums">{qty(member.stock ?? 0)}</td>
                                                    <td className="py-1.5 text-right tabular-nums">{money(member.price)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-destructive"
                                        onClick={() => router.delete(`/group/products/${link.id}`, { preserveScroll: true })}
                                    >
                                        Unlink
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
