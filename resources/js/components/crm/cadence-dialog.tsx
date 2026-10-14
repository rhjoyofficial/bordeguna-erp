import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

const EMPTY = {
    name: '',
    trigger: 'lead_created',
    delay_days: '1',
    task_title: '',
};

/**
 * Start a follow-up cadence in a popup (Joy, 14 Oct 2026: "click add button
 * pop up will add"). The form that sat open above the cadences is gone; the
 * table is the page.
 *
 * Same fields, same route, same server rules as before: the dialog only
 * changes where the form is drawn. There is still no edit: a cadence is
 * paused or resumed from its row, never rewritten or deleted.
 */
export function CadenceDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const { data, setData, post, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

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
        post(route('crm.cadences.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Start a cadence</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name" htmlFor="cadence-name" size="md" error={errors.name} required>
                            <Input
                                id="cadence-name"
                                aria-required
                                placeholder="e.g. First call"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                        </Field>
                        <Field label="Rhythm" htmlFor="cadence-trigger" size="md" error={errors.trigger} required>
                            <Select value={data.trigger} onValueChange={(value) => setData('trigger', value)}>
                                <SelectTrigger id="cadence-trigger">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="lead_created">New lead arrives</SelectItem>
                                    <SelectItem value="customer_dormant">Customer goes quiet</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label={data.trigger === 'lead_created' ? 'Task due after (days)' : 'Quiet for (days)'}
                            htmlFor="cadence-days"
                            size="md"
                            error={errors.delay_days}
                            required
                        >
                            <Input
                                id="cadence-days"
                                aria-required
                                type="number"
                                min="1"
                                max="365"
                                value={data.delay_days}
                                onChange={(e) => setData('delay_days', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Task says" htmlFor="cadence-task" size="grow" error={errors.task_title} required>
                            <Input
                                id="cadence-task"
                                aria-required
                                placeholder="e.g. Call the new lead"
                                value={data.task_title}
                                onChange={(e) => setData('task_title', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>Start cadence</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
