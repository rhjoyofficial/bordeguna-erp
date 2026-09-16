import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Party {
    id: number;
    name: string;
    organisation: string | null;
    phone: string | null;
    kind: string;
    kind_label: string;
    supplier_id: number | null;
    supplier: string | null;
    note: string | null;
    is_active: boolean;
    holding: number;
}

interface Option {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Things we keep', href: '/assets/items' },
    { title: 'Outside parties', href: '/assets/parties' },
];

function PartyForm({
    party,
    kinds,
    suppliers,
    onDone,
}: {
    party: Party | null;
    kinds: Option[];
    suppliers: { id: number; name: string }[];
    onDone?: () => void;
}) {
    const { data, setData, post, put, errors, processing, reset } = useForm({
        name: party?.name ?? '',
        organisation: party?.organisation ?? '',
        phone: party?.phone ?? '',
        kind: party?.kind ?? 'repair_shop',
        supplier_id: party?.supplier_id ? String(party.supplier_id) : '',
        note: party?.note ?? '',
        is_active: party?.is_active ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (party) {
            put(`/assets/parties/${party.id}`, { preserveScroll: true, onSuccess: () => onDone?.() });
        } else {
            post('/assets/parties', { preserveScroll: true, onSuccess: () => reset() });
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
            <div className="grid gap-1">
                <Label required>Name</Label>
                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} className="w-48" placeholder="Rahim" />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-1">
                <Label>Shop or organisation</Label>
                <Input
                    value={data.organisation}
                    onChange={(e) => setData('organisation', e.target.value)}
                    className="w-48"
                    placeholder="Rahim Computer Care"
                />
                <InputError message={errors.organisation} />
            </div>
            <div className="grid gap-1">
                <Label>Phone</Label>
                <Input value={data.phone} onChange={(e) => setData('phone', e.target.value)} className="w-36" placeholder="01XXXXXXXXX" />
                <InputError message={errors.phone} />
            </div>
            <div className="grid gap-1">
                <Label required>Kind</Label>
                <SearchableSelect fit value={data.kind} onChange={(v) => setData('kind', v)} options={kinds} />
                <InputError message={errors.kind} />
            </div>
            <div className="grid gap-1">
                <Label>Also a supplier</Label>
                <SearchableSelect
                    fit
                    value={data.supplier_id}
                    onChange={(v) => setData('supplier_id', v)}
                    options={[{ value: '', label: 'No' }, ...suppliers.map((s) => ({ value: String(s.id), label: s.name }))]}
                />
                <InputError message={errors.supplier_id} />
            </div>
            {party && (
                <div className="flex items-center gap-2 self-center">
                    <Checkbox id={`active-${party.id}`} checked={data.is_active} onCheckedChange={(on) => setData('is_active', on === true)} />
                    <Label htmlFor={`active-${party.id}`} className="font-normal">
                        In use
                    </Label>
                </div>
            )}
            <Button size="sm" disabled={processing}>
                {party ? 'Save' : 'Add party'}
            </Button>
            {party && (
                <Button type="button" size="sm" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            )}
        </form>
    );
}

/**
 * Outside parties that hold things (slice A2): repair shops, freelancers.
 * Switched off, never deleted; hand-overs point at them.
 */
export default function AssetParties({
    parties,
    kinds,
    suppliers,
    can_manage,
}: {
    parties: Party[];
    kinds: Option[];
    suppliers: { id: number; name: string }[];
    can_manage: boolean;
}) {
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Outside parties" />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Outside parties</h1>
                    <p className="text-muted-foreground max-w-3xl text-sm">
                        People and shops outside the business that can hold a thing: a repair shop with the printer, a freelancer with the camera. A
                        thing sent to a repair shop reads Under repair; to anyone else, With an outside party.
                    </p>
                </div>

                {can_manage && (
                    <div className="rounded-lg border p-3">
                        <PartyForm party={null} kinds={kinds} suppliers={suppliers} />
                    </div>
                )}

                {parties.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">None yet.</p>
                ) : (
                    <ul className="grid gap-2">
                        {parties.map((party) => (
                            <li key={party.id} className="rounded-lg border p-3 text-sm">
                                {editing === party.id ? (
                                    <PartyForm party={party} kinds={kinds} suppliers={suppliers} onDone={() => setEditing(null)} />
                                ) : (
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{party.name}</span>
                                            {party.organisation && <span className="text-muted-foreground">{party.organisation}</span>}
                                            {party.phone && <span className="text-muted-foreground">{party.phone}</span>}
                                            <Badge variant="outline">{party.kind_label}</Badge>
                                            {party.holding > 0 && <Badge variant="secondary">holds {party.holding}</Badge>}
                                            {!party.is_active && <Badge variant="outline">Switched off</Badge>}
                                        </div>
                                        {can_manage && (
                                            <Button size="sm" variant="ghost" onClick={() => setEditing(party.id)}>
                                                Edit
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
