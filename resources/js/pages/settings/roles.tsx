import { ConfirmDialog } from '@/components/confirm-dialog';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Role {
    id: number;
    name: string;
    is_system: boolean;
    locked_permissions: string[];
    permissions: string[];
    members_count: number;
    landing_page: string | null;
}

interface LandingOption {
    key: string;
    label: string;
}

interface RolesProps {
    roles: Role[];
    available_permissions: string[];
    landing_options: LandingOption[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Roles', href: '/settings/roles' },
];

const moduleOf = (permission: string) => permission.split('.')[0];
const prettyLabel = (permission: string) => permission.split('.').slice(1).join('.');

function groupPermissions(permissions: string[]): Record<string, string[]> {
    return permissions.reduce<Record<string, string[]>>((groups, permission) => {
        (groups[moduleOf(permission)] ??= []).push(permission);
        return groups;
    }, {});
}

function PermissionGrid({
    available,
    selected,
    locked = [],
    onToggle,
    idPrefix,
}: {
    available: string[];
    selected: string[];
    locked?: string[];
    onToggle: (permission: string) => void;
    idPrefix: string;
}) {
    const groups = groupPermissions(available);

    return (
        <div className="grid gap-5 sm:grid-cols-2">
            {Object.entries(groups).map(([module, permissions]) => (
                <div key={module} className="space-y-2">
                    <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">{module}</p>
                    <div className="space-y-1.5">
                        {permissions.map((permission) => {
                            const isLocked = locked.includes(permission);
                            return (
                                <label key={permission} htmlFor={`${idPrefix}-${permission}`} className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        id={`${idPrefix}-${permission}`}
                                        checked={selected.includes(permission) || isLocked}
                                        disabled={isLocked}
                                        onCheckedChange={() => onToggle(permission)}
                                    />
                                    {prettyLabel(permission)}
                                    {isLocked && <span className="text-muted-foreground text-xs">(required)</span>}
                                </label>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * Create a role in a popup (Joy, 14 Oct 2026: add and edit open from a button,
 * never a form sitting above the list). Same fields and route.
 */
function CreateRoleDialog({
    open,
    onOpenChange,
    availablePermissions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    availablePermissions: string[];
}) {
    const { data, setData, post, errors, processing, reset, clearErrors } = useForm<{ name: string; permissions: string[] }>({
        name: '',
        permissions: [],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('roles.store'), { preserveScroll: true, onSuccess: close });
    };

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const toggle = (permission: string) =>
        setData(
            'permissions',
            data.permissions.includes(permission) ? data.permissions.filter((p) => p !== permission) : [...data.permissions, permission],
        );

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Create a role</DialogTitle>
                    </DialogHeader>
                    <div className="grid max-w-sm gap-2">
                        <Label htmlFor="role-name" required>
                            Name
                        </Label>
                        <Input id="role-name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. HR Admin" />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Permissions</Label>
                        <PermissionGrid available={availablePermissions} selected={data.permissions} onToggle={toggle} idPrefix="new-role" />
                        <InputError message={errors.permissions} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>Create role</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RoleRow({ role, availablePermissions, landingOptions }: { role: Role; availablePermissions: string[]; landingOptions: LandingOption[] }) {
    const [editing, setEditing] = useState(false);
    // Seeded fresh each time edit opens, so re-editing never carries stale state.
    const [name, setName] = useState(role.name);
    const [permissions, setPermissions] = useState<string[]>(role.permissions);
    const [landingPage, setLandingPage] = useState<string>(role.landing_page ?? '');

    const startEdit = () => {
        setName(role.name);
        setPermissions(role.permissions);
        setLandingPage(role.landing_page ?? '');
        setEditing(true);
    };

    const toggle = (permission: string) =>
        setPermissions(permissions.includes(permission) ? permissions.filter((p) => p !== permission) : [...permissions, permission]);

    const save = () =>
        router.put(
            route('roles.update', role.id),
            { name, permissions, landing_page: landingPage === '' ? null : landingPage },
            { preserveScroll: true, onSuccess: () => setEditing(false) },
        );
    const remove = () => router.delete(route('roles.destroy', role.id));

    const canDelete = !role.is_system && role.members_count === 0;

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 border-b py-3 last:border-0">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <span className="font-medium">{role.name}</span>
                    {role.is_system && (
                        <Badge variant="outline" className="text-muted-foreground">
                            Built-in
                        </Badge>
                    )}
                    <span className="text-muted-foreground text-sm">
                        {role.members_count} {role.members_count === 1 ? 'member' : 'members'}
                    </span>
                </div>
                <div className="flex items-center gap-2">
                    <Button variant="ghost" size="sm" onClick={startEdit}>
                        Edit
                    </Button>
                    {canDelete && (
                        <ConfirmDialog
                            trigger={
                                <Button variant="ghost" size="sm">
                                    Delete
                                </Button>
                            }
                            title={`Delete the "${role.name}" role?`}
                            description="This role has no members. Deleting it cannot be undone."
                            confirmLabel="Delete"
                            destructive
                            onConfirm={remove}
                        />
                    )}
                </div>
            </div>

            <>
                <div className="flex flex-wrap gap-1.5">
                    {role.permissions.length > 0 ? (
                        role.permissions.map((permission) => (
                            <Badge key={permission} variant="secondary" className="font-normal">
                                {permission}
                            </Badge>
                        ))
                    ) : (
                        <span className="text-muted-foreground text-sm">No permissions</span>
                    )}
                </div>
                {role.landing_page && (
                    <p className="text-muted-foreground text-xs">
                        After login: {landingOptions.find((option) => option.key === role.landing_page)?.label ?? role.landing_page}
                    </p>
                )}
            </>

            {/* Edit opens a popup (14 Oct 2026), not a panel inside the row. */}
            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>Edit the {role.name} role</DialogTitle>
                    </DialogHeader>
                    <div className="flex flex-col gap-4">
                        {!role.is_system && (
                            <div className="grid max-w-sm gap-2">
                                <Label htmlFor={`role-name-${role.id}`} required>
                                    Name
                                </Label>
                                <Input id={`role-name-${role.id}`} value={name} onChange={(e) => setName(e.target.value)} />
                            </div>
                        )}
                        <PermissionGrid
                            available={availablePermissions}
                            selected={permissions}
                            locked={role.locked_permissions}
                            onToggle={toggle}
                            idPrefix={`edit-role-${role.id}`}
                        />
                        {/* C-LAND-2: what a holder of this role sees first after
                        login. Blank = the automatic defaults (owners the
                        dashboard, HR the HR dashboard, sellers the counter,
                        everyone else their own profile). Nobody is ever sent
                        to a page their permissions cannot open. */}
                        <div className="grid max-w-sm gap-2">
                            <Label htmlFor={`role-landing-${role.id}`}>After login, go to</Label>
                            <select
                                id={`role-landing-${role.id}`}
                                value={landingPage}
                                onChange={(e) => setLandingPage(e.target.value)}
                                className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                            >
                                <option value="">Automatic (by what this role can do)</option>
                                {landingOptions.map((option) => (
                                    <option key={option.key} value={option.key}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => setEditing(false)}>
                            Cancel
                        </Button>
                        <Button type="button" onClick={save} disabled={name.trim() === ''}>
                            Save
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default function Roles({ roles, available_permissions, landing_options }: RolesProps) {
    const [creating, setCreating] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Roles" />

            <SettingsLayout>
                <div className="space-y-8">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <HeadingSmall title="Roles" />
                        <Button type="button" onClick={() => setCreating(true)}>
                            Create a role
                        </Button>
                    </div>

                    <CreateRoleDialog open={creating} onOpenChange={setCreating} availablePermissions={available_permissions} />

                    <div>
                        <h3 className="text-muted-foreground mb-1 text-sm font-medium">Roles</h3>
                        {roles.map((role) => (
                            <RoleRow key={role.id} role={role} availablePermissions={available_permissions} landingOptions={landing_options} />
                        ))}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
