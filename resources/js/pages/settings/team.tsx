import { ConfirmDialog } from '@/components/confirm-dialog';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { StockReasonDialog } from '@/components/inventory/stock-reason-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Member {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    is_super_admin: boolean;
    roles: string[];
    is_self: boolean;
    // Set only while an unused set-password link is outstanding. Laravel
    // deletes the token the moment it is used, so null means either "never
    // sent" or "already used to set a password".
    password_link_sent_at: string | null;
    password_link_expired: boolean;
    // ⭐ Corrections wave 3 (24 Oct 2026).
    signed_in: boolean;
    pending_email: string | null;
    delete_blocked: string | null;
    deactivate_blocked: string | null;
}

type ReasonOption = { id: number; name: string };

type Reasons = { deactivation: ReasonOption[]; restore: ReasonOption[]; deletion: ReasonOption[]; fix_details: ReasonOption[] };

interface TeamProps {
    members: Member[];
    assignable_roles: string[];
    password_link_lifetime: string;
    is_owner: boolean;
    reasons: Reasons;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Team', href: '/settings/team' },
];

function RolePicker({
    available,
    selected,
    onToggle,
    idPrefix,
}: {
    available: string[];
    selected: string[];
    onToggle: (role: string) => void;
    idPrefix: string;
}) {
    return (
        <div className="flex flex-wrap gap-3">
            {available.map((role) => (
                <label key={role} htmlFor={`${idPrefix}-${role}`} className="flex items-center gap-2 text-sm">
                    <Checkbox id={`${idPrefix}-${role}`} checked={selected.includes(role)} onCheckedChange={() => onToggle(role)} />
                    {role}
                </label>
            ))}
        </div>
    );
}

/**
 * Add a team member in a popup (Joy, 14 Oct 2026: add and edit open from a
 * button, never a form sitting above the list). Same fields and route.
 */
