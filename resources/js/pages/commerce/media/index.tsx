import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface MediaRow {
    id: number;
    uuid: string;
    url: string;
    alt: string;
    alt_text: string | null;
    is_decorative: boolean;
    alt_decided: boolean;
    width: number | null;
    height: number | null;
    original_name: string;
    size: number;
    created_at: string | null;
    used_on: string[];
    file_missing: boolean;
}

interface StoreRow {
    id: number;
    name: string;
    key: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Online shops', href: '/commerce/stores' },
    { title: 'Media', href: '/commerce/media' },
];

function readableSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * A store's image library.
 *
 * ⛔ Alt text is asked for on the same screen the image arrives on, not left to
 * a later tidy-up that never happens. The storefront audit found six alt
 * attributes in a thousand-line template, and a page cannot be published while
 * any image on it is still undecided.
 */
export default function MediaIndex({ media, stores, filters }: { media: MediaRow[]; stores: StoreRow[]; filters: { store: number | null } }) {
    const upload = useForm<{ store_id: string; file: File | null; alt_text: string; is_decorative: boolean }>({
        store_id: filters.store ? String(filters.store) : '',
        file: null,
        alt_text: '',
        is_decorative: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('commerce.media.store'), {
            forceFormData: true,
            onSuccess: () => upload.setData((current) => ({ ...current, file: null, alt_text: '', is_decorative: false })),
        });
    };

    const undecided = media.filter((item) => !item.alt_decided).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Media" />
            <div className="flex flex-col gap-4 p-4">
                <div className="text-muted-foreground max-w-3xl text-sm">
                    <p>
                        The images your pages are built from, one library per website. Every upload is downscaled and re-encoded on the way in, so
                        nothing raw off a phone reaches a customer's data plan.
                    </p>
                </div>

                {stores.length === 0 ? (
                    <div className="rounded-md border border-dashed p-6 text-sm">
                        <p className="font-medium">There are no stores yet.</p>
                        <p className="text-muted-foreground mt-1">Images belong to a website, so create a store first.</p>
                    </div>
                ) : (
                    <>
                        <form onSubmit={submit} className="grid gap-4 rounded-md border p-4 md:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="store_id" required>
                                    Store
                                </Label>
                                <Select value={upload.data.store_id} onValueChange={(value) => upload.setData('store_id', value)}>
                                    <SelectTrigger id="store_id" aria-required>
                                        <SelectValue placeholder="Choose a store" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {stores.map((store) => (
                                            <SelectItem key={store.id} value={String(store.id)}>
                                                {store.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={upload.errors.store_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="file" required>
                                    Image
                                </Label>
                                <Input
                                    id="file"
                                    type="file"
                                    required
                                    aria-required
                                    accept="image/jpeg,image/png,image/webp"
                                    onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                                />
                                <p className="text-muted-foreground text-xs">JPG, PNG or WEBP. Up to 8 MB.</p>
                                <InputError message={upload.errors.file} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="alt_text">Alt text</Label>
                                <Input
                                    id="alt_text"
                                    placeholder="What the picture shows"
                                    disabled={upload.data.is_decorative}
                                    value={upload.data.alt_text}
                                    onChange={(e) => upload.setData('alt_text', e.target.value)}
                                />
                                <InputError message={upload.errors.alt_text} />
                            </div>

                            <div className="grid gap-2">
                                <Label>Or</Label>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="is_decorative"
                                        checked={upload.data.is_decorative}
                                        onCheckedChange={(next) => upload.setData('is_decorative', next === true)}
                                    />
                                    <Label htmlFor="is_decorative" className="text-sm font-normal">
                                        Decorative
                                    </Label>
                                </div>
                                {/* ⚠ The distinction that makes this correct rather than box-ticking. */}
                                <p className="text-muted-foreground text-xs">A flourish that carries no meaning. A screen reader will skip it.</p>
                            </div>

                            <div className="md:col-span-4">
                                <Button type="submit" disabled={upload.processing || !upload.data.file}>
                                    Add image
                                </Button>
                            </div>
                        </form>

                        <div className="flex flex-wrap items-center gap-3">
                            <Label htmlFor="filter" className="text-sm">
                                Store
                            </Label>
                            <Select
                                value={filters.store ? String(filters.store) : ''}
                                onValueChange={(value) =>
                                    router.get(route('commerce.media.index'), { store: value }, { preserveState: true, replace: true })
                                }
                            >
                                <SelectTrigger id="filter" className="w-64">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {stores.map((store) => (
                                        <SelectItem key={store.id} value={String(store.id)}>
                                            {store.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            {undecided > 0 && <Badge variant="destructive">{undecided} without alt text</Badge>}
                        </div>

                        {media.length === 0 ? (
                            <div className="text-muted-foreground rounded-md border border-dashed p-6 text-sm">No images on this store yet.</div>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {media.map((item) => (
                                    <MediaCard key={item.id} item={item} />
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function MediaCard({ item }: { item: MediaRow }) {
    const form = useForm({
        alt_text: item.alt_text ?? '',
        is_decorative: item.is_decorative,
    });
    const [error, setError] = useState<string | null>(null);

    return (
        <div className="flex flex-col gap-3 rounded-md border p-3">
            <div className="bg-muted flex h-40 items-center justify-center overflow-hidden rounded">
                {item.file_missing ? (
                    // ⚠ A row whose file has gone is a real state after a restore from a
                    // database-only backup. Saying so beats rendering a broken image icon.
                    <span className="text-muted-foreground text-xs">The file behind this row is missing</span>
                ) : (
                    <img src={item.url} alt={item.alt} className="h-full w-full object-cover" />
                )}
            </div>

            <div className="text-sm">
                <p className="truncate font-medium" title={item.original_name}>
                    {item.original_name}
                </p>
                <p className="text-muted-foreground text-xs">
                    {item.width && item.height ? `${item.width} x ${item.height}` : 'size unknown'} &middot; {readableSize(item.size)}
                    {item.created_at ? ` · ${showDate(item.created_at)}` : ''}
                </p>
                {item.used_on.length > 0 && <p className="text-muted-foreground mt-1 text-xs">Used on: {item.used_on.join(', ')}</p>}
            </div>

            <div className="grid gap-2">
                <Input
                    value={form.data.alt_text}
                    placeholder="What the picture shows"
                    disabled={form.data.is_decorative}
                    onChange={(e) => form.setData('alt_text', e.target.value)}
                />
                <div className="flex items-center gap-2">
                    <Checkbox
                        id={`dec-${item.id}`}
                        checked={form.data.is_decorative}
                        onCheckedChange={(next) => form.setData('is_decorative', next === true)}
                    />
                    <Label htmlFor={`dec-${item.id}`} className="text-sm font-normal">
                        Decorative
                    </Label>
                    {!item.alt_decided && (
                        <Badge variant="destructive" className="ml-auto">
                            Not decided
                        </Badge>
                    )}
                </div>
            </div>

            {error && <p className="text-destructive text-sm">{error}</p>}

            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={form.processing}
                    onClick={() => form.put(route('commerce.media.update', item.id), { preserveScroll: true, onSuccess: () => setError(null) })}
                >
                    Save
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        router.delete(route('commerce.media.destroy', item.id), {
                            preserveScroll: true,
                            // ⛔ The refusal names the pages still using it, so it is
                            // an instruction rather than an obstacle.
                            onError: (bag) => setError((Object.values(bag).flat() as string[])[0] ?? 'Could not delete.'),
                        })
                    }
                >
                    Delete
                </Button>
            </div>
        </div>
    );
}
