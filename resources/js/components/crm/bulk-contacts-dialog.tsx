import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, Fragment, useEffect, useState } from 'react';

// ⚠ A `type`, not an `interface`: Inertia's useForm wants an index signature,
// which a type alias has implicitly and an interface does not.
type Row = {
    name: string;
    phone: string;
    email: string;
    company: string;
    source: string;
};

type BulkForm = {
    group_id: string;
    source: string;
    rows: Row[];
};

type RowField = keyof Row;

// ⚠ Must match ContactController::BULK_LIMIT. The server refuses a longer list
// either way; this only says so before the person presses Save.
const LIMIT = 200;

const COLUMNS: { key: RowField; label: string; width: string; placeholder?: string }[] = [
    { key: 'name', label: 'Name', width: 'w-48' },
    { key: 'phone', label: 'Phone', width: 'w-36', placeholder: '01712345678' },
    { key: 'email', label: 'Email', width: 'w-56' },
    { key: 'company', label: 'Company', width: 'w-40' },
    { key: 'source', label: 'Where from', width: 'w-40' },
];

const blankRow = (): Row => ({ name: '', phone: '', email: '', company: '', source: '' });
const blankRows = (n: number): Row[] => Array.from({ length: n }, blankRow);
const isBlank = (row: Row) => COLUMNS.every((c) => !row[c.key].trim());

/**
 * Pasted spreadsheet rows, in the column order the popup shows: name, phone,
 * email, company, where from. A copy from Excel or Google Sheets arrives
 * TAB-separated; a typed list may use commas. A first row that reads "name" is
 * the header and is skipped.
 */
function parsePasted(text: string): Row[] {
    return text
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '')
        .map((line) => (line.includes('\t') ? line.split('\t') : line.split(',')).map((cell) => cell.trim()))
        .filter((cells, i) => !(i === 0 && cells[0]?.toLowerCase() === 'name'))
        .map((cells) => ({
            name: cells[0] ?? '',
            phone: cells[1] ?? '',
            email: cells[2] ?? '',
            company: cells[3] ?? '',
            source: cells[4] ?? '',
        }));
}

/**
 * Many people from one popup (Joy, 14 Oct 2026: "also will be bulk adding
 * system"). Type them in rows or paste from a spreadsheet, optionally put
 * them all in a group, save once.
 *
 * ⚠ All or nothing on the server: one bad row refuses the batch and the error
 * sits under that row. Blank rows are dropped BEFORE sending, so the row
 * numbers in an error are the row numbers on the screen.
 */
