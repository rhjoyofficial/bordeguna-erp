import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useRef, useState } from 'react';

export interface SupplierCertification {
    id: number;
    type: string;
    label: string;
    name: string | null;
    number: string | null;
    expires_on: string | null;
    is_expired: boolean;
    scans: { id: number; name: string; url: string }[];
}

type ValueLabel = { value: string; label: string };

/**
 * A supplier's certifications (Joy's answer 18, CRM slice 2e): type, number,
 * expiry and a scan, all optional, editable here by whoever manages suppliers.
 *
 * ⚠ An expired certificate is shown as expired, never hidden: a buyer needs to
 * know the BSTI licence lapsed last month more than he needs a tidy list.
 */
export function SupplierCertificationsPanel({
    supplierId,
    certifications,
    types,
    canManage,
}: {
    supplierId: number;
    certifications: SupplierCertification[];
    types: ValueLabel[];
    canManage: boolean;
}) {
    const [editing, setEditing] = useState<SupplierCertification | null>(null);
    const [adding, setAdding] = useState(false);
    const base = `/purchasing/suppliers/${supplierId}/certifications`;

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-4">
            <h2 className="text-sm font-medium">Certifications</h2>
            {certifications.length === 0 && <p className="text-muted-foreground text-xs">None recorded. Add one only when it is relevant.</p>}

            {certifications.map((c) =>
                editing?.id === c.id ? (
                    <CertificationForm
                        key={c.id}
                        action={`${base}/${c.id}`}
                        types={types}
                        certification={c}
                        submitLabel="Save"
                        onDone={() => setEditing(null)}
                    />
                ) : (
                    <div key={c.id} className="grid gap-1 border-b pb-2 text-sm last:border-b-0">
                        <p className="flex flex-wrap items-center gap-2 font-medium">
                            {c.label}
                            {c.is_expired && <Badge variant="destructive">Expired</Badge>}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            {c.number ? `No. ${c.number}` : 'No number recorded'}
                            {c.expires_on ? ` · expires ${c.expires_on}` : ' · no expiry recorded'}
                        </p>
                        {c.scans.map((scan) => (
                            <div key={scan.id} className="flex min-w-0 flex-wrap items-center gap-2">
                                <a href={scan.url} target="_blank" rel="noreferrer" className="min-w-0 truncate underline underline-offset-4">
                                    {scan.name}
                                </a>
                                {canManage && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.delete(`${base}/${c.id}/scans/${scan.id}`, { preserveScroll: true })}
                                    >
                                        Remove scan
                                    </Button>
                                )}
                            </div>
                        ))}
                        {canManage && (
                            <div className="flex flex-wrap gap-1">
                                <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(c)}>
                                    Edit
                                </Button>
                                <ConfirmDialog
                                    trigger={
                                        <Button type="button" variant="ghost" size="sm">
                                            Remove
                                        </Button>
                                    }
                                    title={`Remove ${c.label}`}
                                    description="The certification and its scans are deleted from the supplier's record."
                                    confirmLabel="Remove"
                                    onConfirm={() => router.delete(`${base}/${c.id}`, { preserveScroll: true })}
                                />
                            </div>
                        )}
                    </div>
                ),
            )}

            {canManage &&
                (adding ? (
                    <CertificationForm action={base} types={types} submitLabel="Add certification" onDone={() => setAdding(false)} />
                ) : (
                    <Button type="button" variant="outline" size="sm" className="self-start" onClick={() => setAdding(true)}>
                        Add a certification
                    </Button>
                ))}
        </div>
    );
}

function CertificationForm({
    action,
    types,
    certification,
    submitLabel,
    onDone,
}: {
    action: string;
    types: ValueLabel[];
    certification?: SupplierCertification;
    submitLabel: string;
    onDone: () => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{ type: string; name: string; number: string; expires_on: string; scan: File[] }>({
        type: certification?.type ?? types[0]?.value ?? '',
        name: certification?.name ?? '',
        number: certification?.number ?? '',
        expires_on: certification?.expires_on ?? '',
        scan: [],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(action, { preserveScroll: true, forceFormData: true, onSuccess: () => onDone() });
    };

    const errors = form.errors as Record<string, string | undefined>;
    const scanError = Object.entries(errors).find(([key]) => key.startsWith('scan'))?.[1];

    return (
        <form onSubmit={submit} className="flex flex-col gap-2 rounded-md border border-dashed p-3">
            <div className="grid gap-1">
                <Label htmlFor={`cert_type_${certification?.id ?? 'new'}`} required>
                    Certification
                </Label>
                <SearchSelect
                    fit
                    id={`cert_type_${certification?.id ?? 'new'}`}
                    value={form.data.type}
                    onChange={(e) => form.setData('type', e.target.value)}
                >
                    {types.map((t) => (
                        <option key={t.value} value={t.value}>
                            {t.label}
                        </option>
                    ))}
                </SearchSelect>
                <InputError message={errors.type} />
            </div>
            {form.data.type === 'other' && (
                <div className="grid gap-1">
                    <Label htmlFor={`cert_name_${certification?.id ?? 'new'}`}>Which certification</Label>
                    <Input
                        id={`cert_name_${certification?.id ?? 'new'}`}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} />
                </div>
            )}
            <div className="grid gap-1">
                <Label htmlFor={`cert_number_${certification?.id ?? 'new'}`}>Number (optional)</Label>
                <Input
                    id={`cert_number_${certification?.id ?? 'new'}`}
                    value={form.data.number}
                    onChange={(e) => form.setData('number', e.target.value)}
                />
                <InputError message={errors.number} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`cert_expiry_${certification?.id ?? 'new'}`}>Expires on (optional)</Label>
                <Input
                    id={`cert_expiry_${certification?.id ?? 'new'}`}
                    type="date"
                    value={form.data.expires_on}
                    onChange={(e) => form.setData('expires_on', e.target.value)}
                />
                <InputError message={errors.expires_on} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`cert_scan_${certification?.id ?? 'new'}`}>{certification ? 'Add a scan (optional)' : 'Scan (optional)'}</Label>
                <input
                    ref={input}
                    id={`cert_scan_${certification?.id ?? 'new'}`}
                    type="file"
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    multiple
                    className="w-full min-w-0 text-sm"
                    onChange={(e) => form.setData('scan', Array.from(e.target.files ?? []))}
                />
                <InputError message={scanError} />
            </div>
            <div className="flex flex-wrap gap-2">
                <Button size="sm" disabled={form.processing}>
                    {submitLabel}
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
