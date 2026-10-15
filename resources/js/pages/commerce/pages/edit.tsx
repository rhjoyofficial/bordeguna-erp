import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Eye, EyeOff, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { FieldSpec, MediaItem, Reference, SectionFields } from './section-fields';

interface SectionRow {
    id: number;
    type_key: string;
    type_version: number;
    identity: string;
    label: string;
    group: string | null;
    purpose: string | null;
    position: number;
    is_hidden: boolean;
    payload: Record<string, unknown>;
    fields: FieldSpec[];
    is_known: boolean;
    renderable: boolean;
}

interface CatalogueType {
    key: string;
    version: number;
    identity: string;
    label: string;
    purpose: string;
    fields: FieldSpec[];
    renderable: boolean;
}

interface PageData {
    id: number;
    title: string;
    slug: string;
    seo_title: string | null;
    seo_description: string | null;
    type_label: string;
    path: string;
    is_published: boolean;
    is_scheduled: boolean;
    published_at: string | null;
    slug_locked: boolean;
    sections: SectionRow[];
    publish_problems: string[];
}

/**
 * The section composer.
 *
 * ⭐⭐ The screen that replaces sixteen bespoke Blade templates. An author adds
 * typed sections, orders them, hides them and fills them in; nobody edits
 * markup, and no copy change needs a deploy.
 *
 * ⚠ Each section saves on its own rather than the page saving as one form. A
 * page is a long document and one giant form means a validation error anywhere
 * loses the lot.
 */