export function BulkContactsDialog({
    open,
    onOpenChange,
    groups,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    groups: { id: number; name: string; is_active: boolean }[];
}) {
    const { data, setData, post, errors, processing, reset, clearErrors, transform } = useForm<BulkForm>({
        group_id: '',
        source: '',
        rows: blankRows(5),
    });
    const [pasted, setPasted] = useState('');

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
            setPasted('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const rowError = (i: number, key: RowField) => (errors as Record<string, string | undefined>)[`rows.${i}.${key}`];
    const filled = data.rows.filter((r) => !isBlank(r)).length;

    const setCell = (i: number, key: RowField, value: string) =>
        setData(
            'rows',
            data.rows.map((r, j) => (j === i ? { ...r, [key]: value } : r)),
        );

    const removeRow = (i: number) => {
        const next = data.rows.filter((_, j) => j !== i);
        setData('rows', next.length > 0 ? next : blankRows(1));
    };

    const fillFromPaste = () => {
        const parsed = parsePasted(pasted);

        if (parsed.length === 0) {
            return;
        }

        setData('rows', [...data.rows.filter((r) => !isBlank(r)), ...parsed]);
        setPasted('');
    };

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const rows = data.rows.filter((r) => !isBlank(r));

        if (rows.length === 0) {
            return;
        }

        // Shown compacted too, so "Row 3" in an error is the third row on screen.
        setData('rows', rows);
        transform((d) => ({ ...d, rows, group_id: d.group_id || null }));
        post(route('crm.contacts.bulk'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Bulk add contacts</DialogTitle>
                        <DialogDescription>
                            Type people in the rows below or paste them from a spreadsheet. Up to {LIMIT} at a time; for a longer list use the contact
                            file import at the bottom of the page.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-wrap items-start gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="bulk-group">Put them all in a group</Label>
                            <SearchSelect fit id="bulk-group" value={data.group_id} onChange={(e) => setData('group_id', e.target.value)}>
                                <option value="">No group</option>
                                {groups
                                    .filter((g) => g.is_active)
                                    .map((g) => (
                                        <option key={g.id} value={g.id}>
                                            {g.name}
                                        </option>
                                    ))}
                            </SearchSelect>
                            <InputError message={errors.group_id} />
                        </div>
                        <div className="grid w-64 gap-2">
                            <Label htmlFor="bulk-source">Where from (every row)</Label>
                            <Input
                                id="bulk-source"
                                placeholder="Trade fair 2026"
                                value={data.source}
                                onChange={(e) => setData('source', e.target.value)}
                            />
                            <p className="text-muted-foreground text-xs">A row's own "where from" wins.</p>
                            <InputError message={errors.source} />
                        </div>
                    </div>

                    <div className="grid gap-2 rounded-lg border p-3">
                        <Label htmlFor="bulk-paste">Paste from a spreadsheet</Label>
                        <p className="text-muted-foreground text-xs">
                            Columns in this order: Name, Phone, Email, Company, Where from. Copy the cells and paste here.
                        </p>
                        <textarea
                            id="bulk-paste"
                            rows={3}
                            className="border-input bg-background rounded-md border px-3 py-2 font-mono text-xs"
                            value={pasted}
                            onChange={(e) => setPasted(e.target.value)}
                        />
                        <div>
                            <Button type="button" variant="outline" size="sm" disabled={!pasted.trim()} onClick={fillFromPaste}>
                                Fill the rows
                            </Button>
                        </div>
                    </div>

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">#</th>
                                    {COLUMNS.map((c) => (
                                        <th key={c.key} className="p-2">
                                            {c.label}
                                            {c.key === 'name' && <span className="text-destructive"> *</span>}
                                        </th>
                                    ))}
                                    <th className="p-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.rows.map((row, i) => {
                                    const rowErrors = COLUMNS.map((c) => rowError(i, c.key)).filter(Boolean);

                                    return (
                                        <Fragment key={i}>
                                            <tr className="border-t">
                                                <td className="text-muted-foreground p-2">{i + 1}</td>
                                                {COLUMNS.map((c) => (
                                                    <td key={c.key} className="p-2">
                                                        <Input
                                                            aria-label={`Row ${i + 1} ${c.label}`}
                                                            aria-invalid={Boolean(rowError(i, c.key))}
                                                            className={c.width}
                                                            placeholder={c.placeholder}
                                                            value={row[c.key]}
                                                            onChange={(e) => setCell(i, c.key, e.target.value)}
                                                        />
                                                    </td>
                                                ))}
                                                <td className="p-2">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={`Remove row ${i + 1}`}
                                                        onClick={() => removeRow(i)}
                                                    >
                                                        Remove
                                                    </Button>
                                                </td>
                                            </tr>
                                            {rowErrors.length > 0 && (
                                                <tr>
                                                    <td></td>
                                                    <td colSpan={COLUMNS.length + 1} className="px-2 pb-2">
                                                        <InputError message={[...new Set(rowErrors)].join(' ')} />
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" size="sm" onClick={() => setData('rows', [...data.rows, ...blankRows(5)])}>
                            Add 5 more rows
                        </Button>
                        <span className={filled > LIMIT ? 'text-destructive text-sm' : 'text-muted-foreground text-sm'}>
                            {filled} {filled === 1 ? 'person' : 'people'} ready{filled > LIMIT ? `, more than ${LIMIT}` : ''}
                        </span>
                    </div>
                    <InputError message={errors.rows} />

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing || filled === 0 || filled > LIMIT}>
                            Add {filled} {filled === 1 ? 'contact' : 'contacts'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
