import { ConfirmDialog } from '@/components/confirm-dialog';
import HeadingSmall from '@/components/heading-small';
import { OrgUnitDialog, type OrgUnitTarget } from '@/components/settings/org-unit-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { ReactNode, useState } from 'react';
import { toast } from 'sonner';

interface SectionUnit {
    id: number;
    name: string;
    is_active: boolean;
}

interface DepartmentUnit {
    id: number;
    name: string;
    is_active: boolean;
    sections: SectionUnit[];
}

interface OfficeUnit {
    id: number;
    name: string;
    code: string | null;
    is_active: boolean;
    departments: DepartmentUnit[];
    geofence_latitude: string | null;
    geofence_longitude: string | null;
    geofence_radius_meters: number | null;
    geofence_enforced: boolean;
}

interface Designation {
    id: number;
    name: string;
    is_active: boolean;
}

interface OrganizationProps {
    offices: OfficeUnit[];
    designations: Designation[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Organization', href: '/settings/organization' },
];

/**
 * Optional clock-in geofence per office (ATT-05). Enforcement is opt-in, and a
 * punch outside the radius is always accepted and flagged for review - never
 * rejected - so switching this on adds review work, it does not block anyone.
 */
function GeofenceRow({ office }: { office: OfficeUnit }) {
    const [latitude, setLatitude] = useState(office.geofence_latitude ?? '');
    const [longitude, setLongitude] = useState(office.geofence_longitude ?? '');
    const [radius, setRadius] = useState(office.geofence_radius_meters ? String(office.geofence_radius_meters) : '');
    const [enforced, setEnforced] = useState(office.geofence_enforced);

    const save = () => {
        router.put(
            route('organization.offices.geofence', office.id),
            {
                geofence_latitude: latitude === '' ? null : Number(latitude),
                geofence_longitude: longitude === '' ? null : Number(longitude),
                geofence_radius_meters: radius === '' ? null : Number(radius),
                geofence_enforced: enforced,
            },
            { ...visitOptions, onError, onSuccess: () => toast.success('Geofence updated.') },
        );
    };

    const useMyLocation = () => {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition((position) => {
            setLatitude(String(position.coords.latitude));
            setLongitude(String(position.coords.longitude));
        });
    };

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border grid gap-3 border-b py-3 last:border-b-0 sm:grid-cols-[1fr_auto]">
            <div className="grid gap-2">
                <div className="text-sm font-medium">{office.name}</div>
                <div className="flex flex-wrap items-center gap-2">
                    <Input className="w-36" placeholder="Latitude" value={latitude} onChange={(e) => setLatitude(e.target.value)} />
                    <Input className="w-36" placeholder="Longitude" value={longitude} onChange={(e) => setLongitude(e.target.value)} />
                    <Input className="w-28" placeholder="Radius (m)" value={radius} onChange={(e) => setRadius(e.target.value)} />
                    <Button variant="ghost" size="sm" onClick={useMyLocation}>
                        Use my location
                    </Button>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={enforced} onChange={(e) => setEnforced(e.target.checked)} />
                        Enforce
                    </label>
                </div>
            </div>
            <div className="flex items-start">
                <Button size="sm" variant="outline" onClick={save}>
                    Save
                </Button>
            </div>
        </div>
    );
}

// Keep the tree open across the Inertia round-trip after every mutation.
const visitOptions = { preserveState: true, preserveScroll: true } as const;
const onError = (errors: Record<string, string>) => toast.error(Object.values(errors)[0]);

function UnitRow({
    name,
    code = null,
    isActive,
    canDelete,
    deleteDescription,
    onRename,
    onToggle,
    onDelete,
    expandable = false,
    expanded = false,
    onToggleExpand,
    children,
}: {
    name: string;
    code?: string | null;
    isActive: boolean;
    canDelete: boolean;
    deleteDescription: string;
    // Opens the rename popup (14 Oct 2026); the row no longer turns into inputs.
    onRename: () => void;
    onToggle: () => void;
    onDelete: () => void;
    expandable?: boolean;
    expanded?: boolean;
    onToggleExpand?: () => void;
    children?: ReactNode;
}) {
    return (
        <div>
            <div className="flex flex-wrap items-center justify-between gap-2 py-2">
                <div className="flex items-center gap-2">
                    {expandable && (
                        <button type="button" onClick={onToggleExpand} className="text-muted-foreground hover:text-foreground">
                            <ChevronRight className={`size-4 transition-transform ${expanded ? 'rotate-90' : ''}`} />
                        </button>
                    )}
                    <span className={isActive ? '' : 'text-muted-foreground line-through'}>
                        {name}
                        {code && <span className="text-muted-foreground"> ({code})</span>}
                    </span>
                    {!isActive && (
                        <Badge variant="outline" className="text-muted-foreground">
                            Inactive
                        </Badge>
                    )}
                </div>
                <div className="flex items-center gap-1">
                    <Button size="sm" variant="ghost" onClick={onRename}>
                        Rename
                    </Button>
                    <Button size="sm" variant="ghost" onClick={onToggle}>
                        {isActive ? 'Deactivate' : 'Reactivate'}
                    </Button>
                    {canDelete && (
                        <ConfirmDialog
                            trigger={
                                <Button size="sm" variant="ghost">
                                    Delete
                                </Button>
                            }
                            title={`Delete "${name}"?`}
                            description={deleteDescription}
                            confirmLabel="Delete"
                            destructive
                            onConfirm={onDelete}
                        />
                    )}
                </div>
            </div>
            {expandable && expanded && <div className="border-sidebar-border/70 dark:border-sidebar-border ml-2 border-l pl-4">{children}</div>}
        </div>
    );
}

