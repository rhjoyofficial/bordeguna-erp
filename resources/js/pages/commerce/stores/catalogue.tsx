import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * Which products this shop sells.
 *
 * ⛔ There is no price field on this screen, and that is the design. A website
 * is a price group (ADR-005), so what a visitor pays comes from the ordinary
 * price list read at the store's group. A price typed here would be a second
 * opinion about money, editable from a content screen and invisible to every
 * report that reads the price list.
 *
 * ⚠ A product that cannot be published says WHY, on the row, before anybody
 * presses anything. The commonest reason is that no price is in force for the
 * store's group - which is fixed in Sales, not here, so the sentence says so.
 */

interface CatalogueRow {
    id: number;
    product_id: number;
    sku: string | null;
    master_name: string | null;
    title: string;
    title_override: string | null;
    summary_override: string | null;
    slug: string;
    position: number;
    image_media_id: number | null;
    published_at: string | null;
    refusal: string | null;
}

interface Candidate {
    id: number;
    name: string;
    sku: string;
    refusal: string | null;
}

interface StoreSummary {
    id: number;
    name: string;
    key: string;
    status: string;
    price_group: string;
}

interface MediaItem {
    id: number;
    uuid: string;
    alt_text: string | null;
    is_decorative: boolean;
}

function RowEditor({ store, row, media, onDone }: { store: StoreSummary; row: CatalogueRow; media: MediaItem[]; onDone: () => void }) {
    const { data, setData, put, errors, processing } = useForm({
        slug: row.slug,
        title_override: row.title_override ?? '',
        summary_override: row.summary_override ?? '',
        image_media_id: row.image_media_id,
        position: row.position,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/commerce/stores/${store.id}/catalogue/${row.id}`, { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4">
            <div className="grid gap-4 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor={`slug-${row.id}`} required>
                        Web address
                    </Label>
                    <Input id={`slug-${row.id}`} required aria-required value={data.slug} onChange={(e) => setData('slug', e.target.value)} />
                    <p className="text-muted-foreground text-xs">/products/{data.slug || '…'}</p>
                    <InputError message={errors.slug} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`title-${row.id}`}>Title on this shop</Label>
                    <Input
                        id={`title-${row.id}`}
                        value={data.title_override}
                        onChange={(e) => setData('title_override', e.target.value)}
                        placeholder={row.master_name ?? ''}
                    />
                    {/* ⚠ Presentation, not a rename. Left empty the shop shows
                        the catalogue's own name, which is why a store that
                        overrides nothing still has a complete catalogue. */}
                    <p className="text-muted-foreground text-xs">Leave empty to use the catalogue name.</p>
                    <InputError message={errors.title_override} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`summary-${row.id}`}>Short description</Label>
                <Input id={`summary-${row.id}`} value={data.summary_override} onChange={(e) => setData('summary_override', e.target.value)} />
                <InputError message={errors.summary_override} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`image-${row.id}`}>Picture</Label>
                <select
                    id={`image-${row.id}`}
                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    value={data.image_media_id ?? ''}
                    onChange={(e) => setData('image_media_id', e.target.value === '' ? null : Number(e.target.value))}
                >
                    <option value="">No picture</option>
                    {media.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.alt_text || (item.is_decorative ? 'Decorative image' : item.uuid.slice(0, 8))}
                        </option>
                    ))}
                </select>
                {/* ⛔ Chosen from this shop's own library, never typed as a URL. */}
                <p className="text-muted-foreground text-xs">From this shop's media library only.</p>
                <InputError message={errors.image_media_id} />
            </div>

            <div className="flex items-center gap-2">
                <Button disabled={processing}>Save</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export default function StoreCatalogue({
    store,
    published,
    candidates,
    filters,
    media,
}: {
    store: StoreSummary;
    published: CatalogueRow[];
    candidates: Candidate[];
    filters: { search: string };
    media: MediaItem[];
}) {
    const [editing, setEditing] = useState<number | null>(null);
    const [search, setSearch] = useState(filters.search);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Online shops', href: '/commerce/stores' },
        { title: store.name, href: `/commerce/stores/${store.id}` },
        { title: 'Catalogue', href: `/commerce/stores/${store.id}/catalogue` },
    ];

    const find: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(`/commerce/stores/${store.id}/catalogue`, { search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${store.name} catalogue`} />

            <div className="grid gap-6 p-4">
                <HeadingSmall
                    title={`${store.name} catalogue`}
                    description={`Visitors to this shop are priced at ${store.price_group}. Prices come from the selling price list, not from this screen.`}
                />

                <div className="grid gap-3">
                    <h3 className="text-sm font-medium">On sale ({published.length})</h3>

                    {published.length === 0 && <p className="text-muted-foreground text-sm">Nothing is published to this shop yet.</p>}

                    {published.map((row) =>
                        editing === row.id ? (
                            <RowEditor key={row.id} store={store} row={row} media={media} onDone={() => setEditing(null)} />
                        ) : (
                            <div key={row.id} className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4">
                                <div className="grid gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{row.title}</span>
                                        {row.sku && <Badge variant="outline">{row.sku}</Badge>}
                                        {row.published_at === null && <Badge variant="secondary">Off sale</Badge>}
                                    </div>
                                    <p className="text-muted-foreground text-sm">/products/{row.slug}</p>
                                    {/* ⚠ Recomputed on every view. A price can expire after a
                                        product was published, and a check made only at publish
                                        time would keep saying it was fine. */}
                                    {row.refusal && <p className="text-sm text-amber-600 dark:text-amber-500">{row.refusal}</p>}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button variant="outline" size="sm" onClick={() => setEditing(row.id)}>
                                        Edit
                                    </Button>
                                    {row.published_at === null ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.post(`/commerce/stores/${store.id}/catalogue/${row.id}/publish`)}
                                        >
                                            Put back on sale
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.post(`/commerce/stores/${store.id}/catalogue/${row.id}/unpublish`)}
                                        >
                                            Take off sale
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.delete(`/commerce/stores/${store.id}/catalogue/${row.id}`)}
                                    >
                                        Remove
                                    </Button>
                                </div>
                            </div>
                        ),
                    )}
                </div>

                <div className="grid gap-3">
                    <h3 className="text-sm font-medium">Add a product</h3>

                    <form onSubmit={find} className="flex flex-wrap items-end gap-2">
                        <div className="grid w-72 gap-2">
                            <Label htmlFor="catalogue-search">Search the catalogue</Label>
                            <Input id="catalogue-search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name or SKU" />
                        </div>
                        <Button type="submit" variant="outline">
                            Find
                        </Button>
                    </form>

                    {candidates.map((candidate) => (
                        <div key={candidate.id} className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4">
                            <div className="grid gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">{candidate.name}</span>
                                    <Badge variant="outline">{candidate.sku}</Badge>
                                </div>
                                {/* ⭐ The reason is shown BEFORE anybody presses anything, so it
                                    is read on the screen rather than met as a validation error. */}
                                {candidate.refusal && <p className="text-sm text-amber-600 dark:text-amber-500">{candidate.refusal}</p>}
                            </div>
                            <Button
                                size="sm"
                                disabled={candidate.refusal !== null}
                                onClick={() => router.post(`/commerce/stores/${store.id}/catalogue`, { product_id: candidate.id })}
                            >
                                Publish
                            </Button>
                        </div>
                    ))}
                </div>

                <div>
                    <Button variant="ghost" asChild>
                        <Link href={`/commerce/stores/${store.id}`}>Back to the shop</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
