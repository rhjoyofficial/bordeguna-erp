import { Pagination } from '@/components/data-table/pagination';
import { type PreviewKind } from '@/components/file-preview/types';
import InputError from '@/components/input-error';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    ChevronRight,
    Download,
    FileArchive,
    FileCode,
    File as FileIcon,
    FileSpreadsheet,
    FileText,
    Folder as FolderIcon,
    FolderInput,
    FolderPlus,
    Home,
    Image as ImageIcon,
    LayoutGrid,
    List as ListIcon,
    Music,
    Pencil,
    Presentation,
    Trash2,
    Upload,
    Video,
} from 'lucide-react';
import { DragEvent, FormEventHandler, lazy, Suspense, useState } from 'react';

// ⚠⚠ THE PREVIEW PANEL IS ITS OWN CHUNK, and not only to keep this page small.
// The readers share helpers with the panel; when the panel sat in this page's
// chunk, the readers imported from the PAGE chunk and Vite's manifest lost the
// `resources/js/pages/files/index.tsx` key, so Laravel's @vite could no longer
// find the page and every visit to /files failed. Loaded lazily, the shared
// helpers live in the panel's chunk instead and the page keeps its own entry.
const FilePreview = lazy(() => import('@/components/file-preview/file-preview').then((m) => ({ default: m.FilePreview })));

/** The file's extension as a small badge (Joy, 12 Oct 2026: "file extension must be visible"). */
function ExtensionBadge({ filename }: { filename: string }) {
    const dot = filename.lastIndexOf('.');
    const ext = dot > 0 ? filename.slice(dot + 1).toUpperCase() : '';

    if (ext === '') return null;

    return (
        <span className="bg-primary/10 text-primary inline-flex shrink-0 items-center rounded px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-wide">
            .{ext}
        </span>
    );
}

interface StoredFile {
    id: number;
    name: string;
    filename: string;
    description: string | null;
    reference: string | null;
    document_date: string | null;
    category: string | null;
    category_id: number | null;
    folder_id: number | null;
    can_manage: boolean;
    version: number;
    superseded: boolean;
    previewable: PreviewKind | null;
    kind: string;
    size: string;
    bytes: number;
    mime: string | null;
    uploaded_by: string;
    uploaded_at: string | null;
    expires_on: string | null;
    expired: boolean;
    expiring: boolean;
    url: string;
    can_delete: boolean;
}

interface Folder {
    id: number;
    name: string;
    file_count: number;
    subfolder_count: number;
}

interface FolderRef {
    id: number;
    name: string;
    parent_id: number | null;
}

