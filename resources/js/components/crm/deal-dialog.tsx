import { Field, FormRow } from '@/components/form/field';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

interface Option {
    id: number;
    name: string;
}

const EMPTY = {
    name: '',
    party_kind: 'lead',
    lead_id: '',
    customer_id: '',
    stage_id: '',
    value: '',
    expected_close_on: '',
    assigned_to_user_id: '',
    next_action: '',
};

/**
 * Add a deal to the pipeline in a popup (Joy, 14 Oct 2026: "click add button
 * pop up will add"). The seven-field form that sat open above the board is
 * gone; the board is the page.
 *
 * Same fields, same route, same server rules as before: the dialog only
 * changes where the form is drawn. A new deal still starts in the first
 * stage, and moving, winning and losing stay on the cards.
 */
export function DealDialog({
    open,
    onOpenChange,
    firstStageId,
    leads,
    customers,
    users,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    firstStageId: number | null;
    leads: Option[];
    customers: Option[];
    users: Option[];
}) {
    const { data, setData, post, transform, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens, aimed at the first stage of the board.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = { ...EMPTY, stage_id: String(firstStageId ?? '') };
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, firstStageId]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // ⚠ Only the party picked travels: the server wants a lead OR a customer.
        transform((d) => ({
            name: d.name,
            lead_id: d.party_kind === 'lead' ? d.lead_id || null : null,
            customer_id: d.party_kind === 'customer' ? d.customer_id || null : null,
            stage_id: d.stage_id,
            value: d.value,
            expected_close_on: d.expected_close_on || null,
            assigned_to_user_id: d.assigned_to_user_id || null,
            next_action: d.next_action || null,
        }));
        post(route('crm.opportunities.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Add a deal</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Deal" htmlFor="deal-name" size="grow" error={errors.name} required>
                            <Input
                                id="deal-name"
                                aria-required
                                placeholder="e.g. Dealer opening order"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                        </Field>
                        <Field label="Value (BDT)" htmlFor="deal-value" size="sm" error={errors.value} required>
                            <Input
                                id="deal-value"
                                aria-required
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="0"
                                value={data.value}
                                onChange={(e) => setData('value', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Who" htmlFor="deal-party-kind" size="sm">
                            <Select value={data.party_kind} onValueChange={(value) => setData('party_kind', value)}>
                                <SelectTrigger id="deal-party-kind">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="lead">A lead</SelectItem>
                                    <SelectItem value="customer">A customer</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label={data.party_kind === 'lead' ? 'Lead' : 'Customer'}
                            htmlFor="deal-party"
                            error={errors.lead_id ?? errors.customer_id}
                            required
                        >
                            {data.party_kind === 'lead' ? (
                                <SearchableSelect
                                    id="deal-party"
                                    value={data.lead_id}
                                    onChange={(value) => setData('lead_id', value)}
                                    options={leads.map((lead) => ({ value: String(lead.id), label: lead.name }))}
                                    placeholder="Pick the lead"
                                    searchPlaceholder="Search leads…"
                                />
                            ) : (
                                <SearchableSelect
                                    id="deal-party"
                                    value={data.customer_id}
                                    onChange={(value) => setData('customer_id', value)}
                                    options={customers.map((customer) => ({ value: String(customer.id), label: customer.name }))}
                                    placeholder="Pick the customer"
                                    searchPlaceholder="Search customers…"
                                />
                            )}
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Expected close" htmlFor="deal-close" error={errors.expected_close_on}>
                            <Input
                                id="deal-close"
                                type="date"
                                value={data.expected_close_on}
                                onChange={(e) => setData('expected_close_on', e.target.value)}
                            />
                        </Field>
                        <Field label="Salesperson" htmlFor="deal-salesperson" size="md" error={errors.assigned_to_user_id}>
                            <Select value={data.assigned_to_user_id} onValueChange={(value) => setData('assigned_to_user_id', value)}>
                                <SelectTrigger id="deal-salesperson">
                                    <SelectValue placeholder="Unassigned" />
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
                        <Field label="Next action" htmlFor="deal-next" size="grow" error={errors.next_action}>
                            <Input
                                id="deal-next"
                                placeholder="e.g. call after Friday prayers"
                                value={data.next_action}
                                onChange={(e) => setData('next_action', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>Add deal</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
