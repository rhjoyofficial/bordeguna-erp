import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface StoredFile {
    id: number;
    name: string;
    filename: string;
    description: string | null;
    reference: string | null;
    document_date: string | null;
    category: string | null;
    category_id: number | null;
    version: number;
    superseded: boolean;
    previewable: 'image' | 'pdf' | 'video' | 'audio' | null;
    kind: string;
    size: string;
    mime: string | null;
    uploaded_by: string;
    uploaded_at: string | null;
    expires_on: string | null;
    expired: boolean;
    expiring: boolean;
    url: string;
    can_delete: boolean;
}

interface Category {
    value: string;
    label: string;
    retention_months: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Documents', href: '/files' }];

/**
 * The preview panel.
 *
 * ⚠⚠ Every kind is served from the SAME authenticated attachments route the
 * download uses. There is no second endpoint and no public URL: a preview that
 * needed one would be a way to read a payslip without logging in.
 *
 * ⚠ `controls` on video and audio rather than autoplay. An archive opening a
 * document should not start making noise in an office.
 */
function Preview({ file, onClose }: { file: StoredFile; onClose: () => void }) {
    return (
        <div className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-medium">{file.name}</p>
                    <p className="text-muted-foreground text-xs">
                        {file.filename} · {file.size}
                        {file.reference && <> · {file.reference}</>}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" asChild>
                        {/* ⚠ ?download=1 flips the disposition server-side. Without
                            it the browser renders in place, which is what the
                            preview wants and not what a download button means. */}
                        <a href={`${file.url}?download=1`}>Download</a>
                    </Button>
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        Close
                    </Button>
                </div>
            </div>

            {file.description && <p className="text-muted-foreground max-w-2xl text-sm">{file.description}</p>}

            {file.previewable === 'image' && <img src={file.url} alt={file.name} className="max-h-[70vh] w-auto rounded-md border" />}

            {file.previewable === 'pdf' && <iframe src={file.url} title={file.name} className="h-[70vh] w-full rounded-md border" />}

            {file.previewable === 'video' && <video src={file.url} controls className="max-h-[70vh] w-full rounded-md border" />}

            {file.previewable === 'audio' && <audio src={file.url} controls className="w-full" />}

            {file.previewable === null && (
                <p className="text-muted-foreground text-sm">
                    This kind of file cannot be shown in the page. Download it to open it in whatever program reads it.
                </p>
            )}
        </div>
    );
}

export default function FilesIndex({
    files,
    filters: initialFilters,
    types,
    categories,
    expiring_count,
    expired_count,
    can,
}: {
    files: Paginated<StoredFile>;
    filters: { search: string; type: string; from: string; to: string; category: string; superseded: string };
    types: { value: string; label: string }[];
    categories: Category[];
    expiring_count: number;
    expired_count: number;
    can: { manage: boolean };
}) {
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/files', initialFilters);
    const [previewing, setPreviewing] = useState<StoredFile | null>(null);
    const [addingCategory, setAddingCategory] = useState(false);

    const upload = useForm<{
        file: File | null;
        title: string;
        description: string;
        reference: string;
        document_date: string;
        document_category_id: string;
        expires_on: string;
    }>({
        file: null,
        title: '',
        description: '',
        reference: '',
        document_date: '',
        document_category_id: '',
        expires_on: '',
    });

    const category = useForm({ name: '', retention_months: '', description: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('files.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => upload.reset(),
        });
    };

    const submitCategory: FormEventHandler = (e) => {
        e.preventDefault();
        category.post(route('files.categories.store'), {
            preserveScroll: true,
            onSuccess: () => {
                category.reset();
                setAddingCategory(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documents" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Document archive"
                    description="Everything the business has stored, including the documents attached to records elsewhere. You only see files belonging to records you could already open, so this list is not the same length for everybody."
                />

                {(expired_count > 0 || expiring_count > 0) && (
                    <div className="flex flex-wrap gap-2 rounded-lg border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
                        {expired_count > 0 && (
                            <span>
                                <strong>{expired_count}</strong> document{expired_count === 1 ? ' has' : 's have'} passed the date they were good
                                until.
                            </span>
                        )}
                        {expiring_count > 0 && (
                            <span>
                                <strong>{expiring_count}</strong> more run out within two months.
                            </span>
                        )}
                    </div>
                )}

                {can.manage && (
                    <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="file" required>
                                Document
                            </Label>
                            <Input id="file" type="file" onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)} />
                            <InputError message={upload.errors.file} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="title">Title</Label>
                            <Input
                                id="title"
                                placeholder="Trade licence 2026"
                                value={upload.data.title}
                                onChange={(e) => upload.setData('title', e.target.value)}
                            />
                            <InputError message={upload.errors.title} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="document_category_id">Kind</Label>
                            <SearchSelect
                                fit
                                id="document_category_id"
                                value={upload.data.document_category_id}
                                onChange={(e) => upload.setData('document_category_id', e.target.value)}
                            >
                                <option value="">Not filed</option>
                                {categories.map((c) => (
                                    <option key={c.value} value={c.value}>
                                        {c.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={upload.errors.document_category_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="reference">Reference</Label>
                            <Input
                                id="reference"
                                placeholder="Licence no."
                                value={upload.data.reference}
                                onChange={(e) => upload.setData('reference', e.target.value)}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="document_date">Date on the document</Label>
                            <Input
                                id="document_date"
                                type="date"
                                value={upload.data.document_date}
                                onChange={(e) => upload.setData('document_date', e.target.value)}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="expires_on">Good until</Label>
                            <Input
                                id="expires_on"
                                type="date"
                                value={upload.data.expires_on}
                                onChange={(e) => upload.setData('expires_on', e.target.value)}
                            />
                            <InputError message={upload.errors.expires_on} />
                        </div>
                        <div className="grid gap-2 md:col-span-3">
                            <Label htmlFor="description">What it is</Label>
                            <Input
                                id="description"
                                placeholder="Anything somebody looking for this later would search for"
                                value={upload.data.description}
                                onChange={(e) => upload.setData('description', e.target.value)}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-2 md:col-span-3">
                            <Button type="submit" disabled={upload.processing || !upload.data.file}>
                                {upload.processing ? 'Filing...' : 'File it'}
                            </Button>
                            <Button type="button" variant="ghost" size="sm" onClick={() => setAddingCategory(!addingCategory)}>
                                {addingCategory ? 'Cancel' : 'Add a kind'}
                            </Button>
                            <p className="text-muted-foreground text-xs">
                                Documents, images, spreadsheets, archives, video and audio, up to 256 MB. A date under Good until makes the document
                                show as out of date once it passes, which is what a trade licence or a certificate needs.
                            </p>
                        </div>
                    </form>
                )}

                {can.manage && addingCategory && (
                    <form onSubmit={submitCategory} className="flex flex-wrap items-end gap-3 rounded-lg border border-dashed p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="cat-name">Kind of document</Label>
                            <Input
                                id="cat-name"
                                placeholder="Contracts"
                                value={category.data.name}
                                onChange={(e) => category.setData('name', e.target.value)}
                            />
                            <InputError message={category.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="cat-retention">Keep for (months)</Label>
                            <Input
                                id="cat-retention"
                                type="number"
                                min={1}
                                className="w-40"
                                placeholder="Forever"
                                value={category.data.retention_months}
                                onChange={(e) => category.setData('retention_months', e.target.value)}
                            />
                        </div>
                        <Button type="submit" variant="secondary" disabled={category.processing}>
                            Add
                        </Button>
                        <p className="text-muted-foreground w-full text-xs">
                            Leaving the months empty means keep it indefinitely. Nothing is ever deleted automatically; this only records how long a
                            document of this kind is meant to be kept.
                        </p>
                    </form>
                )}

                {previewing && <Preview file={previewing} onClose={() => setPreviewing(null)} />}

                <div className="grid gap-3 rounded-lg border p-4 md:grid-cols-5">
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-search" required>
                            Search
                        </Label>
                        <Input
                            id="file-search"
                            placeholder="Title, reference or file name"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-category" required>
                            Kind
                        </Label>
                        <SearchSelect fit id="file-category" value={filters.category} onChange={(e) => setFilter('category', e.target.value)}>
                            <option value="">Any kind</option>
                            {categories.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-type" required>
                            Belongs to
                        </Label>
                        <SearchSelect fit id="file-type" value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                            <option value="">Anything</option>
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-from" required>
                            From
                        </Label>
                        <Input id="file-from" type="date" value={filters.from} onChange={(e) => setFilter('from', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-to" required>
                            To
                        </Label>
                        <Input id="file-to" type="date" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} />
                    </div>
                    <div className="flex flex-wrap items-center gap-3 md:col-span-5">
                        <span className="text-muted-foreground text-sm">
                            {files.total} document{files.total === 1 ? '' : 's'}
                        </span>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={filters.superseded === '1'}
                                onChange={(e) => setFilter('superseded', e.target.checked ? '1' : '')}
                            />
                            Include replaced versions
                        </label>
                        {hasActiveFilters && (
                            <Button variant="ghost" size="sm" onClick={reset}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Document</th>
                                <th className="p-3">Kind</th>
                                <th className="p-3">Belongs to</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Size</th>
                                <th className="p-3">Added by</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {files.data.map((file) => (
                                <tr key={file.id} className="border-t align-top">
                                    <td className="p-3">
                                        <button
                                            type="button"
                                            className="text-left font-medium underline-offset-2 hover:underline"
                                            onClick={() => setPreviewing(file)}
                                        >
                                            {file.name}
                                        </button>
                                        {file.reference && <div className="text-muted-foreground text-xs">{file.reference}</div>}
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            {file.expires_on && (
                                                <Badge variant={file.expired ? 'destructive' : file.expiring ? 'secondary' : 'outline'}>
                                                    {file.expired ? 'Out of date' : 'Good until'} {file.expires_on}
                                                </Badge>
                                            )}
                                            {file.superseded && <Badge variant="outline">Replaced</Badge>}
                                            {file.version > 1 && <Badge variant="outline">v{file.version}</Badge>}
                                        </div>
                                    </td>
                                    <td className="p-3 whitespace-nowrap">{file.category ?? <span className="text-muted-foreground">-</span>}</td>
                                    <td className="p-3 whitespace-nowrap">{file.kind}</td>
                                    <td className="p-3 whitespace-nowrap">{file.document_date ?? file.uploaded_at}</td>
                                    <td className="p-3 whitespace-nowrap">{file.size}</td>
                                    <td className="p-3 whitespace-nowrap">{file.uploaded_by}</td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        <Button variant="ghost" size="sm" onClick={() => setPreviewing(file)}>
                                            Preview
                                        </Button>
                                        <Button variant="ghost" size="sm" asChild>
                                            <a href={`${file.url}?download=1`}>Download</a>
                                        </Button>
                                        {file.can_delete && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => router.delete(route('files.destroy', file.id), { preserveScroll: true })}
                                            >
                                                Delete
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {files.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                        {hasActiveFilters ? 'No documents match these filters.' : 'Nothing filed yet.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={files} />
            </div>
        </AppLayout>
    );
}
