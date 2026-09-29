import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';

export interface IdentityDocumentKind {
    value: string;
    label: string;
    restricted: boolean;
    count: number;
    may_open: boolean;
    may_remove: boolean;
    files: { id: number; name: string; url: string }[];
}

export interface IdentityDocuments {
    can_add: boolean;
    kinds: IdentityDocumentKind[];
}

/**
 * A customer's or supplier's trade licence and NID scans (CRM slice 2e, plan
 * D11), as IdentityDocuments::props() sends them.
 *
 * ⚠ A restricted kind the reader may not open arrives as a count with no files,
 * so this screen cannot show what the stream would refuse. Adding one is still
 * offered to whoever manages the record: the NID copy is collected in the field.
 */
export function IdentityDocumentsPanel({
    documents,
    uploadUrl,
    removeUrl,
}: {
    documents: IdentityDocuments;
    uploadUrl: string;
    removeUrl: (id: number) => string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{ kind: string; files: File[] }>({ kind: documents.kinds[0]?.value ?? '', files: [] });

    // Sent as documents[kind][], the shape the conversion form uses too.
    form.transform((d) => ({ documents: { [d.kind]: d.files } }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(uploadUrl, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.setData('files', []);
                if (input.current) input.current.value = '';
            },
        });
    };

    const errors = form.errors as Record<string, string | undefined>;
    const fileError = Object.entries(errors).find(([key]) => key.startsWith('documents'))?.[1];

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-4">
            <h2 className="text-sm font-medium">Documents</h2>
            {documents.kinds.map((kind) => (
                <div key={kind.value} className="grid gap-1 text-sm">
                    <p className="flex flex-wrap items-center gap-2 font-medium">
                        {kind.label}
                        {kind.restricted && <Badge variant="outline">Owner only</Badge>}
                    </p>
                    {kind.count === 0 && <p className="text-muted-foreground text-xs">None on file.</p>}
                    {kind.count > 0 && !kind.may_open && (
                        <p className="text-muted-foreground text-xs">
                            {kind.count} on file. Only the Owner can open {kind.count === 1 ? 'it' : 'them'}.
                        </p>
                    )}
                    {kind.files.map((file) => (
                        <div key={file.id} className="flex min-w-0 flex-wrap items-center gap-2">
                            <a href={file.url} target="_blank" rel="noreferrer" className="min-w-0 truncate underline underline-offset-4">
                                {file.name}
                            </a>
                            {kind.may_remove && (
                                <ConfirmDialog
                                    trigger={
                                        <Button type="button" variant="ghost" size="sm">
                                            Remove
                                        </Button>
                                    }
                                    title={`Remove ${file.name}`}
                                    description="The scan is deleted from the ERP. Keep the paper copy if it is still needed."
                                    confirmLabel="Remove"
                                    onConfirm={() => router.delete(removeUrl(file.id), { preserveScroll: true })}
                                />
                            )}
                        </div>
                    ))}
                </div>
            ))}

            {documents.can_add && (
                <form onSubmit={submit} className="flex flex-col gap-2 border-t pt-3">
                    <div className="grid gap-1">
                        <Label htmlFor="document_kind">Add a scan</Label>
                        <SearchSelect fit id="document_kind" value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                            {documents.kinds.map((k) => (
                                <option key={k.value} value={k.value}>
                                    {k.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <input
                        ref={input}
                        id="document_files"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        multiple
                        className="w-full min-w-0 text-sm"
                        onChange={(e) => form.setData('files', Array.from(e.target.files ?? []))}
                    />
                    <InputError message={fileError} />
                    <div>
                        <Button size="sm" disabled={form.processing || form.data.files.length === 0}>
                            Save scan
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}