export default function Organization({ offices, designations }: OrganizationProps) {
    const [openOffices, setOpenOffices] = useState<Set<number>>(new Set());
    const [openDepartments, setOpenDepartments] = useState<Set<number>>(new Set());
    // ⭐ Add and Rename open one small popup (Joy, 14 Oct 2026: "click add button
    // pop up will add, same when edit"). `undefined` = closed.
    const [dialog, setDialog] = useState<OrgUnitTarget | undefined>(undefined);

    const toggleIn = (setter: typeof setOpenOffices, id: number) =>
        setter((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });

    const patch = (routeName: string, id: number) => router.patch(route(routeName, id), {}, visitOptions);
    const destroy = (routeName: string, id: number) => router.delete(route(routeName, id), visitOptions);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Organization" />

            <SettingsLayout>
                <div className="space-y-10">
                    <HeadingSmall title="Organization" />

                    <section className="space-y-3">
                        {/* No permission check of its own: the whole page needs the
                            same one as saving (foundation.organization.manage). */}
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h3 className="text-sm font-medium">Offices</h3>
                            <Button size="sm" onClick={() => setDialog({ kind: 'office', unit: null })}>
                                Add office
                            </Button>
                        </div>

                        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                            {offices.length === 0 ? (
                                <p className="text-muted-foreground py-2 text-sm">No offices yet.</p>
                            ) : (
                                offices.map((office) => (
                                    <UnitRow
                                        key={office.id}
                                        name={office.name}
                                        code={office.code}
                                        isActive={office.is_active}
                                        canDelete={office.departments.length === 0}
                                        deleteDescription="This office has no departments. Deleting it cannot be undone."
                                        onRename={() => setDialog({ kind: 'office', unit: office })}
                                        onToggle={() => patch('organization.offices.toggle', office.id)}
                                        onDelete={() => destroy('organization.offices.destroy', office.id)}
                                        expandable
                                        expanded={openOffices.has(office.id)}
                                        onToggleExpand={() => toggleIn(setOpenOffices, office.id)}
                                    >
                                        <div className="space-y-1 py-1">
                                            {office.departments.map((department) => (
                                                <UnitRow
                                                    key={department.id}
                                                    name={department.name}
                                                    isActive={department.is_active}
                                                    canDelete={department.sections.length === 0}
                                                    deleteDescription="This department has no sections. Deleting it cannot be undone."
                                                    onRename={() => setDialog({ kind: 'department', unit: department })}
                                                    onToggle={() => patch('organization.departments.toggle', department.id)}
                                                    onDelete={() => destroy('organization.departments.destroy', department.id)}
                                                    expandable
                                                    expanded={openDepartments.has(department.id)}
                                                    onToggleExpand={() => toggleIn(setOpenDepartments, department.id)}
                                                >
                                                    <div className="space-y-1 py-1">
                                                        {department.sections.map((section) => (
                                                            <UnitRow
                                                                key={section.id}
                                                                name={section.name}
                                                                isActive={section.is_active}
                                                                canDelete
                                                                deleteDescription="Deleting this section cannot be undone."
                                                                onRename={() => setDialog({ kind: 'section', unit: section })}
                                                                onToggle={() => patch('organization.sections.toggle', section.id)}
                                                                onDelete={() => destroy('organization.sections.destroy', section.id)}
                                                            />
                                                        ))}
                                                        <div className="pt-1">
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => setDialog({ kind: 'section', parent: department, unit: null })}
                                                            >
                                                                Add section
                                                            </Button>
                                                        </div>
                                                    </div>
                                                </UnitRow>
                                            ))}
                                            <div className="pt-1">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => setDialog({ kind: 'department', parent: office, unit: null })}
                                                >
                                                    Add department
                                                </Button>
                                            </div>
                                        </div>
                                    </UnitRow>
                                ))
                            )}
                        </div>
                    </section>

                    {offices.length > 0 && (
                        <section className="space-y-3">
                            <h3 className="text-sm font-medium">Clock-in geofence</h3>
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border px-3">
                                {offices.map((office) => (
                                    <GeofenceRow key={office.id} office={office} />
                                ))}
                            </div>
                        </section>
                    )}

                    <section className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h3 className="text-sm font-medium">Designations</h3>
                            <Button size="sm" onClick={() => setDialog({ kind: 'designation', unit: null })}>
                                Add designation
                            </Button>
                        </div>

                        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                            {designations.length === 0 ? (
                                <p className="text-muted-foreground py-2 text-sm">No designations yet.</p>
                            ) : (
                                designations.map((designation) => (
                                    <UnitRow
                                        key={designation.id}
                                        name={designation.name}
                                        isActive={designation.is_active}
                                        canDelete
                                        deleteDescription="Deleting this designation cannot be undone."
                                        onRename={() => setDialog({ kind: 'designation', unit: designation })}
                                        onToggle={() => patch('organization.designations.toggle', designation.id)}
                                        onDelete={() => destroy('organization.designations.destroy', designation.id)}
                                    />
                                ))
                            )}
                        </div>
                    </section>
                </div>

                <OrgUnitDialog open={dialog !== undefined} onOpenChange={(open) => !open && setDialog(undefined)} target={dialog ?? null} />
            </SettingsLayout>
        </AppLayout>
    );
}
