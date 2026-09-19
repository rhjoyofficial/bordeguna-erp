import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useEffect, useState } from 'react';

export type LedgerType = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

type Heading = { id: number; label: string; suggested_code: string | null };

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const TYPE_LABEL: Record<LedgerType, string> = {
    asset: 'Asset',
    liability: 'Liability',
    equity: 'Equity',
    income: 'Income',
    expense: 'Expense',
};

/**
 * "New ledger head", opened from inside a ledger picker (Joy's accountant, 19
 * Sep 2026): the head that is missing is added without leaving the form that
 * needed it. Talks to QuickAccountController in JSON, so the page underneath -
 * and whatever was already typed into it - is never re-rendered.
 *
 * ⚠ `types` with more than one entry (the journal form posts to any account)
 * shows a type picker; with one entry the type is fixed and not asked.
 */
export function LedgerQuickCreate({
    open,
    onOpenChange,
    types,
    initialName = '',
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    types: LedgerType[];
    initialName?: string;
    onCreated: (account: { id: number; label: string }) => void;
}) {
    const [type, setType] = useState<LedgerType>(types[0]);
    const [headings, setHeadings] = useState<Heading[]>([]);
    const [parentId, setParentId] = useState('');
    const [code, setCode] = useState('');
    const [name, setName] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setName(initialName);
            setErrors({});
        }
    }, [open, initialName]);

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;
        setParentId('');
        setCode('');

        fetch(`/accounting/accounts/quick/options?type=${type}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : { headings: [] }))
            .then((body: { headings: Heading[] }) => {
                if (!cancelled) {
                    setHeadings(body.headings);
                }
            })
            .catch(() => setHeadings([]));

        return () => {
            cancelled = true;
        };
    }, [open, type]);

    function chooseParent(next: string) {
        setParentId(next);
        const heading = headings.find((h) => String(h.id) === next);

        if (heading?.suggested_code) {
            setCode(heading.suggested_code);
        }
    }

    async function save() {
        setSaving(true);
        setErrors({});

        try {
            const response = await fetch('/accounting/accounts/quick', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ code, name, type, parent_id: parentId === '' ? null : Number(parentId) }),
            });

            if (response.status === 422) {
                const body = (await response.json()) as { errors: Record<string, string[]> };
                setErrors(Object.fromEntries(Object.entries(body.errors).map(([key, value]) => [key, value[0]])));

                return;
            }

            if (!response.ok) {
                setErrors({
                    name: response.status === 403 ? 'You are not allowed to add ledger heads. Ask the Owner.' : 'Could not save. Try again.',
                });

                return;
            }

            const account = (await response.json()) as { id: number; label: string };
            onCreated(account);
            onOpenChange(false);
        } catch {
            setErrors({ name: 'Could not reach the server. Try again.' });
        } finally {
            setSaving(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>New ledger head</DialogTitle>
                    <DialogDescription>It is added to the chart of accounts for every business, and chosen here straight away.</DialogDescription>
                </DialogHeader>
                {/* ⚠ Not a <form>: this dialog opens from inside another form, and a
                    nested form's submit would submit the outer one too. */}
                <div className="grid gap-4">
                    {types.length > 1 ? (
                        <div className="grid gap-2">
                            <Label htmlFor="ledger_quick_type" required>
                                Type
                            </Label>
                            <SearchableSelect
                                id="ledger_quick_type"
                                value={type}
                                onChange={(next) => setType(next as LedgerType)}
                                options={types.map((t) => ({ value: t, label: TYPE_LABEL[t] }))}
                            />
                        </div>
                    ) : null}
                    <div className="grid gap-2">
                        <Label htmlFor="ledger_quick_parent" required>
                            Under which heading
                        </Label>
                        <SearchableSelect
                            id="ledger_quick_parent"
                            value={parentId}
                            onChange={chooseParent}
                            options={headings.map((h) => ({ value: String(h.id), label: h.label }))}
                            placeholder="Choose a heading"
                            invalid={Boolean(errors.parent_id)}
                        />
                        <InputError message={errors.parent_id} />
                    </div>
                    <div className="grid grid-cols-[8rem_1fr] gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="ledger_quick_code" required>
                                Code
                            </Label>
                            <Input id="ledger_quick_code" value={code} onChange={(e) => setCode(e.target.value)} placeholder="e.g. 6065" />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ledger_quick_name" required>
                                Name
                            </Label>
                            <Input id="ledger_quick_name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Office Security" />
                        </div>
                    </div>
                    <InputError message={errors.code ?? errors.name ?? errors.type} />
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button type="button" disabled={saving || parentId === '' || code === '' || name === ''} onClick={save}>
                        Add ledger head
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