interface Category {
    value: string;
    label: string;
    retention_months: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Documents', href: '/files' }];

const VIEW_KEY = 'files.view';

// What the upload box accepts; the server checks the same list by name and by content.
const UPLOAD_ACCEPT =
    '.pdf,.jpg,.jpeg,.png,.webp,.gif,.csv,.txt,.md,.markdown,.json,.xml,.log,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.mp4,.webm,.mov,.m4v,.mp3,.wav,.ogg';

function readView(): 'grid' | 'list' {
    try {
        return localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid';
    } catch {
        return 'grid';
    }
}

// The icon that stands in for a file, by what it is. A tile people can scan.
function FileGlyph({ file }: { file: StoredFile }) {
    const kind = file.previewable;
    const cls = 'size-8';
    if (kind === 'image') return <ImageIcon className={cls} />;
    if (kind === 'video') return <Video className={cls} />;
    if (kind === 'audio') return <Music className={cls} />;
    if (kind === 'pdf' || kind === 'docx' || kind === 'doc' || kind === 'markdown') return <FileText className={cls} />;
    if (kind === 'sheet') return <FileSpreadsheet className={cls} />;
    if (kind === 'pptx' || kind === 'ppt') return <Presentation className={cls} />;
    if (kind === 'text') return <FileCode className={cls} />;
    if (kind === 'zip') return <FileArchive className={cls} />;
    return <FileIcon className={cls} />;
}

// An indented option per folder, so the picker reads as a tree.
function folderOptions(folders: FolderRef[], parent: number | null, depth: number): { id: number; label: string }[] {
    return folders
        .filter((f) => f.parent_id === parent)
        .flatMap((f) => [{ id: f.id, label: `${'  '.repeat(depth)}${f.name}` }, ...folderOptions(folders, f.id, depth + 1)]);
}

/** Rename / re-file / move a library file. Only fields it shows are sent. */
function EditFileDialog({
    file,
    folders,
    categories,
    onClose,
}: {
    file: StoredFile;
    folders: FolderRef[];
    categories: Category[];
    onClose: () => void;
}) {
    const form = useForm({
        title: file.name,
        reference: file.reference ?? '',
        description: file.description ?? '',
        document_date: file.document_date ?? '',
        expires_on: file.expires_on ?? '',
        document_category_id: file.category_id ? String(file.category_id) : '',
        folder_id: file.folder_id ? String(file.folder_id) : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            document_category_id: data.document_category_id === '' ? null : data.document_category_id,
            folder_id: data.folder_id === '' ? null : data.folder_id,
        }));
        form.patch(route('files.update', file.id), { preserveScroll: true, onSuccess: onClose });
    };

    const options = folderOptions(folders, null, 0);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit document</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-title">Title</Label>
                        <Input id="edit-title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        <InputError message={form.errors.title} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-folder">Folder</Label>
                        <SearchSelect fit id="edit-folder" value={form.data.folder_id} onChange={(e) => form.setData('folder_id', e.target.value)}>
                            <option value="">Home (no folder)</option>
                            {options.map((o) => (
                                <option key={o.id} value={String(o.id)}>
                                    {o.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-kind">Kind</Label>
                        <SearchSelect
                            fit
                            id="edit-kind"
                            value={form.data.document_category_id}
                            onChange={(e) => form.setData('document_category_id', e.target.value)}
                        >
                            <option value="">Not filed</option>
                            {categories.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="edit-ref">Reference</Label>
                            <Input id="edit-ref" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="edit-date">Date on document</Label>
                            <Input
                                id="edit-date"
                                type="date"
                                value={form.data.document_date}
                                onChange={(e) => form.setData('document_date', e.target.value)}
                            />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-expires">Good until</Label>
                        <Input
                            id="edit-expires"
                            type="date"
                            value={form.data.expires_on}
                            onChange={(e) => form.setData('expires_on', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-desc">What it is</Label>
                        <Input id="edit-desc" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Rename an existing folder, or create one under the current folder. */
function FolderDialog({ folder, parentId, onClose }: { folder: Folder | null; parentId: number | null; onClose: () => void }) {
    // Rename sends the name alone (so it never moves the folder); create sends the
    // name plus the parent it is being made under.
    const form = useForm({ name: folder?.name ?? '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (folder) {
            form.patch(route('files.folders.update', folder.id), { preserveScroll: true, onSuccess: onClose });
        } else {
            form.transform((data) => ({ ...data, parent_id: parentId }));
            form.post(route('files.folders.store'), { preserveScroll: true, onSuccess: onClose });
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{folder ? 'Rename folder' : 'New folder'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="folder-name" required>
                            Folder name
                        </Label>
                        <Input
                            id="folder-name"
                            autoFocus
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="Licences"
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing || form.data.name.trim() === ''}>
                            {folder ? 'Rename' : 'Create'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
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
    browsing,
    current_folder,
    breadcrumb,
    folders,
    all_folders,
    opened,
}: {
    files: Paginated<StoredFile>;
    opened: StoredFile | null;
    filters: { search: string; type: string; from: string; to: string; category: string; superseded: string };
    types: { value: string; label: string }[];
    categories: Category[];
    expiring_count: number;
    expired_count: number;
    can: { manage: boolean };
    browsing: boolean;
    current_folder: { id: number; name: string } | null;
    breadcrumb: { id: number; name: string }[];
    folders: Folder[];
    all_folders: FolderRef[];
}) {
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/files', initialFilters);
    // A link with ?open=<id> (the preview's "New tab" button) lands with that file open.
    const [previewing, setPreviewing] = useState<StoredFile | null>(opened);
    const [editing, setEditing] = useState<StoredFile | null>(null);
    const [renamingFolder, setRenamingFolder] = useState<Folder | null>(null);
    const [creatingFolder, setCreatingFolder] = useState(false);
    const [addingCategory, setAddingCategory] = useState(false);
    const [showUpload, setShowUpload] = useState(false);
    const [fileInputKey, setFileInputKey] = useState(0);
    const [view, setView] = useState<'grid' | 'list'>(readView);
    const [dragging, setDragging] = useState(false);

    const currentFolderId = current_folder?.id ?? null;

    const setViewMode = (mode: 'grid' | 'list') => {
        setView(mode);
        try {
            localStorage.setItem(VIEW_KEY, mode);
        } catch {
            /* a remembered view is a convenience, not a requirement */
        }
    };

    const openFolder = (id: number | null) => router.get('/files', id === null ? {} : { folder: id }, { preserveScroll: true, preserveState: false });

    const upload = useForm<{
        file: File | null;
        title: string;
        description: string;
        reference: string;
        document_date: string;
        document_category_id: string;
        expires_on: string;
        folder_id: string;
    }>({
        file: null,
        title: '',
        description: '',
        reference: '',
        document_date: '',
        document_category_id: '',
        expires_on: '',
        folder_id: currentFolderId === null ? '' : String(currentFolderId),
    });

    const category = useForm({ name: '', retention_months: '', description: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        upload.transform((data) => ({ ...data, folder_id: currentFolderId === null ? null : String(currentFolderId) }));
        upload.post(route('files.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                upload.reset('file', 'title', 'description', 'reference', 'document_date', 'expires_on');
                // ⚠ The browser's own file box keeps showing the old name after a
                // reset, inviting a second "File it". Remount it, and close the form.
                setFileInputKey((k) => k + 1);
                setShowUpload(false);
            },
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

    // Drag files anywhere onto the archive to file them into the folder open now.
    // Each dropped file is one upload; the server lands it in the current folder.
    // ⚠ Bug hunt 16 Oct 2026: one AFTER another. Sent together, every upload but
    // the first was cancelled (the double-submit guard keys on method and URL,
    // and Inertia cancels a visit when the next starts), so dropping four scans
    // filed one and lost three without a word.
    const uploadInTurn = (files: File[], folderId: string | null) => {
        const [file, ...rest] = files;
        if (!file) return;
        router.post(
            route('files.store'),
            { file, folder_id: folderId },
            { forceFormData: true, preserveScroll: true, onFinish: () => uploadInTurn(rest, folderId) },
        );
    };

    const onDrop = (e: DragEvent) => {
        e.preventDefault();
        setDragging(false);
        if (!can.manage) return;
        uploadInTurn(Array.from(e.dataTransfer.files), currentFolderId === null ? null : String(currentFolderId));
    };

    const deleteFile = (file: StoredFile) => {
        if (confirm(`Delete "${file.name}"? This cannot be undone.`)) {
            router.delete(route('files.destroy', file.id), { preserveScroll: true });
        }
    };

    const deleteFolder = (folder: Folder) => {
        router.delete(route('files.folders.destroy', folder.id), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documents" />
            <div
                className="flex flex-col gap-4 p-4"
                onDragOver={(e) => {
                    if (can.manage) {
                        e.preventDefault();
                        setDragging(true);
                    }
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
            >
                <ReportHeading title="Document archive" />

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

                {/* Toolbar: breadcrumb, and the actions Drive keeps at the top. */}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <nav className="flex flex-wrap items-center gap-1 text-sm">
                        <button type="button" onClick={() => openFolder(null)} className="hover:bg-muted flex items-center gap-1 rounded px-2 py-1">
                            <Home className="size-4" /> Home
                        </button>
                        {breadcrumb.map((crumb) => (
                            <span key={crumb.id} className="flex items-center gap-1">
                                <ChevronRight className="text-muted-foreground size-3" />
                                <button type="button" onClick={() => openFolder(crumb.id)} className="hover:bg-muted rounded px-2 py-1">
                                    {crumb.name}
                                </button>
                            </span>
                        ))}
                    </nav>
                    <div className="flex flex-wrap items-center gap-1">
                        <div className="mr-1 flex items-center rounded-md border">
                            <Button
                                variant={view === 'grid' ? 'secondary' : 'ghost'}
                                size="sm"
                                onClick={() => setViewMode('grid')}
                                aria-label="Grid view"
                            >
                                <LayoutGrid className="size-4" />
                            </Button>
                            <Button
                                variant={view === 'list' ? 'secondary' : 'ghost'}
                                size="sm"
                                onClick={() => setViewMode('list')}
                                aria-label="List view"
                            >
                                <ListIcon className="size-4" />
                            </Button>
                        </div>
                        {can.manage && (
                            <>
                                <Button variant="outline" size="sm" onClick={() => setCreatingFolder(true)}>
                                    <FolderPlus className="size-4" /> New folder
                                </Button>
                                <Button size="sm" onClick={() => setShowUpload((v) => !v)}>
                                    <Upload className="size-4" /> Upload
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {dragging && can.manage && (
                    <div className="border-primary bg-primary/5 text-primary rounded-lg border-2 border-dashed p-6 text-center text-sm">
                        Drop files here to add them to {current_folder ? `"${current_folder.name}"` : 'the archive'}.
                    </div>
                )}

                {/* Search / filters. When any is set, the view goes flat across every folder. */}
                <div className="grid gap-3 rounded-lg border p-4 md:grid-cols-5">
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-search">Search</Label>
                        <Input
                            id="file-search"
                            placeholder="Title, reference or file name"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-category">Kind</Label>
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
                        <Label htmlFor="file-type">Belongs to</Label>
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
                        <Label htmlFor="file-from">From</Label>
                        <Input id="file-from" type="date" value={filters.from} onChange={(e) => setFilter('from', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="file-to">To</Label>
                        <Input id="file-to" type="date" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} />
                    </div>
                    <div className="flex flex-wrap items-center gap-3 md:col-span-5">
                        <span className="text-muted-foreground text-sm">
                            {files.total} document{files.total === 1 ? '' : 's'}
                            {browsing ? '' : ' match'}
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

                {can.manage && showUpload && (
                    <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="file" required>
                                Document
                            </Label>
                            <Input
                                id="file"
                                key={fileInputKey}
                                type="file"
                                accept={UPLOAD_ACCEPT}
                                onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                            />
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
                                {upload.processing ? 'Filing…' : current_folder ? `File into "${current_folder.name}"` : 'File it'}
                            </Button>
                            <Button type="button" variant="ghost" size="sm" onClick={() => setAddingCategory(!addingCategory)}>
                                {addingCategory ? 'Cancel' : 'Add a kind'}
                            </Button>
                        </div>
                    </form>
                )}

                {can.manage && showUpload && addingCategory && (
                    <form onSubmit={submitCategory} className="flex flex-wrap items-end gap-3 rounded-lg border border-dashed p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="cat-name" required>
                                Kind of document
                            </Label>
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
                    </form>
                )}

                {/* ⭐ 12 Oct 2026 (Joy): the preview opened far down the page, out of
                    sight, so a click looked like nothing happened. It is a large
                    centred window now: the page stays put behind it, Esc or the
                    backdrop closes it. */}
                <Dialog open={previewing !== null} onOpenChange={(open) => !open && setPreviewing(null)}>
                    <DialogContent className="flex max-h-[92vh] w-[96vw] max-w-6xl flex-col overflow-hidden p-0 sm:max-w-6xl [&>button:last-child]:hidden">
                        <DialogTitle className="sr-only">{previewing ? `Preview of ${previewing.filename}` : 'Preview'}</DialogTitle>
                        <div className="min-h-0 flex-1 overflow-y-auto p-3 sm:p-5">
                            {previewing && (
                                <Suspense fallback={<p className="text-muted-foreground p-6 text-sm">Opening...</p>}>
                                    <FilePreview file={previewing} onClose={() => setPreviewing(null)} openUrl={`/files?open=${previewing.id}`} />
                                </Suspense>
                            )}
                        </div>
                    </DialogContent>
                </Dialog>

                {/* Folders at this level (browsing only). */}
                {browsing && folders.length > 0 && (
                    <div className={view === 'grid' ? 'grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4' : 'flex flex-col gap-1'}>
                        {folders.map((folder) =>
                            view === 'grid' ? (
                                <div key={folder.id} className="hover:bg-muted/50 group flex items-center gap-3 rounded-lg border p-3">
                                    <button
                                        type="button"
                                        className="flex flex-1 items-center gap-3 text-left"
                                        onDoubleClick={() => openFolder(folder.id)}
                                        onClick={() => openFolder(folder.id)}
                                    >
                                        <FolderIcon className="size-8 shrink-0 text-sky-500" />
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">{folder.name}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {folder.file_count} file{folder.file_count === 1 ? '' : 's'}
                                                {folder.subfolder_count > 0 &&
                                                    `, ${folder.subfolder_count} folder${folder.subfolder_count === 1 ? '' : 's'}`}
                                            </p>
                                        </div>
                                    </button>
                                    {can.manage && (
                                        <div className="flex shrink-0 opacity-0 group-hover:opacity-100">
                                            <Button variant="ghost" size="sm" onClick={() => setRenamingFolder(folder)} aria-label="Rename folder">
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => deleteFolder(folder)} aria-label="Delete folder">
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div key={folder.id} className="hover:bg-muted/50 group flex items-center gap-3 rounded-md border px-3 py-2">
                                    <button type="button" className="flex flex-1 items-center gap-2 text-left" onClick={() => openFolder(folder.id)}>
                                        <FolderIcon className="size-5 text-sky-500" />
                                        <span className="font-medium">{folder.name}</span>
                                        <span className="text-muted-foreground text-xs">
                                            {folder.file_count} file{folder.file_count === 1 ? '' : 's'}
                                        </span>
                                    </button>
                                    {can.manage && (
                                        <div className="flex shrink-0 opacity-0 group-hover:opacity-100">
                                            <Button variant="ghost" size="sm" onClick={() => setRenamingFolder(folder)} aria-label="Rename folder">
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => deleteFolder(folder)} aria-label="Delete folder">
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            ),
                        )}
                    </div>
                )}

                {/* Files. */}
                {view === 'grid' ? (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        {files.data.map((file) => (
                            <div key={file.id} className="hover:bg-muted/50 group flex flex-col rounded-lg border p-3">
                                <button
                                    type="button"
                                    className="flex flex-1 flex-col items-start gap-2 text-left"
                                    onClick={() => setPreviewing(file)}
                                >
                                    <div className="flex w-full items-center justify-between gap-2">
                                        <span className="text-muted-foreground">
                                            <FileGlyph file={file} />
                                        </span>
                                        <ExtensionBadge filename={file.filename} />
                                    </div>
                                    <p className="line-clamp-2 text-sm font-medium">{file.name}</p>
                                    <p className="text-muted-foreground w-full truncate font-mono text-[11px]" title={file.filename}>
                                        {file.filename}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {file.kind} · {file.size}
                                    </p>
                                    <div className="flex flex-wrap gap-1">
                                        {file.expires_on && (
                                            <Badge variant={file.expired ? 'destructive' : file.expiring ? 'secondary' : 'outline'}>
                                                {file.expired ? 'Out of date' : 'Good until'} {file.expires_on}
                                            </Badge>
                                        )}
                                        {file.superseded && <Badge variant="outline">Replaced</Badge>}
                                        {file.version > 1 && <Badge variant="outline">v{file.version}</Badge>}
                                    </div>
                                </button>
                                <div className="mt-2 flex flex-wrap gap-1 opacity-0 group-hover:opacity-100">
                                    <Button variant="ghost" size="sm" asChild aria-label="Download">
                                        <a href={`${file.url}?download=1`}>
                                            <Download className="size-4" />
                                        </a>
                                    </Button>
                                    {file.can_manage && (
                                        <>
                                            <Button variant="ghost" size="sm" onClick={() => setEditing(file)} aria-label="Edit or move">
                                                <FolderInput className="size-4" />
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => deleteFile(file)} aria-label="Delete">
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
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
                                            </button>{' '}
                                            <ExtensionBadge filename={file.filename} />
                                            <div className="text-muted-foreground font-mono text-[11px] break-all">{file.filename}</div>
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
                                            {file.can_manage && (
                                                <Button variant="ghost" size="sm" onClick={() => setEditing(file)}>
                                                    Edit
                                                </Button>
                                            )}
                                            {file.can_delete && (
                                                <Button variant="ghost" size="sm" onClick={() => deleteFile(file)}>
                                                    Delete
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {folders.length === 0 && files.data.length === 0 && (
                    <p className="text-muted-foreground py-8 text-center text-sm">
                        {hasActiveFilters
                            ? 'No documents match these filters.'
                            : browsing && current_folder
                              ? 'This folder is empty.'
                              : 'Nothing filed yet.'}
                    </p>
                )}

                <Pagination meta={files} />
            </div>

            {editing && <EditFileDialog file={editing} folders={all_folders} categories={categories} onClose={() => setEditing(null)} />}
            {creatingFolder && <FolderDialog folder={null} parentId={currentFolderId} onClose={() => setCreatingFolder(false)} />}
            {renamingFolder && <FolderDialog folder={renamingFolder} parentId={null} onClose={() => setRenamingFolder(null)} />}
        </AppLayout>
    );
}
