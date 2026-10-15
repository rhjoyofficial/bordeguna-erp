import HeadingSmall from '@/components/heading-small';
import { PrinterProfileDialog, type PrinterPreset } from '@/components/settings/printer-profile-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Printers', href: '/settings/printers' },
];

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

function ProfileRow({ profile, onEdit }: { profile: Profile; onEdit: () => void }) {
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
                <Button variant="outline" size="sm" onClick={onEdit}>
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
    presets: PrinterPreset[];
    builtin: { name: string; paper_width_mm: number; print_width_mm: number; dpi: number };
}) {
    // ⭐ Add and Edit open one popup over the list (Joy, 14 Oct 2026: "click add
    // button pop up will add, same when edit"). `undefined` = closed, `null` = a new one.
    const [dialog, setDialog] = useState<Profile | null | undefined>(undefined);
    const hasDefault = profiles.some((profile) => profile.warehouse_id === null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Printers" />

            <SettingsLayout>
                <div className="grid gap-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <HeadingSmall title="Printers" />
                        {/* No permission check of its own: the whole page needs the
                            same one as saving (foundation.organization.manage). */}
                        <Button onClick={() => setDialog(null)}>Add a printer profile</Button>
                    </div>

                    {!hasDefault && (
                        <div className="rounded-lg border border-amber-500/50 bg-amber-500/10 p-4 text-sm">
                            No business default is set, so every counter prints on the built-in profile: <strong>{builtin.name}</strong> (
                            {builtin.paper_width_mm}mm paper, {builtin.print_width_mm}mm printed, {builtin.dpi} dpi). Receipts work, but if your
                            machine is not that, describe it here.
                        </div>
                    )}

                    <div className="grid gap-3">
                        {profiles.map((profile) => (
                            <ProfileRow key={profile.id} profile={profile} onEdit={() => setDialog(profile)} />
                        ))}
                    </div>

                    <PrinterProfileDialog
                        open={dialog !== undefined}
                        onOpenChange={(open) => !open && setDialog(undefined)}
                        profile={dialog ?? null}
                        warehouses={warehouses}
                        presets={presets}
                    />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
