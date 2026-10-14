import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogPrinterProfile {
    id: number;
    warehouse_id: number | null;
    name: string;
    paper_width_mm: number;
    print_width_mm: number;
    dpi: number;
    base_font_pt: number;
    line_height: number;
    show_logo: boolean;
    show_barcode: boolean;
    footer_note: string | null;
    is_active: boolean;
}

export interface PrinterPreset {
    key: string;
    label: string;
    paper_width_mm: number;
    print_width_mm: number;
    dpi: number;
    base_font_pt: number;
}

const EMPTY = {
    warehouse_id: '' as string,
    name: '',
    paper_width_mm: 80,
    print_width_mm: 72,
    dpi: 203,
    base_font_pt: 8,
    line_height: 1.3,
    show_logo: false as boolean,
    show_barcode: true as boolean,
    footer_note: '',
    is_active: true as boolean,
};

function fromProfile(profile: DialogPrinterProfile): typeof EMPTY {
    return {
        warehouse_id: profile.warehouse_id === null ? '' : String(profile.warehouse_id),
        name: profile.name,
        paper_width_mm: profile.paper_width_mm,
        print_width_mm: profile.print_width_mm,
        dpi: profile.dpi,
        base_font_pt: profile.base_font_pt,
        line_height: profile.line_height,
        show_logo: profile.show_logo,
        show_barcode: profile.show_barcode,
        footer_note: profile.footer_note ?? '',
        is_active: profile.is_active,
    };
}

/**
 * Describe the paper at a counter, or correct one, in one popup (Joy, 14 Oct
 * 2026: "click add button pop up will add, same when edit"). The form used to
 * open below the list for a new profile and replace the card for an edit.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn. One business default and one profile per
 * outlet are still refused by the server, and the refusal shows under Outlet.
 */
export function PrinterProfileDialog({
    open,
    onOpenChange,
    profile,
    warehouses,
    presets,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    profile: DialogPrinterProfile | null;
    warehouses: { id: number; name: string; code: string }[];
    presets: PrinterPreset[];
}) {
    const editing = profile !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: the 80mm defaults for a new profile, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = profile ? fromProfile(profile) : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, profile]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const applyPreset = (preset: PrinterPreset) => {
        setData((current) => ({
            ...current,
            paper_width_mm: preset.paper_width_mm,
            print_width_mm: preset.print_width_mm,
            dpi: preset.dpi,
            base_font_pt: preset.base_font_pt,
            name: current.name || preset.label,
        }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (profile) {
            put(route('printers.update', profile.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('printers.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${profile.name}` : 'Add a printer profile'}</DialogTitle>
                    </DialogHeader>

                    <div className="flex flex-wrap gap-2">
                        {presets.map((preset) => (
                            <Button key={preset.key} type="button" variant="outline" size="sm" onClick={() => applyPreset(preset)}>
                                {preset.label}
                            </Button>
                        ))}
                    </div>

                    <FormRow>
                        <Field label="Name" htmlFor="printer-name" size="grow" error={errors.name} required>
                            <Input
                                id="printer-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Front counter printer"
                            />
                        </Field>
                        <Field label="Outlet" htmlFor="printer-outlet" error={errors.warehouse_id}>
                            {/*
                              ⚠ A native select on purpose: the list is short, and this
                              is the one field whose meaning has to be unmistakable -
                              "no outlet" is not an empty value here, it is the business
                              default every counter falls back to.
                            */}
                            <select
                                id="printer-outlet"
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                value={data.warehouse_id}
                                onChange={(e) => setData('warehouse_id', e.target.value)}
                            >
                                <option value="">The whole business (default profile)</option>
                                {warehouses.map((warehouse) => (
                                    <option key={warehouse.id} value={String(warehouse.id)}>
                                        {warehouse.name} ({warehouse.code})
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </FormRow>

                    {/* Four small numbers, each box just wide enough for its label. */}
                    <FormRow>
                        <Field label="Paper width (mm)" htmlFor="paper-width" className="w-40" error={errors.paper_width_mm} required>
                            <Input
                                id="paper-width"
                                aria-required
                                type="number"
                                value={data.paper_width_mm}
                                onChange={(e) => setData('paper_width_mm', Number(e.target.value))}
                            />
                        </Field>
                        <Field label="Printable width (mm)" htmlFor="print-width" className="w-40" error={errors.print_width_mm} required>
                            <Input
                                id="print-width"
                                aria-required
                                type="number"
                                value={data.print_width_mm}
                                onChange={(e) => setData('print_width_mm', Number(e.target.value))}
                            />
                        </Field>
                        <Field label="Head resolution (dpi)" htmlFor="dpi" className="w-40" error={errors.dpi} required>
                            <Input id="dpi" aria-required type="number" value={data.dpi} onChange={(e) => setData('dpi', Number(e.target.value))} />
                        </Field>
                        <Field label="Text size (pt)" htmlFor="font-pt" className="w-40" error={errors.base_font_pt} required>
                            <Input
                                id="font-pt"
                                aria-required
                                type="number"
                                step="0.5"
                                value={data.base_font_pt}
                                onChange={(e) => setData('base_font_pt', Number(e.target.value))}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Footer note (optional)" htmlFor="footer-note" size="grow" error={errors.footer_note}>
                            <Input
                                id="footer-note"
                                value={data.footer_note}
                                onChange={(e) => setData('footer_note', e.target.value)}
                                placeholder="e.g. Goods once sold are exchangeable within 3 days with this slip"
                            />
                        </Field>
                    </FormRow>

                    <div className="flex flex-wrap items-center gap-6">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.show_barcode} onCheckedChange={(v) => setData('show_barcode', v === true)} />
                            Print the barcode
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.show_logo} onCheckedChange={(v) => setData('show_logo', v === true)} />
                            Print the logo
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', v === true)} />
                            In use
                        </label>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save changes' : 'Add printer profile'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
