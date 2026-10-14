import { Field, FormRow } from '@/components/form/field';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

const EMPTY = { name: '', name_bn: '', account_id: '', receipt_required: true as boolean };

/**
 * Add an expense category in a popup (14 Oct 2026). The form that sat open
 * above the mapping table is gone. Same fields, same route, same server rules.
 *
 * ⚠ Add only. A category is never edited in a form: the row's own switches
 * (account, receipt, active) save at once, and the name stays what staff
 * already know it by.
 *
 * ⚠ "New ledger head" inside the account picker opens a second popup over this
 * one. It is not a <form> on purpose (ledger-quick-create.tsx), so its Save
 * cannot submit this form; the new head is chosen here once it is in the list.
 */
export function ExpenseCategoryDialog({
    open,
    onOpenChange,
    accounts,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    accounts: { id: number; label: string }[];
}) {
    const { data, setData, post, errors, processing, reset, clearErrors } = useForm(EMPTY);

    // Blank fields every time it opens.
    useEffect(() => {
        if (!open) {
            return;
        }

        setData(EMPTY);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('hrm.expense-categories.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Add a category</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name staff will see" htmlFor="expense-category-name" size="md" error={errors.name} required>
                            <Input
                                id="expense-category-name"
                                aria-required
                                value={data.name}
                                placeholder="ERP and Hosting"
                                onChange={(e) => setData('name', e.target.value)}
                            />
                        </Field>

                        <Field label="Bangla name" htmlFor="expense-category-name-bn" size="md" error={errors.name_bn}>
                            <Input id="expense-category-name-bn" value={data.name_bn} onChange={(e) => setData('name_bn', e.target.value)} />
                        </Field>

                        <Field label="Posts to" htmlFor="expense-category-account" className="min-w-72 flex-1" error={errors.account_id} required>
                            <SearchSelect
                                newLedger={{ types: ['expense'], reload: ['accounts'] }}
                                id="expense-category-account"
                                value={data.account_id}
                                onChange={(e) => setData('account_id', e.target.value)}
                            >
                                <option value="">Pick the account…</option>
                                {accounts.map((account) => (
                                    <option key={account.id} value={String(account.id)}>
                                        {account.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>

                        <Field label="Receipt" htmlFor="expense-category-receipt" size="md">
                            <label className="flex h-10 items-center gap-2 text-sm">
                                <input
                                    id="expense-category-receipt"
                                    type="checkbox"
                                    checked={data.receipt_required}
                                    onChange={(e) => setData('receipt_required', e.target.checked)}
                                />
                                Required
                            </label>
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>Add category</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
