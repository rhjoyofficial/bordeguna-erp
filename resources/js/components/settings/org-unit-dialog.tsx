import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export type OrgUnitKind = 'office' | 'department' | 'section' | 'designation';

/**
 * What the popup is for: a new unit (`unit: null`) under `parent`, or a rename
 * of `unit`. A department lives in an office and a section in a department;
 * offices and designations have no parent.
 */
export interface OrgUnitTarget {
    kind: OrgUnitKind;
    parent?: { id: number; name: string };
    unit: { id: number; name: string; code?: string | null } | null;
}

const PLACEHOLDER: Record<OrgUnitKind, string> = {
    office: 'e.g. Head Office',
    department: 'e.g. Finance',
    section: 'e.g. Payroll',
    designation: 'e.g. Senior Officer',
};

/**
 * Add an office, a department, a section or a designation, or rename one, in
 * one small popup (Joy, 14 Oct 2026: "click add button pop up will add, same
 * when edit"). Before this every list had an add box under it and Rename
 * turned the row into inputs.
 *
 * Same fields, same routes, same server rules: each kind posts exactly what
 * its add box or rename sent before (a name; a code too for an office; the
 * parent's id for a new department or section). A repeated name comes back
 * under the box instead of as a toast.
 */
export function OrgUnitDialog({
    open,
    onOpenChange,
    target,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    target: OrgUnitTarget | null;
}) {
    const kind = target?.kind ?? 'office';
    const unit = target?.unit ?? null;
    const withCode = kind === 'office';
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults, transform } = useForm({ name: '', code: '' });

    // Fresh fields every time it opens: blank for a new unit, filled for a rename.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = { name: unit?.name ?? '', code: unit?.code ?? '' };
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, target]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (!target) {
            return;
        }

        // ⚠ Only what this kind takes: a code belongs to an office alone, and the
        // parent's id is sent only when a department or section is created.
        transform((form) => ({
            name: form.name,
            ...(withCode ? { code: form.code } : {}),
            ...(!unit && kind === 'department' && target.parent ? { office_id: target.parent.id } : {}),
            ...(!unit && kind === 'section' && target.parent ? { department_id: target.parent.id } : {}),
        }));

        const options = { preserveState: true, preserveScroll: true, onSuccess: close };

        if (unit) {
            put(route(`organization.${kind}s.update`, unit.id), options);

            return;
        }

        post(route(`organization.${kind}s.store`), options);
    };

    const title = unit
        ? `Rename ${unit.name}`
        : target?.parent
          ? `Add a ${kind} to ${target.parent.name}`
          : `Add ${kind === 'office' ? 'an' : 'a'} ${kind}`;

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name" htmlFor="org-unit-name" size="lg" error={errors.name} required>
                            <Input
                                id="org-unit-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder={PLACEHOLDER[kind]}
                                autoFocus
                            />
                        </Field>
                        {withCode && (
                            <Field label="Code" htmlFor="org-unit-code" size="sm" error={errors.code}>
                                <Input id="org-unit-code" value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. HO" />
                            </Field>
                        )}
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{unit ? 'Save' : `Add ${kind}`}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
