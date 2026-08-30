import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface StoredFile {
    id: number;
    name: string;
    kind: string;
    size: string;
    mime: string | null;
    uploaded_by: string;
    uploaded_at: string | null;
    expires_on: string | null;
    expired: boolean;
    url: string;
    can_delete: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Files', href: '/files' }];

export default function FilesIndex({
    files,
    filters: initialFilters,
    types,
    can,
}: {
    files: Paginated<StoredFile>;
    filters: { search: string; type: string; from: string; to: string };
    types: { value: string; label: string }[];
    can: { manage: boolean };
}) {
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/files', initialFilters);

    const upload = useForm<{ file: File | null; expires_on: string }>({ file: null, expires_on: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('files.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => upload.reset(),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Files" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h2 className="text-lg font-medium">Files</h2>
                    <p className="text-muted-foreground text-sm">
                        Everything the business has stored, including the documents attached to records elsewhere. You only see files belonging to
                        records you could already open, so this list is not the same length for everybody.
                    </p>
                </div>

                {can.manage && (
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-lg border p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="file">Add to the library</Label>
                            <Input id="file" type="file" onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)} />
                            <InputError message={upload.errors.file} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="expires_on">Good until (optional)</Label>
                            <Input
                                id="expires_on"
                                type="date"
                                value={upload.data.expires_on}
                                onChange={(e) => upload.setData('expires_on', e.target.value)}
                            />
                            <InputError message={upload.errors.expires_on} />
                        </div>
                        <Button type="submit" disabled={upload.processing || !upload.data.file}>
                            Upload
                        </Button>
                        <p className="text-muted-foreground w-full text-xs">
                            Documents, images, spreadsheets and archives, up to 10 MB. A date makes the file show as out of date once it passes, which
                            is what a trade licence or a certificate needs.
                        </p>
                    </form>
                )}

                <div className="grid gap-3 rounded-lg border p-4 md:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-search">Search</Label>
                        <Input
                            id="file-search"
                            placeholder="File name"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-type">Belongs to</Label>
                        <SearchSelect id="file-type" value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                            <option value="">Anything</option>
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-from">From</Label>
                        <Input id="file-from" type="date" value={filters.from} onChange={(e) => setFilter('from', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-to">To</Label>
                        <Input id="file-to" type="date" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} />
                    </div>
                    <div className="flex items-center gap-2 md:col-span-4">
                        <span className="text-muted-foreground text-sm">
                            {files.total} file{files.total === 1 ? '' : 's'}
                        </span>
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
                                <th className="p-3">File</th>
                                <th className="p-3">Belongs to</th>
                                <th className="p-3">Size</th>
                                <th className="p-3">Added by</th>
                                <th className="p-3">Added</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {files.data.map((file) => (
                                <tr key={file.id} className="border-t">
                                    <td className="p-3">
                                        <a href={file.url} className="font-medium underline-offset-2 hover:underline">
                                            {file.name}
                                        </a>
                                        {file.expires_on && (
                                            <div>
                                                <Badge variant={file.expired ? 'destructive' : 'outline'}>
                                                    {file.expired ? 'Out of date' : 'Good until'} {file.expires_on}
                                                </Badge>
                                            </div>
                                        )}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">{file.kind}</td>
                                    <td className="p-3 whitespace-nowrap">{file.size}</td>
                                    <td className="p-3 whitespace-nowrap">{file.uploaded_by}</td>
                                    <td className="p-3 whitespace-nowrap">{file.uploaded_at}</td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        <Button variant="ghost" size="sm" asChild>
                                            <a href={file.url}>Open</a>
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
                                    <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                        {hasActiveFilters ? 'No files match these filters.' : 'No files stored yet.'}
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
