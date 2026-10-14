import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

function blank(month: string) {
    return { user_id: '', month, target_amount: '', commission_percent: '' };
}

/**
 * Set a salesperson's target for a month in a popup (Joy, 14 Oct 2026: "click
 * add button pop up will add"). The form that sat open above the table is
 * gone; the table is the page and the form appears only when somebody asks.
 *
 * Same fields, same route, same server rules as before: the dialog only
 * changes where the form is drawn. ⚠ One target per person per month, so
 * setting one again for the same month REPLACES it (the server decides that,
 * not this form).
 */
export function TargetDialog({
    open,
    onOpenChange,
    month,
    users,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The month the page shows, and the default for a new target. */
    month: string;
    users: { id: number; name: string }[];
}) {
    const { data, setData, post, errors, processing, reset, clearErrors, setDefaults } = useForm(blank(month));

    // Fresh fields every time it opens, for the month the page is showing.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = blank(month);
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, month]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/sales/targets', { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Set a sales target</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Salesperson" htmlFor="target-user_id" size="md" error={errors.user_id} required>
                            <Select value={data.user_id} onValueChange={(value) => setData('user_id', value)}>
                                <SelectTrigger id="target-user_id" aria-required>
                                    <SelectValue placeholder="Pick" />
                                </SelectTrigger>
                                <SelectContent>
                                    {users.map((user) => (
                                        <SelectItem key={user.id} value={String(user.id)}>
                                            {user.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Month" htmlFor="target-month" error={errors.month} required>
                            <Input
                                id="target-month"
                                type="month"
                                aria-required
                                value={data.month}
                                onChange={(e) => setData('month', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Target (BDT)" htmlFor="target-target_amount" size="sm" error={errors.target_amount} required>
                            <Input
                                id="target-target_amount"
                                type="number"
                                aria-required
                                step="0.01"
                                min="0"
                                value={data.target_amount}
                                onChange={(e) => setData('target_amount', e.target.value)}
                            />
                        </Field>
                        <Field label="Commission %" htmlFor="target-commission_percent" size="xs" error={errors.commission_percent}>
                            <Input
                                id="target-commission_percent"
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                placeholder="0"
                                value={data.commission_percent}
                                onChange={(e) => setData('commission_percent', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Set target
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