export default function PageComposer({
    page,
    store,
    catalogue,
    products,
    categories,
    media,
}: {
    page: PageData;
    store: { id: number; name: string; key: string; status: string; theme_key: string | null; theme_name: string | null };
    catalogue: { group: string; types: CatalogueType[] }[];
    products: Reference[];
    categories: Reference[];
    media: MediaItem[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Online shops', href: '/commerce/stores' },
        { title: 'Pages', href: '/commerce/pages' },
        { title: page.title, href: `/commerce/pages/${page.id}` },
    ];

    const details = useForm({
        title: page.title,
        slug: page.slug,
        seo_title: page.seo_title ?? '',
        seo_description: page.seo_description ?? '',
    });

    const saveDetails: FormEventHandler = (e) => {
        e.preventDefault();
        details.put(route('commerce.pages.update', page.id), { preserveScroll: true });
    };

    const addSection = (type: CatalogueType) =>
        router.post(route('commerce.pages.sections.store', page.id), { type_key: type.key, type_version: type.version }, { preserveScroll: true });

    const move = (index: number, by: number) => {
        const target = index + by;
        if (target < 0 || target >= page.sections.length) return;

        const order = page.sections.map((section) => section.id);
        [order[index], order[target]] = [order[target], order[index]];

        // ⚠⚠ The WHOLE order is posted, never a swapped pair. Two authors sending
        // "move this one up" would interleave into an order neither of them
        // asked for.
        router.post(route('commerce.pages.sections.reorder', page.id), { order }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={page.title} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">{page.title}</h1>
                        <p className="text-muted-foreground text-sm">
                            {page.type_label} on {store.name} · <span className="font-mono text-xs">{page.path}</span>
                            {store.theme_name ? ` · ${store.theme_name}` : ' · no theme chosen'}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        {page.is_published ? (
                            <Badge>Published</Badge>
                        ) : page.is_scheduled ? (
                            <Badge variant="secondary">Scheduled</Badge>
                        ) : (
                            <Badge variant="outline">Draft</Badge>
                        )}

                        {page.is_published || page.is_scheduled ? (
                            <Button
                                variant="outline"
                                onClick={() => router.post(route('commerce.pages.unpublish', page.id), {}, { preserveScroll: true })}
                            >
                                Take down
                            </Button>
                        ) : (
                            <Button
                                disabled={page.publish_problems.length > 0}
                                onClick={() => router.post(route('commerce.pages.publish', page.id), {}, { preserveScroll: true })}
                            >
                                Publish
                            </Button>
                        )}
                    </div>
                </div>

                {/* ⭐ The gate, shown as a list rather than a single refusal. An
                    author fixing one problem at a time is an author who gives up. */}
                {page.publish_problems.length > 0 && (
                    <div className="border-destructive/40 bg-destructive/5 rounded-md border p-4">
                        <p className="text-sm font-medium">
                            {page.publish_problems.length === 1
                                ? 'One thing stops this page being published:'
                                : `${page.publish_problems.length} things stop this page being published:`}
                        </p>
                        <ul className="text-muted-foreground mt-2 list-disc space-y-1 pl-5 text-sm">
                            {page.publish_problems.map((problem, index) => (
                                <li key={index}>{problem}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <form onSubmit={saveDetails} className="grid gap-4 rounded-md border p-4 md:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="title" required>
                            Title
                        </Label>
                        <Input
                            id="title"
                            required
                            aria-required
                            value={details.data.title}
                            onChange={(e) => details.setData('title', e.target.value)}
                        />
                        <InputError message={details.errors.title} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="slug" required={!page.slug_locked}>
                            Address
                        </Label>
                        <Input
                            id="slug"
                            aria-required={!page.slug_locked}
                            value={details.data.slug}
                            disabled={page.slug_locked}
                            onChange={(e) => details.setData('slug', e.target.value)}
                        />
                        {page.slug_locked && (
                            <p className="text-muted-foreground text-xs">
                                Frozen: this page has been published, so the address has been crawled and linked. Changing it would 404 everyone
                                holding the link.
                            </p>
                        )}
                        <InputError message={details.errors.slug} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="seo_title">Search title</Label>
                        <Input
                            id="seo_title"
                            placeholder={page.title}
                            value={details.data.seo_title}
                            onChange={(e) => details.setData('seo_title', e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">Left blank, the page title is used.</p>
                        <InputError message={details.errors.seo_title} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="seo_description">Search description</Label>
                        <textarea
                            id="seo_description"
                            maxLength={320}
                            className="border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm"
                            value={details.data.seo_description}
                            onChange={(e) => details.setData('seo_description', e.target.value)}
                        />
                        <InputError message={details.errors.seo_description} />
                    </div>

                    <div className="md:col-span-2">
                        <Button type="submit" disabled={details.processing}>
                            Save details
                        </Button>
                    </div>
                </form>

                <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
                    <div className="flex flex-col gap-4">
                        <h2 className="text-sm font-semibold">Sections</h2>

                        {page.sections.length === 0 && (
                            <div className="text-muted-foreground rounded-md border border-dashed p-6 text-sm">
                                Nothing on this page yet. Add a section from the list on the right.
                            </div>
                        )}

                        {page.sections.map((section, index) => (
                            <SectionCard
                                key={section.id}
                                page={page}
                                section={section}
                                index={index}
                                total={page.sections.length}
                                products={products}
                                categories={categories}
                                media={media}
                                onMove={move}
                            />
                        ))}
                    </div>

                    <div className="flex flex-col gap-4">
                        <h2 className="text-sm font-semibold">Add a section</h2>
                        {catalogue.map((group) => (
                            <div key={group.group} className="rounded-md border p-3">
                                <p className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">{group.group}</p>
                                <div className="flex flex-col gap-1">
                                    {group.types.map((type) => (
                                        <button
                                            key={type.identity}
                                            type="button"
                                            onClick={() => addSection(type)}
                                            className="hover:bg-muted rounded px-2 py-1.5 text-left text-sm"
                                            title={type.purpose}
                                        >
                                            {type.label}
                                            {/* ⛔ Marked BEFORE it is added, not discovered at publish. */}
                                            {!type.renderable && (
                                                <span className="text-muted-foreground ml-1 text-xs">(this theme cannot draw it)</span>
                                            )}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div>
                    <Link href={route('commerce.pages.index')} className="text-muted-foreground text-sm underline">
                        Back to pages
                    </Link>
                </div>
            </div>
        </AppLayout>
    );
}

function SectionCard({
    page,
    section,
    index,
    total,
    products,
    categories,
    media,
    onMove,
}: {
    page: PageData;
    section: SectionRow;
    index: number;
    total: number;
    products: Reference[];
    categories: Reference[];
    media: MediaItem[];
    onMove: (index: number, by: number) => void;
}) {
    const [payload, setPayload] = useState<Record<string, unknown>>(section.payload ?? {});
    const [errors, setErrors] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);
    const [open, setOpen] = useState(false);

    const save = () => {
        setSaving(true);
        router.put(
            route('commerce.pages.sections.update', [page.id, section.id]),
            // ⚠ The one cast in this screen, and it is unavoidable: a section's
            // shape is decided by its contract at RUNTIME, so TypeScript cannot
            // know it statically. It is structurally a tree of strings, numbers,
            // booleans and lists, which is exactly FormDataConvertible, and the
            // server validates it against the contract regardless of what is
            // sent.
            { payload: payload as Record<string, FormDataConvertible> },
            {
                preserveScroll: true,
                onSuccess: () => setErrors([]),
                // ⚠ The validator returns EVERY problem, so they are all shown.
                onError: (bag) => setErrors(Object.values(bag).flat() as string[]),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <div className={`rounded-md border ${section.is_hidden ? 'opacity-60' : ''}`}>
            <div className="flex flex-wrap items-center justify-between gap-2 border-b p-3">
                <button type="button" className="text-left" onClick={() => setOpen(!open)}>
                    <span className="text-sm font-medium">{section.label}</span>
                    <span className="text-muted-foreground ml-2 font-mono text-xs">{section.identity}</span>
                    {section.is_hidden && (
                        <Badge variant="outline" className="ml-2">
                            Hidden
                        </Badge>
                    )}
                    {!section.is_known && (
                        <Badge variant="destructive" className="ml-2">
                            Unknown type
                        </Badge>
                    )}
                    {section.is_known && !section.renderable && (
                        <Badge variant="destructive" className="ml-2">
                            Theme cannot draw this
                        </Badge>
                    )}
                </button>

                <div className="flex items-center gap-1">
                    <Button type="button" variant="ghost" size="sm" disabled={index === 0} onClick={() => onMove(index, -1)}>
                        <ArrowUp className="size-4" />
                    </Button>
                    <Button type="button" variant="ghost" size="sm" disabled={index === total - 1} onClick={() => onMove(index, 1)}>
                        <ArrowDown className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        title={section.is_hidden ? 'Show on the page' : 'Hide from the page, keep the content'}
                        onClick={() => router.post(route('commerce.pages.sections.visibility', [page.id, section.id]), {}, { preserveScroll: true })}
                    >
                        {section.is_hidden ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.delete(route('commerce.pages.sections.destroy', [page.id, section.id]), {
                                preserveScroll: true,
                            })
                        }
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            </div>

            {open && (
                <div className="p-4">
                    {section.purpose && <p className="text-muted-foreground mb-4 text-xs">{section.purpose}</p>}

                    {section.is_known ? (
                        <>
                            <SectionFields
                                fields={section.fields}
                                payload={payload}
                                onChange={setPayload}
                                products={products}
                                categories={categories}
                                media={media}
                            />

                            {errors.length > 0 && (
                                <ul className="text-destructive mt-4 list-disc space-y-1 pl-5 text-sm">
                                    {errors.map((problem, i) => (
                                        <li key={i}>{problem}</li>
                                    ))}
                                </ul>
                            )}

                            <div className="mt-4">
                                <Button type="button" onClick={save} disabled={saving}>
                                    Save section
                                </Button>
                            </div>
                        </>
                    ) : (
                        // ⚠ A real state after a deploy that retires a type. It is
                        // shown rather than hidden, so somebody can remove it.
                        <p className="text-muted-foreground text-sm">
                            This section is a <span className="font-mono">{section.identity}</span>, which this ERP no longer defines. It cannot be
                            edited, only removed.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
