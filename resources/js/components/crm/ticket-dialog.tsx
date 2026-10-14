import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

interface Option {
    id: number;
    name: string;
}

const EMPTY = {
    subject: '',
    description: '',
    party_kind: 'customer',
    customer_id: '',
    caller_name: '',
    caller_phone: '',
    category: 'complaint',
    priority: 'normal',
    assigned_to_user_id: '',
};

/**
 * Open a customer service ticket in a popup (Joy, 14 Oct 2026: "click add
 * button pop up will add"). The form that sat open above the tickets is gone;
 * the list is the page.
 *
 * Same fields, same route, same server rules as before: the dialog only
 * changes where the form is drawn. The resolve-by clock is still stamped by
 * the server from the priority, and replying, resolving and reassigning stay
 * on each ticket's card.
 */
export function TicketDialog({
    open,
    onOpenChange,
    customers,
    users,
    slaHours,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    customers: Option[];
    users: Option[];
    slaHours: Record<string, number>;
}) {
    const { t } = useTranslation();
    const { data, setData, post, transform, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens.
    useEffect(() => {
        if (!open) {
            return;
        }

        setDefaults(EMPTY);
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
        // ⚠ Only the side picked travels: a customer on file OR a caller's name.
        transform((d) => ({
            subject: d.subject,
            description: d.description || null,
            customer_id: d.party_kind === 'customer' ? d.customer_id || null : null,
            caller_name: d.party_kind === 'walkin' ? d.caller_name || null : null,
            caller_phone: d.party_kind === 'walkin' ? d.caller_phone || null : null,
            category: d.category,
            priority: d.priority,
            assigned_to_user_id: d.assigned_to_user_id || null,
        }));
        post(route('crm.tickets.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{t('tickets.new.title')}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="What happened" htmlFor="ticket-subject" size="grow" error={errors.subject} required>
                            <Input
                                id="ticket-subject"
                                aria-required
                                placeholder="e.g. Broken jar in the carton"
                                value={data.subject}
                                onChange={(e) => setData('subject', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Who is complaining" htmlFor="ticket-party-kind" size="lg">
                            <Select value={data.party_kind} onValueChange={(value) => setData('party_kind', value)}>
                                <SelectTrigger id="ticket-party-kind">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="customer">A customer on file</SelectItem>
                                    <SelectItem value="walkin">Walk-in / phone caller</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        {data.party_kind === 'customer' ? (
                            <Field label="Customer" htmlFor="ticket-customer" error={errors.customer_id} required>
                                <SearchableSelect
                                    id="ticket-customer"
                                    value={data.customer_id}
                                    onChange={(value) => setData('customer_id', value)}
                                    options={customers.map((customer) => ({ value: String(customer.id), label: customer.name }))}
                                    placeholder="Pick the customer"
                                    searchPlaceholder="Search customers…"
                                />
                            </Field>
                        ) : (
                            <>
                                <Field label="Name" htmlFor="ticket-caller-name" size="md" error={errors.caller_name} required>
                                    <Input
                                        id="ticket-caller-name"
                                        aria-required
                                        value={data.caller_name}
                                        onChange={(e) => setData('caller_name', e.target.value)}
                                    />
                                </Field>
                                <Field label="Phone" htmlFor="ticket-caller-phone" size="md" error={errors.caller_phone}>
                                    <Input
                                        id="ticket-caller-phone"
                                        value={data.caller_phone}
                                        onChange={(e) => setData('caller_phone', e.target.value)}
                                    />
                                </Field>
                            </>
                        )}
                    </FormRow>

                    <FormRow>
                        <Field label="Category" htmlFor="ticket-category" size="md" error={errors.category} required>
                            <Select value={data.category} onValueChange={(value) => setData('category', value)}>
                                <SelectTrigger id="ticket-category">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="complaint">Complaint</SelectItem>
                                    <SelectItem value="quality">Product quality</SelectItem>
                                    <SelectItem value="delivery">Delivery</SelectItem>
                                    <SelectItem value="question">Question</SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Priority" htmlFor="ticket-priority" size="lg" error={errors.priority} required>
                            <Select value={data.priority} onValueChange={(value) => setData('priority', value)}>
                                <SelectTrigger id="ticket-priority">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(slaHours).map(([priority, hours]) => (
                                        <SelectItem key={priority} value={priority}>
                                            {priority} ({hours}h to resolve)
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Assign to" htmlFor="ticket-assignee" size="md" error={errors.assigned_to_user_id}>
                            <Select value={data.assigned_to_user_id} onValueChange={(value) => setData('assigned_to_user_id', value)}>
                                <SelectTrigger id="ticket-assignee">
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
                    </FormRow>

                    <div className="grid gap-2">
                        <Label htmlFor="ticket-description">Details</Label>
                        <textarea
                            id="ticket-description"
                            rows={3}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            placeholder="what, when, which delivery…"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            {t('tickets.new.cancel')}
                        </Button>
                        <Button disabled={processing}>Open ticket</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
