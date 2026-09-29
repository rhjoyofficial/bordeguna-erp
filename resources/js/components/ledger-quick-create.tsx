import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useEffect, useState } from 'react';

export type LedgerType = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

type Heading = { id: number; label: string; suggested_code: string | null; suggested_heading_code: string | null };

const NEW_HEADING = '__new__';

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
 *
 * 26 Sep 2026 (Joy, the accountant): "Under which heading" also offers
 * "+ New heading". It asks which EXISTING heading the new one sits under, its
 * code (a free band of ten is suggested) and its name, and the server makes
 * heading and head together. Never a top-level heading from here: those shape
 * the statements and stay on the Chart of accounts screen.
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
    const [headingParentId, setHeadingParentId] = useState('');
    const [headingCode, setHeadingCode] = useState('');
    const [headingName, setHeadingName] = useState('');
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
        setHeadingParentId('');
        setHeadingCode('');
        setHeadingName('');
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

    const makingHeading = parentId === NEW_HEADING;

    function chooseParent(next: string) {
        setParentId(next);
        const heading = headings.find((h) => String(h.id) === next);

        if (heading?.suggested_code) {
            setCode(heading.suggested_code);
        }

        if (next === NEW_HEADING) {
            setCode('');
        }
    }

    function chooseHeadingParent(next: string) {
        setHeadingParentId(next);
        const heading = headings.find((h) => String(h.id) === next);
        const suggested = heading?.suggested_heading_code ?? '';
        setHeadingCode(suggested);
        // The first head under a new heading takes the heading's code plus one.
        setCode(suggested && /^\d+$/.test(suggested) ? String(Number(suggested) + 1).padStart(suggested.length, '0') : '');
    }

    const ready = code !== '' && name !== '' && (makingHeading ? headingParentId !== '' && headingCode !== '' && headingName !== '' : parentId !== '');

    async function save() {
        setSaving(true);
        setErrors({});

        try {
            const response = await fetch('/accounting/accounts/quick', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify(
                    makingHeading
                        ? { code, name, type, new_heading: { parent_id: Number(headingParentId), code: headingCode, name: headingName } }
                        : { code, name, type, parent_id: parentId === '' ? null : Number(parentId) },
                ),
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
            <DialogContent className="sm:max-w-md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>New ledger head</DialogTitle>
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
                            options={[{ value: NEW_HEADING, label: '+ New heading\u2026' }, ...headings.map((h) => ({ value: String(h.id), label: h.label }))]}
                            placeholder="Choose a heading"
                            invalid={Boolean(errors.parent_id)}
                        />
                        <InputError message={errors.parent_id} />
                    </div>
                    {makingHeading ? (
                        <div className="grid gap-3 rounded-md border border-dashed p-3">
                            <div className="grid gap-2">
                                <Label htmlFor="ledger_quick_heading_parent" required>
                                    New heading sits under
                                </Label>
                                <SearchableSelect
                                    id="ledger_quick_heading_parent"
                                    value={headingParentId}
                                    onChange={chooseHeadingParent}
                                    options={headings.map((h) => ({ value: String(h.id), label: h.label }))}
                                    placeholder="Choose an existing heading"
                                    invalid={Boolean(errors['new_heading.parent_id'])}
                                />
                                <InputError message={errors['new_heading.parent_id']} />
                            </div>
                            <div className="grid grid-cols-[8rem_1fr] gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="ledger_quick_heading_code" required>
                                        Heading code
                                    </Label>
                                    <Input
                                        id="ledger_quick_heading_code"
                                        value={headingCode}
                                        onChange={(e) => setHeadingCode(e.target.value)}
                                        placeholder="e.g. 6820"
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="ledger_quick_heading_name" required>
                                        Heading name
                                    </Label>
                                    <Input
                                        id="ledger_quick_heading_name"
                                        value={headingName}
                                        onChange={(e) => setHeadingName(e.target.value)}
                                        placeholder="e.g. Field Costs"
                                    />
                                </div>
                            </div>
                            <InputError message={errors['new_heading.code'] ?? errors['new_heading.name']} />
                        </div>
                    ) : null}
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
                    <Button type="button" disabled={saving || !ready} onClick={save}>
                        {makingHeading ? 'Add heading and ledger head' : 'Add ledger head'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