function AddMemberDialog({
    open,
    onOpenChange,
    assignableRoles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignableRoles: string[];
}) {
    const { data, setData, post, errors, processing, reset, clearErrors } = useForm<{
        name: string;
        email: string;
        password: string;
        roles: string[];
    }>({
        name: '',
        email: '',
        password: '',
        roles: [],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('users.store'), { preserveScroll: true, onSuccess: close });
    };

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const toggleRole = (role: string) => setData('roles', data.roles.includes(role) ? data.roles.filter((r) => r !== role) : [...data.roles, role]);

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Add a team member</DialogTitle>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="name" required>
                                Name
                            </Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Full name" />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="email" required>
                                Email
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="email@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password" required>
                                Initial password
                            </Label>
                            <PasswordInput
                                id="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Set an initial password"
                            />
                            <InputError message={errors.password} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label required>Roles</Label>
                        <RolePicker available={assignableRoles} selected={data.roles} onToggle={toggleRole} idPrefix="new" />
                        <InputError message={errors.roles} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>Add member</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MemberRow({
    member,
    assignableRoles,
    linkLifetime,
    isOwner,
    reasons,
}: {
    member: Member;
    assignableRoles: string[];
    linkLifetime: string;
    isOwner: boolean;
    reasons: Reasons;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const [fixName, setFixName] = useState(member.name);
    const [fixEmail, setFixEmail] = useState(member.email);
    // Seeded from the current member each time edit opens (not once at mount),
    // so re-editing after an update never carries a stale role set.
    const [roles, setRoles] = useState<string[]>(member.roles);

    const startEdit = () => {
        setRoles(member.roles);
        setEditing(true);
    };

    const toggleRole = (role: string) => setRoles(roles.includes(role) ? roles.filter((r) => r !== role) : [...roles, role]);

    const save = () => router.put(route('users.update', member.id), { roles }, { preserveScroll: true, onSuccess: () => setEditing(false) });
    const remove = () => router.delete(route('users.destroy', member.id));
    const sendPasswordLink = () => router.post(route('users.password-link', member.id));

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 border-b py-3 last:border-0">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="font-medium">
                        {member.name} {member.is_self && <span className="text-muted-foreground text-xs">(you)</span>}
                        {!member.is_active && (
                            <Badge variant="outline" className="text-muted-foreground ml-2">
                                Deactivated
                            </Badge>
                        )}
                    </p>
                    <p className="text-muted-foreground text-sm">{member.email}</p>
                    {member.pending_email && (
                        <p className="text-xs text-amber-600">{t('corr.assets.pending_email', { email: member.pending_email })}</p>
                    )}
                    {member.password_link_sent_at && (
                        <p className={`text-xs ${member.password_link_expired ? 'text-destructive' : 'text-muted-foreground'}`}>
                            {member.password_link_expired
                                ? `Password link sent ${showDate(member.password_link_sent_at)} has EXPIRED and was never used. Send another.`
                                : `Password link sent ${showDate(member.password_link_sent_at)}, not used yet (valid ${linkLifetime}).`}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {member.roles.length > 0 ? (
                        member.roles.map((role) => (
                            <Badge key={role} variant="secondary">
                                {role}
                            </Badge>
                        ))
                    ) : (
                        <span className="text-muted-foreground text-sm">No roles</span>
                    )}
                    <Button variant="ghost" size="sm" onClick={startEdit}>
                        Edit roles
                    </Button>
                    <ConfirmDialog
                        trigger={
                            <Button variant="ghost" size="sm">
                                {member.password_link_sent_at ? 'Resend password link' : 'Send password link'}
                            </Button>
                        }
                        title={`Email a password link to ${member.name}?`}
                        description={`An email goes to ${member.email} with a link where they choose their own password. The link is valid for ${linkLifetime} and can only be used once. Nobody here sees or types their password.`}
                        confirmLabel="Send link"
                        onConfirm={sendPasswordLink}
                    />
                    {!member.is_self && (
                        <>
                            {/* ⭐ Corrections wave 3 (24 Oct 2026): fix a typo'd name or email,
                                Deactivate and Reactivate with a reason, Delete login when it
                                never signed in (else greyed with the sentence). */}
                            {(!member.signed_in || isOwner) && (
                                <StockReasonDialog
                                    trigger={
                                        <Button variant="ghost" size="sm">
                                            {t('corr.assets.fix_email')}
                                        </Button>
                                    }
                                    title={t('corr.assets.fix_email_title', { name: member.name })}
                                    description={member.signed_in ? t('corr.assets.fix_email_after') : t('corr.assets.fix_email_before')}
                                    confirmLabel={t('corr.assets.save')}
                                    reasons={reasons.fix_details}
                                    url={route('users.email', member.id)}
                                    data={{ name: fixName, email: fixEmail }}
                                    ready={fixName.trim() !== '' && fixEmail.trim() !== ''}
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor={`fix-name-${member.id}`} required>
                                            {t('corr.assets.name')}
                                        </Label>
                                        <Input id={`fix-name-${member.id}`} value={fixName} onChange={(e) => setFixName(e.target.value)} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`fix-email-${member.id}`} required>
                                            {t('corr.assets.email')}
                                        </Label>
                                        <Input
                                            id={`fix-email-${member.id}`}
                                            type="email"
                                            value={fixEmail}
                                            onChange={(e) => setFixEmail(e.target.value)}
                                        />
                                    </div>
                                </StockReasonDialog>
                            )}
                            {member.is_active && member.deactivate_blocked ? (
                                <Button variant="ghost" size="sm" disabled title={member.deactivate_blocked}>
                                    {t('corr.assets.deactivate')}
                                </Button>
                            ) : (
                                <StockReasonDialog
                                    trigger={
                                        <Button variant="ghost" size="sm">
                                            {member.is_active ? t('corr.assets.deactivate') : t('corr.assets.reactivate')}
                                        </Button>
                                    }
                                    title={
                                        member.is_active
                                            ? t('corr.assets.deactivate_title', { name: member.name })
                                            : t('corr.assets.reactivate_title', { name: member.name })
                                    }
                                    description={member.is_active ? t('corr.assets.deactivate_note') : undefined}
                                    confirmLabel={member.is_active ? t('corr.assets.deactivate') : t('corr.assets.reactivate')}
                                    reasons={member.is_active ? reasons.deactivation : reasons.restore}
                                    url={route('users.toggle-active', member.id)}
                                    method="patch"
                                    destructive={member.is_active}
                                />
                            )}
                            {member.delete_blocked ? (
                                <Button variant="ghost" size="sm" disabled title={member.delete_blocked}>
                                    {t('corr.assets.delete_login')}
                                </Button>
                            ) : (
                                <StockReasonDialog
                                    trigger={
                                        <Button variant="ghost" size="sm" className="text-destructive">
                                            {t('corr.assets.delete_login')}
                                        </Button>
                                    }
                                    title={t('corr.assets.to_bin', { name: member.name })}
                                    description={t('corr.assets.delete_login_note')}
                                    confirmLabel={t('corr.assets.delete_login')}
                                    reasons={reasons.deletion}
                                    url={route('users.delete-login', member.id)}
                                    destructive
                                />
                            )}
                            <ConfirmDialog
                                trigger={
                                    <Button variant="ghost" size="sm">
                                        Remove
                                    </Button>
                                }
                                title={`Remove ${member.name} from the team?`}
                                description="They lose their roles and access to this business. Their account and history are kept, and they can be re-added later."
                                confirmLabel="Remove"
                                destructive
                                onConfirm={remove}
                            />
                        </>
                    )}
                </div>
            </div>
            {/* Edit roles opens a popup (14 Oct 2026), not a panel inside the row. */}
            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Roles for {member.name}</DialogTitle>
                        <DialogDescription>{member.email}</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label required>Roles</Label>
                        <RolePicker available={assignableRoles} selected={roles} onToggle={toggleRole} idPrefix={`edit-${member.id}`} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => setEditing(false)}>
                            Cancel
                        </Button>
                        <Button type="button" onClick={save} disabled={roles.length === 0}>
                            Save roles
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default function Team({ members, assignable_roles, password_link_lifetime, is_owner, reasons }: TeamProps) {
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Team" />

            <SettingsLayout>
                <div className="space-y-8">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <HeadingSmall title="Team" />
                        <Button type="button" onClick={() => setAdding(true)}>
                            Add a team member
                        </Button>
                    </div>

                    <AddMemberDialog open={adding} onOpenChange={setAdding} assignableRoles={assignable_roles} />

                    <div>
                        <h3 className="text-muted-foreground mb-1 text-sm font-medium">Members</h3>
                        {members.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No members yet.</p>
                        ) : (
                            members.map((member) => (
                                <MemberRow
                                    key={member.id}
                                    member={member}
                                    assignableRoles={assignable_roles}
                                    linkLifetime={password_link_lifetime}
                                    isOwner={is_owner}
                                    reasons={reasons}
                                />
                            ))
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
