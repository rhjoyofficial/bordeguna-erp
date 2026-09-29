import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useEffect, useState } from 'react';

/**
 * ⭐ 27 Sep 2026 (Joy): a unit, category, brand or supplier the dropdown is
 * missing, created from INSIDE the form that needs it. The twin of
 * LedgerQuickCreate: JSON to a quick endpoint, never an Inertia redirect (which
 * would re-render the page under the open dialog and throw away the half-filled
 * product or order). The picker gets the new row's id and label and selects it.
 */
export type QuickCreateKind = 'unit' | 'category' | 'brand' | 'supplier';

type FieldKey = 'name' | 'abbreviation' | 'code' | 'phone';

type KindConfig = {
    title: string;
    endpoint: string;
    fields: { key: FieldKey; label: string; required: boolean; placeholder?: string }[];
};

const CONFIG: Record<QuickCreateKind, KindConfig> = {
    unit: {
        title: 'New unit',
        endpoint: '/inventory/quick/units',
        fields: [
            { key: 'name', label: 'Name', required: true, placeholder: 'e.g. Kilogram' },
            { key: 'abbreviation', label: 'Short form', required: true, placeholder: 'e.g. kg' },
        ],
    },
    category: {
        title: 'New category',
        endpoint: '/inventory/quick/categories',
        fields: [
            { key: 'name', label: 'Name', required: true, placeholder: 'e.g. Spices' },
            { key: 'code', label: 'Code (optional)', required: false, placeholder: 'e.g. SPC' },
        ],
    },
    brand: {
        title: 'New brand',
        endpoint: '/inventory/quick/brands',
        fields: [
            { key: 'name', label: 'Name', required: true, placeholder: 'e.g. Bor de Guna' },
            { key: 'code', label: 'Code', required: true, placeholder: '2-8 letters, e.g. BDG' },
        ],
    },
    supplier: {
        title: 'New supplier',
        endpoint: '/purchasing/suppliers/quick',
        fields: [
            { key: 'name', label: 'Name', required: true, placeholder: 'e.g. Padma Traders' },
            { key: 'phone', label: 'Phone (optional)', required: false, placeholder: 'e.g. 01XXXXXXXXX' },
        ],
    },
};

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function EntityQuickCreate({
    kind,
    open,
    onOpenChange,
    initialName = '',
    onCreated,
}: {
    kind: QuickCreateKind;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialName?: string;
    onCreated: (row: { id: number; label: string }) => void;
}) {
    const config = CONFIG[kind];
    const [values, setValues] = useState<Record<string, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setValues({ name: initialName });
            setErrors({});
        }
    }, [open, initialName]);

    const ready = config.fields.every((field) => !field.required || (values[field.key] ?? '').trim() !== '');

    async function save() {
        setSaving(true);
        setErrors({});

        try {
            const response = await fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify(values),
            });

            if (response.status === 422) {
                const body = (await response.json()) as { errors: Record<string, string[]> };
                setErrors(Object.fromEntries(Object.entries(body.errors).map(([key, value]) => [key, value[0]])));

                return;
            }

            if (!response.ok) {
                setErrors({
                    name: response.status === 403 ? 'You are not allowed to add this. Ask the Owner or a Manager.' : 'Could not save. Try again.',
                });

                return;
            }

            const row = (await response.json()) as { id: number; label: string };
            onCreated(row);
            onOpenChange(false);
        } catch {
            setErrors({ name: 'Could not reach the server. Try again.' });
        } finally {
            setSaving(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{config.title}</DialogTitle>
                </DialogHeader>
                {/* ⚠ Not a <form>: this opens from inside another form, and a nested
                    form's submit would submit the outer one too. */}
                <div className="grid gap-4">
                    {config.fields.map((field) => (
                        <div key={field.key} className="grid gap-2">
                            <Label htmlFor={`quick_${kind}_${field.key}`} required={field.required}>
                                {field.label}
                            </Label>
                            <Input
                                id={`quick_${kind}_${field.key}`}
                                value={values[field.key] ?? ''}
                                placeholder={field.placeholder}
                                onChange={(e) => setValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
                            />
                            <InputError message={errors[field.key]} />
                        </div>
                    ))}
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button type="button" disabled={saving || !ready} onClick={save}>
                        {saving ? 'Adding…' : config.title}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
