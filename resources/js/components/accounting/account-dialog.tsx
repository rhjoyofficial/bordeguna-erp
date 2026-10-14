import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';

export interface ChartAccount {
    id: number;
    code: string;
    name: string;
    type: string;
    type_label: string;
    normal_balance: 'debit' | 'credit';
    parent_id: number | null;
    is_postable: boolean;
    is_active: boolean;
    is_system: boolean;
    note: string | null;
}

export interface ChartAccountType {
    value: string;
    label: string;
    normal_balance: 'debit' | 'credit';
}

const EMPTY = { code: '', name: '', type: 'asset', parent_id: '', is_postable: true, is_active: true, note: '' };

/**
 * New head and Edit head in one popup (Joy, 14 Oct 2026: "on the chart of
 * accounts head page adding new, modify edit also from the popup system").
 * The form that sat open above the chart is gone, and Edit no longer changes
 * a form at the top of a page the person has scrolled away from.
 *
 * Same fields, same routes, same server rules as before. The note, which the
 * server always accepted but the old form never drew, now has a box.
 */
export function AccountDialog({
    open,
    onOpenChange,
    account,
    preset,
    accounts,
    types,
    moneyGroupIds,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The head being edited; null for a new one. */
    account: ChartAccount | null;
    /** "Add under" on a group row: the new head starts in that group. */
    preset: { type: string; parent_id: number } | null;
    accounts: ChartAccount[];
    types: ChartAccountType[];
    moneyGroupIds: number[];
}) {
    // 26 Sep 2026: the chart key is split. Creating, renaming and deleting are
    // three keys; anything STRUCTURAL (a top-level heading, a move, a re-type,
    // head to heading or back, retiring a heading) needs a fourth, so the form
    // only offers those controls to somebody who holds it. The server checks
    // the same rule, so this is courtesy, not the guard.
    const { canCreateLedgerHeads, canUpdateLedgerHeads, canManageChartStructure } = usePage<SharedData>().props;
    const editing = account !== null;
    const { data, setData, post, put, errors, processing, clearErrors, setDefaults } = useForm(EMPTY);

    // 12 Oct 2026 (Joy): "each time we need to add the code ... it's very
    // painful". The server proposes the next free code from Type and Sits under
    // (Support\AccountCodes); it fills the box until the person types their own.
    const [codeTyped, setCodeTyped] = useState(false);
    const [suggestedCode, setSuggestedCode] = useState('');

    // Fresh fields every time it opens: the row for an edit, the group for
    // "Add under", blank otherwise.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = account
            ? {
                  code: account.code,
                  name: account.name,
                  type: account.type,
                  parent_id: account.parent_id === null ? '' : String(account.parent_id),
                  is_postable: account.is_postable,
                  is_active: account.is_active,
                  note: account.note ?? '',
              }
            : { ...EMPTY, ...(preset ? { type: preset.type, parent_id: String(preset.parent_id) } : {}) };

        setDefaults(next);
        setData(next);
        setCodeTyped(false);
        setSuggestedCode('');
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, account, preset]);

    useEffect(() => {
        if (!open || editing || !canCreateLedgerHeads) {
            return;
        }

        // Without the structure key a top-level account cannot be made, so there
        // is nothing to propose until a heading is chosen.
        if (data.parent_id === '' && !canManageChartStructure) {
            setSuggestedCode('');

            if (!codeTyped) {
                setData('code', '');
            }

            return;
        }

        let cancelled = false;
        const query = new URLSearchParams({ type: data.type });

        if (data.parent_id !== '') {
            query.set('parent_id', data.parent_id);
        }

        fetch(`${route('accounting.accounts.next-code')}?${query.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : { code: null }))
            .then((body: { code: string | null }) => {
                if (cancelled) {
                    return;
                }

                setSuggestedCode(body.code ?? '');

                if (!codeTyped) {
                    setData('code', body.code ?? '');
                }
            })
            .catch(() => setSuggestedCode(''));

        return () => {
            cancelled = true;
        };
        // Asked again only when where the account sits changes, or after a save.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, data.type, data.parent_id, editing, accounts]);

    // Only accounts of the same type can be a parent, because a child must match
    // its parent's type. Offering the rest and rejecting the choice afterwards
    // teaches the rule one failed save at a time.
    const parentOptions = useMemo(
        () =>
            accounts.filter(
                (a) =>
                    a.type === data.type &&
                    a.id !== account?.id &&
                    // ⚠ Never under the cash, bank and wallet groups, for ANYONE:
                    // the server refuses a new head there whatever the key
                    // (Bank & cash makes those ledgers). Seen live 14 Oct 2026: the
                    // Owner was offered them. Kept only when it is the account's
                    // own parent, so an edit still shows where it sits.
                    (!moneyGroupIds.includes(a.id) || a.id === account?.parent_id) &&
                    // A head cannot sit under a head (the structure key may still
                    // turn a head into a group, so it sees them).
                    (canManageChartStructure || !a.is_postable),
            ),
        [accounts, data.type, account, canManageChartStructure, moneyGroupIds],
    );
    const structureLocked = editing && !canManageChartStructure;
    // A cash, bank or wallet ledger, or one of their three groups: Bank & cash owns it.
    const moneyLedger = editing && account.parent_id !== null && moneyGroupIds.includes(account.parent_id);
    const moneyGroup = editing && moneyGroupIds.includes(account.id);
    const normalBalance = types.find((t) => t.value === data.type)?.normal_balance;

    const close = () => {
        clearErrors();
        onOpenChange(false);
    };

    // ⚠ "Add and start another" keeps Type and Sits under, clears the rest and
    // asks for the next free code: adding five heads to one group is one popup.
    const save = (another: boolean) => {
        if (account) {
            put(route('accounting.accounts.update', account.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('accounting.accounts.store'), {
            preserveScroll: true,
            onSuccess: () => {
                if (!another) {
                    close();

                    return;
                }

                setData({ ...data, code: '', name: '', note: '' });
                setCodeTyped(false);
                clearErrors();
            },
        });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        save(false);
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${account.code} - ${account.name}` : 'New ledger head'}</DialogTitle>
                        <DialogDescription>
                            {moneyLedger
                                ? 'This is the ledger of a Bank & cash account: only its note changes here. Rename, move or switch it off in Accounting > Bank & cash.'
                                : moneyGroup
                                  ? 'This group holds the cash, bank or wallet ledgers: only its name and note change here. Add a ledger under it from Accounting > Bank & cash.'
                                  : editing && account.is_system
                                    ? 'The system posts to this account by itself. It cannot be deleted, and only the Owner can switch it off.'
                                    : 'A head is where amounts are posted; a group only holds heads under it.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-wrap items-start gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="account-type" required>
                                Type
                            </Label>
                            <SearchSelect
                                fit
                                id="account-type"
                                value={data.type}
                                disabled={structureLocked}
                                onChange={(e) => setData({ ...data, type: e.target.value, parent_id: '' })}
                            >
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            {normalBalance && (
                                <p className="text-muted-foreground text-xs">Normal balance: {normalBalance === 'debit' ? 'Debit' : 'Credit'}</p>
                            )}
                            <InputError message={errors.type} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="account-parent" required={!canManageChartStructure}>
                                Sits under
                            </Label>
                            <SearchSelect
                                fit
                                id="account-parent"
                                value={data.parent_id}
                                disabled={structureLocked}
                                onChange={(e) => setData('parent_id', e.target.value)}
                            >
                                {canManageChartStructure ? <option value="">Top level</option> : <option value="">Choose a heading</option>}
                                {parentOptions.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.code} - {a.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.parent_id} />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-start gap-4">
                        <div className="grid gap-2">
                            {/* ⚠ Optional on a new head: a blank code is filled from the group
                                (AccountCodes). Required on an edit, where nothing renumbers. */}
                            <Label htmlFor="account-code" required={editing}>
                                Code
                            </Label>
                            <Input
                                id="account-code"
                                value={data.code}
                                onChange={(e) => {
                                    setData('code', e.target.value);
                                    // Clearing the box hands it back to the suggestion.
                                    setCodeTyped(e.target.value !== '');
                                }}
                                className="w-28"
                                placeholder="6320"
                            />
                            {!editing && data.code !== '' && data.code === suggestedCode && (
                                <p className="text-muted-foreground text-xs">Suggested, you can change it</p>
                            )}
                            <InputError message={errors.code} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="account-name" required>
                                Name
                            </Label>
                            <Input
                                id="account-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="w-72"
                                placeholder="Security services"
                            />
                            <InputError message={errors.name} />
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-x-6 gap-y-2">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={data.is_postable}
                                disabled={structureLocked}
                                onChange={(e) => setData('is_postable', e.target.checked)}
                            />
                            Can be posted to (untick for a group)
                        </label>
                        {editing && (
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={data.is_active}
                                    disabled={structureLocked && !account.is_postable}
                                    onChange={(e) => setData('is_active', e.target.checked)}
                                />
                                Active
                            </label>
                        )}
                    </div>
                    <InputError message={errors.is_postable ?? errors.is_active} />

                    <div className="grid gap-2">
                        <Label htmlFor="account-note">Note</Label>
                        <textarea
                            id="account-note"
                            rows={2}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            placeholder="What goes here, and what does not"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                        <InputError message={errors.note} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        {!editing && (
                            <Button type="button" variant="outline" disabled={processing || !canCreateLedgerHeads} onClick={() => save(true)}>
                                Add and start another
                            </Button>
                        )}
                        <Button disabled={processing || (editing ? !canUpdateLedgerHeads : !canCreateLedgerHeads)}>
                            {editing ? `Save ${account.code}` : 'Add head'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
