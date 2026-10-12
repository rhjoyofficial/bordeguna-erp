import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useEffect, useState } from 'react';

/**
 * ⭐ 27 Sep 2026 (Joy): a customer created from INSIDE a sale - the counter, an
 * invoice, a receipt, a quotation - without leaving the sale in progress. Talks
 * JSON to CustomerQuickCreateController and hands the new customer straight back
 * to the picker, the same shape as the inventory quick-creates.
 *
 * Only what a sale needs: name, phone and type. The price group follows the type
 * on the server, and everything else (BIN/TIN, address, credit terms) is filled
 * later on the customer's own page.
 */
const TYPE_OPTIONS = [
    { value: 'retail', label: 'Retail customer' },
    { value: 'shop', label: 'Retail shop' },
    { value: 'dealer', label: 'Dealer' },
    { value: 'distributor', label: 'Distributor' },
    { value: 'corporate', label: 'Corporate' },
];

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function CustomerQuickCreate({
    open,
    onOpenChange,
    initialName = '',
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialName?: string;
    onCreated: (row: { id: number; label: string }) => void;
}) {
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [type, setType] = useState('retail');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setName(initialName);
            setPhone('');
            setType('retail');
            setErrors({});
        }
    }, [open, initialName]);

    async function save() {
        setSaving(true);
        setErrors({});

        try {
            const response = await fetch('/sales/customers/quick', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ name, phone, type }),
            });

            if (response.status === 422) {
                const body = (await response.json()) as { errors: Record<string, string[]> };
                setErrors(Object.fromEntries(Object.entries(body.errors).map(([key, value]) => [key, value[0]])));

                return;
            }

            if (!response.ok) {
                setErrors({
                    name: response.status === 403 ? 'You are not allowed to add customers. Ask the Owner or a Manager.' : 'Could not save. Try again.',
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
                    <DialogTitle>New customer</DialogTitle>
                </DialogHeader>
                {/* ⚠ Not a <form>: this opens from inside a sale form. */}
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="cqc-name" required>
                            Name
                        </Label>
                        <Input id="cqc-name" aria-required value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Rahim Store" />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="cqc-phone">Phone</Label>
                        <Input id="cqc-phone" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="e.g. 01XXXXXXXXX" />
                        <InputError message={errors.phone} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="cqc-type">Type</Label>
                        <SearchableSelect id="cqc-type" value={type} onChange={setType} options={TYPE_OPTIONS} />
                        <InputError message={errors.type ?? errors.price_group_id} />
                    </div>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button type="button" disabled={saving || name.trim() === ''} onClick={save}>
                        {saving ? 'Adding…' : 'Add customer'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
