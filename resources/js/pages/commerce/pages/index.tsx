import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface PageRow {
    id: number;
    store_id: number;
    store_name: string | null;
    type: string;
    type_label: string;
    slug: string;
    title: string;
    path: string;
    published_at: string | null;
    is_published: boolean;
    is_scheduled: boolean;
    section_count: number;
}

interface StoreRow {
    id: number;
    name: string;
    key: string;
    status: string;
    theme_key: string | null;
}

interface PageTypeOption {
    value: string;
    label: string;
    purpose: string;
    singleton: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Websites', href: '/commerce/stores' },
    { title: 'Pages', href: '/commerce/pages' },
];

/**
 * Every page on every website this business runs.
 *
 * ⚠ Reachable from the sidebar in its own right rather than only from a store,
 * because the question an author actually has is "where is that article", not
 * "which store was it on". Same reachability rule the Sales module had to learn
 * the hard way after shipping with no link to it anywhere.
 */
export default function PagesIndex({
    pages,
    stores,
    pageTypes,
    filters,
}: {
    pages: PageRow[];
    stores: StoreRow[];
    pageTypes: PageTypeOption[];
    filters: { store: number | null };
}) {
    const { data, setData, post, errors, processing, reset } = useForm({
        store_id: stores.length === 1 ? String(stores[0].id) : '',
        type: 'custom',
        title: '',
        slug: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('commerce.pages.store'), { onSuccess: () => reset() });
    };

    const chosenType = pageTypes.find((type) => type.value === data.type);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pages" />
            <div className="flex flex-col gap-6 p-4">
                <div className="text-muted-foreground max-w-3xl text-sm">
                    <p>
                        A page is an address and an ordered list of sections. It holds no layout and no colours: how it looks is the store's theme, so
                        the same page can be redesigned without being rewritten.
                    </p>
                </div>

                {stores.length === 0 ? (
                    <div className="rounded-md border border-dashed p-6 text-sm">
                        <p className="font-medium">There are no stores yet.</p>
                        <p className="text-muted-foreground mt-1">
                            A page belongs to a website.{' '}
                            <Link href={route('commerce.stores.index')} className="underline">
                                Create a store first
                            </Link>
                            .
                        </p>
                    </div>
                ) : (
                    <>
                        <form onSubmit={submit} className="grid gap-4 rounded-md border p-4 md:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="store_id" required>
                                    Store
                                </Label>
                                <Select value={data.store_id} onValueChange={(value) => setData('store_id', value)}>
                                    <SelectTrigger id="store_id">
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
                                <InputError message={errors.store_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="type">Type</Label>
                                <Select value={data.type} onValueChange={(value) => setData('type', value)}>
                                    <SelectTrigger id="type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {pageTypes.map((type) => (
                                            <SelectItem key={type.value} value={type.value}>
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {chosenType && <p className="text-muted-foreground text-xs">{chosenType.purpose}</p>}
                                <InputError message={errors.type} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="title" required>
                                    Title
                                </Label>
                                <Input id="title" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                                <InputError message={errors.title} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="slug">Address</Label>
                                <Input
                                    id="slug"
                                    placeholder="royal-beef-pickle"
                                    value={data.slug}
                                    onChange={(e) => setData('slug', e.target.value)}
                                />
                                {/* ⛔ Said where somebody is typing it, not in a document nobody opens. */}
                                <p className="text-muted-foreground text-xs">Cannot be changed once the page has been published.</p>
                                <InputError message={errors.slug} />
                            </div>

                            <div className="md:col-span-4">
                                <Button type="submit" disabled={processing}>
                                    Create page
                                </Button>
                            </div>
                        </form>

                        <div className="flex items-center gap-3">
                            <Label htmlFor="filter" className="text-sm">
                                Show
                            </Label>
                            <Select
                                value={filters.store ? String(filters.store) : 'all'}
                                onValueChange={(value) =>
                                    router.get(route('commerce.pages.index'), value === 'all' ? {} : { store: value }, {
                                        preserveState: true,
                                        replace: true,
                                    })
                                }
                            >
                                <SelectTrigger id="filter" className="w-64">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Every store</SelectItem>
                                    {stores.map((store) => (
                                        <SelectItem key={store.id} value={String(store.id)}>
                                            {store.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Page</th>
                                        <th className="px-3 py-2 font-medium">Store</th>
                                        <th className="px-3 py-2 font-medium">Type</th>
                                        <th className="px-3 py-2 font-medium">Address</th>
                                        <th className="px-3 py-2 font-medium">Sections</th>
                                        <th className="px-3 py-2 font-medium">State</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pages.map((page) => (
                                        <tr key={page.id} className="border-t">
                                            <td className="px-3 py-2">
                                                <Link href={route('commerce.pages.edit', page.id)} className="font-medium underline">
                                                    {page.title}
                                                </Link>
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2">{page.store_name}</td>
                                            <td className="text-muted-foreground px-3 py-2">{page.type_label}</td>
                                            <td className="text-muted-foreground px-3 py-2 font-mono text-xs">{page.path}</td>
                                            <td className="text-muted-foreground px-3 py-2">{page.section_count}</td>
                                            <td className="px-3 py-2">
                                                {page.is_published ? (
                                                    <Badge>Published</Badge>
                                                ) : page.is_scheduled ? (
                                                    <Badge variant="secondary">Scheduled</Badge>
                                                ) : (
                                                    <Badge variant="outline">Draft</Badge>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {pages.length === 0 && (
                                        <tr>
                                            {/* ⚠ Says which of the two it is. An empty table under a filter
                                                means nothing matched, not that nothing exists. */}
                                            <td colSpan={6} className="text-muted-foreground px-3 py-6 text-center">
                                                {filters.store ? 'No pages on this store yet.' : 'No pages yet. Create the first one above.'}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
