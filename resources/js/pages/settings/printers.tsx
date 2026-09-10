import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * The paper each counter prints on.
 *
 * ⭐ The screen is about PAPER, never about a make and model. Nothing here asks
 * which printer was bought, because nothing in the ERP needs to know: it needs
 * the width of the roll, how much of it the head can reach and how many dots
 * that head lays down per inch. Any ESC/POS receipt printer sold anywhere is
 * described by those three numbers.
 */

interface BarcodeCheck {
    fits: boolean;
    width_mm: number;
    required_mm: number;
    module_dots: number;
    module_mm: number;
    modules: number;
    reason: string | null;
}

interface Profile {
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
    warehouse: { id: number; name: string; code: string } | null;
    barcode_check: BarcodeCheck;
    module_dots: number;
}

interface Warehouse {
    id: number;
    name: string;
    code: string;
}

interface Preset {
    key: string;
    label: string;
    paper_width_mm: number;
    print_width_mm: number;
    dpi: number;
    base_font_pt: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Printers', href: '/settings/printers' }];

const blank = {
    warehouse_id: '' as string,
    name: '',
    paper_width_mm: 80,
    print_width_mm: 72,
    dpi: 203,
    base_font_pt: 8,
    line_height: 1.3,
    show_logo: false,
    show_barcode: true,
    footer_note: '',
    is_active: true,
};

function BarcodeVerdict({ check }: { check: BarcodeCheck }) {
    return (
        <p className={check.fits ? 'text-muted-foreground text-xs' : 'text-xs text-amber-600 dark:text-amber-500'}>
            {check.fits ? (
                <>
                    A typical invoice number prints {check.width_mm}mm wide, one bar being {check.module_dots} whole printer{' '}
                    {check.module_dots === 1 ? 'dot' : 'dots'} ({check.module_mm}mm). It fits with its quiet zones in {check.required_mm}mm.
                </>
            ) : (
                check.reason
            )}
        </p>
    );
}

function ProfileForm({
    profile,
    warehouses,
    presets,
    onDone,
}: {
    profile: Profile | null;
    warehouses: Warehouse[];
    presets: Preset[];
    onDone?: () => void;
}) {
    const { data, setData, post, put, errors, processing, reset } = useForm(
        profile
            ? {
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
              }
            : { ...blank },
    );

    const applyPreset = (preset: Preset) => {
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
            put(route('printers.update', profile.id), { onSuccess: () => onDone?.() });
            return;
        }

        post(route('printers.store'), {
            onSuccess: () => {
                reset();
                onDone?.();
            },
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4">
            <div className="flex flex-wrap gap-2">
                {presets.map((preset) => (
                    <Button key={preset.key} type="button" variant="outline" size="sm" onClick={() => applyPreset(preset)}>
                        {preset.label}
                    </Button>
                ))}
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="printer-name">Name</Label>
                    <Input
                        id="printer-name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="e.g. Front counter printer"
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="printer-outlet">Outlet</Label>
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
                    <InputError message={errors.warehouse_id} />
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-4">
                <div className="grid gap-2">
                    <Label htmlFor="paper-width">Paper width (mm)</Label>
                    <Input
                        id="paper-width"
                        type="number"
                        value={data.paper_width_mm}
                        onChange={(e) => setData('paper_width_mm', Number(e.target.value))}
                    />
                    <InputError message={errors.paper_width_mm} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="print-width">Printable width (mm)</Label>
                    <Input
                        id="print-width"
                        type="number"
                        value={data.print_width_mm}
                        onChange={(e) => setData('print_width_mm', Number(e.target.value))}
                    />
                    <p className="text-muted-foreground text-xs">An 80mm roll normally prints 72mm.</p>
                    <InputError message={errors.print_width_mm} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="dpi" required>
                        Head resolution (dpi)
                    </Label>
                    <Input id="dpi" type="number" value={data.dpi} onChange={(e) => setData('dpi', Number(e.target.value))} />
                    <p className="text-muted-foreground text-xs">Almost always 203. This is what sizes the barcode.</p>
                    <InputError message={errors.dpi} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="font-pt">Text size (pt)</Label>
                    <Input
                        id="font-pt"
                        type="number"
                        step="0.5"
                        value={data.base_font_pt}
                        onChange={(e) => setData('base_font_pt', Number(e.target.value))}
                    />
                    <InputError message={errors.base_font_pt} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="footer-note">Footer note (optional)</Label>
                <Input
                    id="footer-note"
                    value={data.footer_note}
                    onChange={(e) => setData('footer_note', e.target.value)}
                    placeholder="e.g. Goods once sold are exchangeable within 3 days with this slip"
                />
                <InputError message={errors.footer_note} />
            </div>

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

            <div className="flex items-center gap-2">
                <Button disabled={processing}>{profile ? 'Save changes' : 'Add printer profile'}</Button>
                {profile && onDone && (
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancel
                    </Button>
                )}
            </div>
        </form>
    );
}

function ProfileRow({ profile, warehouses, presets }: { profile: Profile; warehouses: Warehouse[]; presets: Preset[] }) {
    const [editing, setEditing] = useState(false);

    if (editing) {
        return <ProfileForm profile={profile} warehouses={warehouses} presets={presets} onDone={() => setEditing(false)} />;
    }

    return (
        <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4">
            <div className="grid gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{profile.name}</span>
                    <Badge variant={profile.warehouse ? 'secondary' : 'default'}>
                        {profile.warehouse ? profile.warehouse.name : 'Business default'}
                    </Badge>
                    {!profile.is_active && <Badge variant="outline">Not in use</Badge>}
                </div>
                <p className="text-muted-foreground text-sm">
                    {profile.paper_width_mm}mm paper, {profile.print_width_mm}mm printed, {profile.dpi} dpi, {profile.base_font_pt}pt text
                </p>
                <BarcodeVerdict check={profile.barcode_check} />
            </div>
            <div className="flex items-center gap-2">
                <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                    Edit
                </Button>
                <Button variant="ghost" size="sm" onClick={() => router.delete(route('printers.destroy', profile.id))}>
                    Remove
                </Button>
            </div>
        </div>
    );
}

export default function Printers({
    profiles,
    warehouses,
    presets,
    builtin,
}: {
    profiles: Profile[];
    warehouses: Warehouse[];
    presets: Preset[];
    builtin: { name: string; paper_width_mm: number; print_width_mm: number; dpi: number };
}) {
    const [adding, setAdding] = useState(false);
    const hasDefault = profiles.some((profile) => profile.warehouse_id === null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Printers" />

            <SettingsLayout>
                <div className="grid gap-6">
                    <HeadingSmall
                        title="Printers"
                        description="What the paper at each counter is. Receipts follow these numbers, so changing the machine is a change here and nothing else."
                    />

                    {!hasDefault && (
                        <div className="rounded-lg border border-amber-500/50 bg-amber-500/10 p-4 text-sm">
                            No business default is set, so every counter prints on the built-in profile: <strong>{builtin.name}</strong> (
                            {builtin.paper_width_mm}mm paper, {builtin.print_width_mm}mm printed, {builtin.dpi} dpi). Receipts work, but if your
                            machine is not that, describe it here.
                        </div>
                    )}

                    <div className="grid gap-3">
                        {profiles.map((profile) => (
                            <ProfileRow key={profile.id} profile={profile} warehouses={warehouses} presets={presets} />
                        ))}
                    </div>

                    {adding ? (
                        <ProfileForm profile={null} warehouses={warehouses} presets={presets} onDone={() => setAdding(false)} />
                    ) : (
                        <div>
                            <Button onClick={() => setAdding(true)}>Add a printer profile</Button>
                        </div>
                    )}

                    {/*
                      ⚠⚠ SAID HERE RATHER THAN STORED AS A SETTING. The ERP
                      prints through the browser, so it cannot cut the paper and
                      it cannot open the cash drawer - those are the printer
                      DRIVER's own settings on the counter PC. A switch on this
                      screen would be a switch that does nothing, which is worse
                      than no switch at all.
                    */}
                    <div className="grid gap-2 rounded-lg border p-4 text-sm">
                        <p className="font-medium">Two things this screen deliberately does not control</p>
                        <p className="text-muted-foreground">
                            <strong>Cutting the paper</strong> and <strong>opening the cash drawer</strong> are settings of the printer driver on the
                            counter computer, not of this system. Open the printer in Windows, go to Printing Preferences, and set the cutter and the
                            cash-drawer behaviour there. They are not offered here because a switch that changed nothing would be worse than none.
                        </p>
                        <p className="text-muted-foreground">
                            If you set the drawer to open on every print, remember it will also open when somebody reprints an old receipt, and when a
                            sale was paid by bKash or on account. The drawer cannot see who paid how.
                        </p>
                        <p className="text-muted-foreground">
                            To stop the print dialog appearing on every sale, start the browser on the counter machine with{' '}
                            <code className="bg-muted rounded px-1">--kiosk-printing</code> and make this printer the default.
                        </p>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
