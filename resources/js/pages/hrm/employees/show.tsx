import { EmployeeCashCard, type EmployeeCashAccount } from '@/components/accounting/employee-cash-card';
import { HrCorrectionActions, type HrActions } from '@/components/hrm/hr-correction-actions';
import { EmployeeMoneyPanel, type EmployeeMoneyData } from '@/components/lifetime/employee-money';
import { CorrectionReasonDialog, type ReasonOption } from '@/components/purchasing/correction-actions';
import RecordTimeline, { type TimelineEntry } from '@/components/record-timeline';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';

interface Option {
    value: string;
    label: string;
}

interface NamedOption {
    id: number;
    name: string;
}

interface OfficeNode {
    id: number;
    name: string;
    departments: { id: number; name: string; sections: NamedOption[] }[];
}

interface Assignment {
    id: number;
    is_primary: boolean;
    office: string | null;
    department: string | null;
    section: string | null;
    designation: string | null;
    supervisor: string | null;
    effective_from: string | null;
    effective_to: string | null;
}

interface Document {
    id: number;
    category: string;
    original_name: string;
    size: number;
    expires_at: string | null;
    is_expired: boolean;
    uploaded_by: string | null;
    url: string;
}

interface EmployeeDetail {
    photo_url: string | null;
    has_signature: boolean;
    referred_by: string | null;
    id: number;
    employee_code: string;
    name: string;
    employment_type: string;
    may_clock_in_remotely: boolean;
    remote_clock_in_granted_by: string | null;
    remote_clock_in_granted_at: string | null;
    remote_clock_in_reason: string | null;
    status: string;
    separation_type: string | null;
    separation_date: string | null;
    separation_reason: string | null;
    personal_email: string | null;
    phone: string | null;
    present_address: string | null;
    permanent_address: string | null;
    national_id: string | null;
    birth_certificate_no: string | null;
    date_of_birth: string | null;
    joining_date: string | null;
    confirmation_date: string | null;
    contract_end_date: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    emergency_contact_relationship: string | null;
    bank_account_name: string | null;
    bank_account_number: string | null;
    bank_name: string | null;
    bank_branch: string | null;
    bank_routing_number: string | null;
    mfs_provider: string | null;
    mfs_account_number: string | null;
    user: {
        id: number;
        name: string;
        email: string;
        is_active: boolean;
        // Set only while a set-password link is outstanding and unused. It
        // going null is how the screen says "they have set their password".
        password_link_sent_at: string | null;
        password_link_expired: boolean;
    } | null;
    assignments: Assignment[];
    documents: Document[];
}

interface ShowProps {
    /** Initial plan B3 (R63): company cash this employee holds or is owed. */
    employee_cash?: EmployeeCashAccount[];
    timeline?: TimelineEntry[];
    /** ⭐ B23 (25 Oct 2026; R77): the money tab, each part null when the reader may not see it. */
    money?: EmployeeMoneyData;
    employee: EmployeeDetail;
    transitionable_statuses: Option[];
    can_confirm: boolean;
    probation_due: string | null;
    can_separate: boolean;
    separation_types: Option[];
    can_transfer: boolean;
    offices_tree: OfficeNode[];
    designations: NamedOption[];
    supervisors: NamedOption[];
    document_types: Option[];
    can_manage_login: boolean;
    password_link_lifetime: string;
    // ⭐ Corrections wave 3 (24 Oct 2026): Delete a draft, Undo separation.
    corrections?: { actions: HrActions; reasons: Record<string, ReasonOption[]> };
}

const statusVariant: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    draft: 'secondary',
    active: 'default',
    on_leave: 'outline',
    suspended: 'destructive',
    separated: 'destructive',
};

function Field({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm">{value ?? '-'}</dd>
        </div>
    );
}

/**
 * The face at the top of the file (Joy, 23 Aug 2026: "can add image"). Same
 * mechanics as the business logo: a singleton attachment, replaced rather than
 * accumulated, deliberately separate from the documents list below because a
 * face is not a certificate.
 */
function SignatureUpload({ employeeId, hasSignature }: { employeeId: number; hasSignature: boolean }) {
    const fileRef = useRef<HTMLInputElement>(null);

    return (
        <span className="inline-flex items-center gap-1">
            <input
                ref={fileRef}
                type="file"
                accept="image/png,image/webp"
                className="hidden"
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (!file) return;
                    const form = new FormData();
                    form.append('signature', file);
                    router.post(route('hrm.employees.signature.store', employeeId), form, {
                        forceFormData: true,
                        preserveScroll: true,
                        onFinish: () => {
                            if (fileRef.current) fileRef.current.value = '';
                        },
                    });
                }}
            />
            <Button variant="outline" size="sm" onClick={() => fileRef.current?.click()}>
                {hasSignature ? 'Replace signature' : 'Upload signature'}
            </Button>
            {hasSignature && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-destructive"
                    onClick={() => router.delete(route('hrm.employees.signature.destroy', employeeId), { preserveScroll: true })}
                >
                    Remove
                </Button>
            )}
        </span>
    );
}

function ProfilePhoto({ employeeId, photoUrl, name }: { employeeId: number; photoUrl: string | null; name: string }) {
    const fileRef = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);

    const initials = name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');

    const upload = (file?: File) => {
        if (!file) return;
        setBusy(true);
        const form = new FormData();
        form.append('photo', file);
        router.post(route('hrm.employees.photo.store', employeeId), form, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                if (fileRef.current) fileRef.current.value = '';
            },
        });
    };

    return (
        <div className="group relative">
            {photoUrl ? (
                <img
                    src={photoUrl}
                    alt={name}
                    className="border-sidebar-border/70 dark:border-sidebar-border h-16 w-16 rounded-full border object-cover"
                />
            ) : (
                <div className="bg-primary/10 text-primary flex h-16 w-16 items-center justify-center rounded-full text-lg font-semibold">
                    {initials || '?'}
                </div>
            )}
            <input
                ref={fileRef}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                className="hidden"
                onChange={(e) => upload(e.target.files?.[0])}
            />
            <div className="mt-1 flex justify-center gap-2">
                <button
                    type="button"
                    className="text-muted-foreground hover:text-foreground text-[11px] underline-offset-2 hover:underline"
                    onClick={() => fileRef.current?.click()}
                    disabled={busy}
                >
                    {photoUrl ? 'Change' : 'Add photo'}
                </button>
                {photoUrl && (
                    <button
                        type="button"
                        className="text-muted-foreground hover:text-foreground text-[11px] underline-offset-2 hover:underline"
                        onClick={() => router.delete(route('hrm.employees.photo.destroy', employeeId), { preserveScroll: true })}
                    >
                        Remove
                    </button>
                )}
            </div>
        </div>
    );
}

/**
 * Ends probation. One action on the server flips the type to permanent AND
 * grants this year's pro-rated leave entitlements - the dialog says so,
 * because a button that quietly moves leave balances is a support call.
 */
function ConfirmDialog({ employeeId, name, probationDue }: { employeeId: number; name: string; probationDue: string | null }) {
    const [open, setOpen] = useState(false);
    const [date, setDate] = useState(dhakaToday());

    const submit = () => {
        router.post(
            route('hrm.employees.confirm', employeeId),
            { confirmation_date: date },
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Confirm as permanent</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Confirm {name} as permanent</DialogTitle>
                    <DialogDescription>
                        Ends the probation{probationDue ? ` (due ${probationDue})` : ''} and grants this year's permanent leave from the confirmation
                        date: casual pro-rata, and sick topped up from the probation 3 days. Balances already used stay used.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="confirmation_date" required>
                        Confirmation date
                    </Label>
                    <Input id="confirmation_date" aria-required type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={date === ''}>
                        Confirm
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function SeparateDialog({ employeeId, separationTypes }: { employeeId: number; separationTypes: Option[] }) {
    const [open, setOpen] = useState(false);
    const [type, setType] = useState(separationTypes[0]?.value ?? '');
    const [date, setDate] = useState('');
    const [reason, setReason] = useState('');

    const submit = () => {
        router.post(
            route('hrm.employees.separate', employeeId),
            { separation_type: type, separation_date: date, separation_reason: reason },
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Separate
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Separate employee</DialogTitle>
                    <DialogDescription>
                        Records how and when they left, ends their assignments, and disables any ERP login. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="separation_type" required>
                            Type
                        </Label>
                        <SearchSelect id="separation_type" value={type} onChange={(e) => setType(e.target.value)}>
                            {separationTypes.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="separation_date" required>
                            Last working date
                        </Label>
                        <Input id="separation_date" aria-required type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="separation_reason">Reason (optional)</Label>
                        <textarea
                            id="separation_reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            className="border-input bg-background focus-visible:ring-ring min-h-20 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={submit} disabled={type === '' || date === ''}>
                        Separate
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function TransferDialog({
    employeeId,
    offices,
    designations,
    supervisors,
}: {
    employeeId: number;
    offices: OfficeNode[];
    designations: NamedOption[];
    supervisors: NamedOption[];
}) {
    const [open, setOpen] = useState(false);
    const [effectiveFrom, setEffectiveFrom] = useState('');
    const [officeId, setOfficeId] = useState('');
    const [departmentId, setDepartmentId] = useState('');
    const [sectionId, setSectionId] = useState('');
    const [designationId, setDesignationId] = useState('');
    const [supervisorId, setSupervisorId] = useState('');

    const departments = offices.find((office) => String(office.id) === officeId)?.departments ?? [];
    const sections = departments.find((department) => String(department.id) === departmentId)?.sections ?? [];

    const changeOffice = (value: string) => {
        setOfficeId(value);
        setDepartmentId('');
        setSectionId('');
    };
    const changeDepartment = (value: string) => {
        setDepartmentId(value);
        setSectionId('');
    };

    const submit = () => {
        const asId = (value: string) => (value === '' ? null : Number(value));
        router.post(
            route('hrm.employees.transfer', employeeId),
            {
                effective_from: effectiveFrom,
                office_id: asId(officeId),
                department_id: asId(departmentId),
                section_id: asId(sectionId),
                designation_id: asId(designationId),
                supervisor_id: asId(supervisorId),
            },
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Reassign
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reassign employee</DialogTitle>
                    <DialogDescription>
                        Opens a new placement from the effective date and ends the current one. The previous placement is kept in the assignment
                        history.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="effective_from" required>
                            Effective from
                        </Label>
                        <Input
                            id="effective_from"
                            aria-required
                            type="date"
                            value={effectiveFrom}
                            onChange={(e) => setEffectiveFrom(e.target.value)}
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="office_id">Office</Label>
                            <SearchSelect id="office_id" value={officeId} onChange={(e) => changeOffice(e.target.value)}>
                                <option value="">- None -</option>
                                {offices.map((office) => (
                                    <option key={office.id} value={office.id}>
                                        {office.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="department_id">Department</Label>
                            <SearchSelect
                                id="department_id"
                                value={departmentId}
                                onChange={(e) => changeDepartment(e.target.value)}
                                disabled={officeId === ''}
                            >
                                <option value="">- None -</option>
                                {departments.map((department) => (
                                    <option key={department.id} value={department.id}>
                                        {department.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="section_id">Section</Label>
                            <SearchSelect
                                id="section_id"
                                value={sectionId}
                                onChange={(e) => setSectionId(e.target.value)}
                                disabled={departmentId === ''}
                            >
                                <option value="">- None -</option>
                                {sections.map((section) => (
                                    <option key={section.id} value={section.id}>
                                        {section.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="designation_id">Designation</Label>
                            <SearchSelect id="designation_id" value={designationId} onChange={(e) => setDesignationId(e.target.value)}>
                                <option value="">- None -</option>
                                {designations.map((designation) => (
                                    <option key={designation.id} value={designation.id}>
                                        {designation.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="supervisor_id">Supervisor</Label>
                        <SearchSelect id="supervisor_id" value={supervisorId} onChange={(e) => setSupervisorId(e.target.value)}>
                            <option value="">- None -</option>
                            {supervisors.map((supervisor) => (
                                <option key={supervisor.id} value={supervisor.id}>
                                    {supervisor.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={effectiveFrom === ''}>
                        Reassign
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function formatBytes(size: number): string {
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(0)} KB`;
    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

function UploadDocumentDialog({ employeeId, documentTypes }: { employeeId: number; documentTypes: Option[] }) {
    const [open, setOpen] = useState(false);
    const [category, setCategory] = useState(documentTypes[0]?.value ?? '');
    const [file, setFile] = useState<File | null>(null);
    const [expiresAt, setExpiresAt] = useState('');

    const submit = () => {
        if (!file) return;
        router.post(
            route('hrm.employees.documents.store', employeeId),
            { category, file, expires_at: expiresAt === '' ? null : expiresAt },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    setFile(null);
                    setExpiresAt('');
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Add document
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Upload document</DialogTitle>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="category" required>
                            Type
                        </Label>
                        <SearchSelect id="category" value={category} onChange={(e) => setCategory(e.target.value)}>
                            {documentTypes.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="file" required>
                            File
                        </Label>
                        <Input
                            id="file"
                            aria-required
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="expires_at">Expiry date (optional)</Label>
                        <Input id="expires_at" type="date" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={category === '' || !file}>
                        Upload
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DocumentsCard({
    employeeId,
    documents,
    documentTypes,
    reasons,
}: {
    employeeId: number;
    documents: Document[];
    documentTypes: Option[];
    reasons: ReasonOption[];
}) {
    const { t } = useTranslation();

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
            <div className="mb-3 flex items-center justify-between gap-3">
                <h3 className="text-sm font-medium">Documents</h3>
                <UploadDocumentDialog employeeId={employeeId} documentTypes={documentTypes} />
            </div>
            {documents.length === 0 ? (
                <p className="text-muted-foreground text-sm">No documents yet.</p>
            ) : (
                <table className="w-full min-w-[42rem] text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Type</th>
                            <th className="py-2">File</th>
                            <th className="py-2">Expiry</th>
                            <th className="py-2">Uploaded by</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {documents.map((document) => (
                            <tr key={document.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">{document.category}</td>
                                <td className="py-2">
                                    <a href={document.url} target="_blank" rel="noopener noreferrer" className="text-primary underline">
                                        {document.original_name}
                                    </a>
                                    <span className="text-muted-foreground ml-2 text-xs">{formatBytes(document.size)}</span>
                                </td>
                                <td className="text-muted-foreground py-2">
                                    {showDate(document.expires_at) || '-'}
                                    {document.is_expired && (
                                        <Badge variant="destructive" className="ml-2">
                                            Expired
                                        </Badge>
                                    )}
                                </td>
                                <td className="text-muted-foreground py-2">{document.uploaded_by ?? '-'}</td>
                                <td className="py-2 text-right">
                                    {/* ⭐ Corrections wave 3: a reason, and a line in History. */}
                                    <CorrectionReasonDialog
                                        trigger={
                                            <Button variant="ghost" size="sm">
                                                {t('corr.hrm.delete_document')}
                                            </Button>
                                        }
                                        title={t('corr.hrm.delete_document_title', { record: `${document.category}, ${document.original_name}` })}
                                        description={t('corr.hrm.delete_document_text')}
                                        reasons={reasons}
                                        submitUrl={route('hrm.employees.documents.delete', [employeeId, document.id])}
                                        confirmLabel={t('corr.hrm.delete_document')}
                                        destructive
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

/**
 * Standing permission to clock in from away from the office.
 *
 * ⚠ This is not the same as approving a day out. Field duty and official work
 * already do that for a particular date; this is for a person whose job is
 * simply not at a desk, so nobody has to approve the same thing every morning.
 */
function RemoteClockInCard({ employee }: { employee: EmployeeDetail }) {
    const [reason, setReason] = useState(employee.remote_clock_in_reason ?? '');
    const [saving, setSaving] = useState(false);

    const set = (allowed: boolean) => {
        setSaving(true);
        router.patch(
            route('hrm.employees.remote-clock-in', employee.id),
            { may_clock_in_remotely: allowed, reason },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
            <h3 className="mb-1 text-sm font-medium">Clocking in from a browser</h3>

            {employee.may_clock_in_remotely ? (
                <>
                    <p className="text-muted-foreground mb-3 text-sm">
                        {employee.name} can clock in and out from a browser, from anywhere. Their location is still recorded with every punch.
                        {employee.remote_clock_in_granted_by
                            ? ` Allowed by ${employee.remote_clock_in_granted_by}${employee.remote_clock_in_granted_at ? ` on ${showDate(employee.remote_clock_in_granted_at)}` : ''}.`
                            : ''}
                    </p>
                    {employee.remote_clock_in_reason && (
                        <p className="text-muted-foreground mb-3 text-sm">Reason given: {employee.remote_clock_in_reason}</p>
                    )}
                    <Button variant="outline" size="sm" disabled={saving} onClick={() => set(false)}>
                        Withdraw this permission
                    </Button>
                </>
            ) : (
                <>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="remote-reason">Why (optional)</Label>
                            <Input
                                id="remote-reason"
                                value={reason}
                                placeholder="Field sales, deliveries, site visits"
                                onChange={(e) => setReason(e.target.value)}
                                className="w-72"
                            />
                        </div>
                        <Button size="sm" disabled={saving} onClick={() => set(true)}>
                            Allow clocking in from a browser
                        </Button>
                    </div>
                </>
            )}
        </div>
    );
}

function LoginAccountCard({ employee, lifetime }: { employee: EmployeeDetail; lifetime: string }) {
    const [email, setEmail] = useState('');
    const [newEmail, setNewEmail] = useState(employee.user?.email ?? '');
    const [sending, setSending] = useState(false);

    const changeLoginEmail = () => {
        if (employee.user === null) return;
        setSending(true);
        router.patch(
            route('hrm.employees.login-email', employee.id),
            { email: newEmail.trim().toLowerCase() },
            { onFinish: () => setSending(false) },
        );
    };

    const createLogin = () => {
        setSending(true);
        router.post(route('hrm.employees.login-account', employee.id), { email: email.trim().toLowerCase() }, { onFinish: () => setSending(false) });
    };

    const resend = () => {
        if (employee.user === null) return;
        setSending(true);
        router.post(route('users.password-link', employee.user.id), {}, { onFinish: () => setSending(false) });
    };

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
            <h3 className="mb-1 text-sm font-medium">Login account</h3>

            {employee.user === null ? (
                <>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="login-email" required>
                                Their email address
                            </Label>
                            <Input
                                id="login-email"
                                aria-required
                                type="email"
                                className="sm:w-80"
                                placeholder="name@example.com"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                            />
                        </div>
                        <Button size="sm" disabled={sending || email.trim() === ''} onClick={createLogin}>
                            Create login and email the link
                        </Button>
                    </div>
                </>
            ) : (
                <>
                    <dl className="mb-3 grid gap-4 sm:grid-cols-3">
                        <Field label="Signs in as" value={employee.user.email} />
                        <Field label="Account" value={employee.user.is_active ? 'Active' : 'Deactivated'} />
                        <Field
                            label="Password"
                            value={
                                employee.user.password_link_sent_at === null
                                    ? 'Set by them'
                                    : employee.user.password_link_expired
                                      ? 'Link expired, never used'
                                      : 'Link sent, not used yet'
                            }
                        />
                    </dl>
                    {employee.user.password_link_sent_at !== null && (
                        <p className={`mb-3 text-xs ${employee.user.password_link_expired ? 'text-destructive' : 'text-muted-foreground'}`}>
                            Password link sent {showDate(employee.user.password_link_sent_at)}
                            {employee.user.password_link_expired
                                ? ' has expired without being used. Send another.'
                                : `, valid for ${lifetime} from then.`}
                        </p>
                    )}
                    <Button size="sm" variant="outline" disabled={sending} onClick={resend}>
                        {employee.user.password_link_sent_at === null ? 'Send password link' : 'Resend password link'}
                    </Button>

                    {/* ⭐ 27 Sep 2026 (Joy): change the address they sign in with. Password stays the same. */}
                    <div className="mt-4 border-t pt-4">
                        <Label htmlFor="change-login-email" required>
                            Change login email
                        </Label>
                        <div className="mt-1.5 flex flex-wrap items-end gap-3">
                            <Input
                                id="change-login-email"
                                aria-required
                                type="email"
                                className="sm:w-80"
                                placeholder="name@example.com"
                                value={newEmail}
                                onChange={(e) => setNewEmail(e.target.value)}
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={sending || newEmail.trim() === '' || newEmail.trim().toLowerCase() === employee.user.email.toLowerCase()}
                                onClick={changeLoginEmail}
                            >
                                Change login email
                            </Button>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

export default function EmployeeShow({
    timeline,
    employee,
    transitionable_statuses,
    can_confirm,
    probation_due,
    can_separate,
    separation_types,
    can_transfer,
    offices_tree,
    designations,
    supervisors,
    document_types,
    can_manage_login,
    password_link_lifetime,
    corrections,
    employee_cash = [],
    money,
}: ShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Employees', href: '/hrm/employees' },
        { title: employee.employee_code, href: `/hrm/employees/${employee.id}` },
    ];

    const setStatus = (status: string) => router.patch(route('hrm.employees.status', employee.id), { status }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={employee.name} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-center gap-4">
                        <ProfilePhoto employeeId={employee.id} photoUrl={employee.photo_url} name={employee.name} />
                        <div>
                            <h2 className="text-lg font-medium">{employee.name}</h2>
                            <p className="text-muted-foreground text-sm">
                                {employee.employee_code} · {employee.employment_type}
                            </p>
                            {employee.referred_by && <p className="text-muted-foreground text-xs">Referred by {employee.referred_by}</p>}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href={`/hrm/attendance/employees/${employee.id}`}>
                            <Button variant="outline" size="sm">
                                Attendance
                            </Button>
                        </Link>
                        <Badge variant={statusVariant[employee.status] ?? 'secondary'} className="capitalize">
                            {employee.status.replace('_', ' ')}
                        </Badge>
                        {transitionable_statuses.map((target) => (
                            <Button key={target.value} variant="outline" size="sm" onClick={() => setStatus(target.value)}>
                                {target.value === 'active' ? 'Set active' : `Set ${target.label.toLowerCase()}`}
                            </Button>
                        ))}
                        {can_confirm && <ConfirmDialog employeeId={employee.id} name={employee.name} probationDue={probation_due} />}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('hrm.employees.edit', employee.id)}>Edit</Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/hrm/employees/${employee.id}/letters`}>HR letters</Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/hrm/employees/${employee.id}/checklist`}>Checklist</Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/hrm/employees/${employee.id}/performance`}>Performance</Link>
                        </Button>
                        <SignatureUpload employeeId={employee.id} hasSignature={employee.has_signature} />
                        {can_transfer && (
                            <TransferDialog employeeId={employee.id} offices={offices_tree} designations={designations} supervisors={supervisors} />
                        )}
                        {can_separate && <SeparateDialog employeeId={employee.id} separationTypes={separation_types} />}
                        {corrections && (
                            <HrCorrectionActions
                                kind="employee"
                                id={employee.id}
                                label={`${employee.name} (${employee.employee_code})`}
                                actions={corrections.actions}
                                reasons={corrections.reasons}
                                url={
                                    corrections.actions.actions[0]?.action === 'delete'
                                        ? route('hrm.employees.destroy', employee.id)
                                        : route('hrm.employees.undo-separation', employee.id)
                                }
                                historyType="hrm.employee"
                            />
                        )}
                    </div>
                </div>

                {employee.status === 'separated' && (
                    <div className="border-destructive/40 bg-destructive/5 rounded-xl border p-4">
                        <h3 className="text-destructive mb-3 text-sm font-medium">Separation</h3>
                        <dl className="grid gap-4 sm:grid-cols-3">
                            <Field label="Type" value={employee.separation_type} />
                            <Field label="Last working date" value={employee.separation_date} />
                            <Field label="Reason" value={employee.separation_reason} />
                        </dl>
                        {/* An approved requirement (EMP-08) that fell between two
                            phases and had no door until now. */}
                        <Button variant="outline" size="sm" className="mt-3" asChild>
                            <Link href={`/hrm/employees/${employee.id}/settlement`}>Final settlement statement</Link>
                        </Button>
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Details</h3>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label="Personal email" value={employee.personal_email} />
                        <Field label="Phone" value={employee.phone} />
                        <Field label="ERP login" value={employee.user ? employee.user.email : 'Not linked'} />
                        <Field label="National ID" value={employee.national_id} />
                        <Field label="Birth certificate no." value={employee.birth_certificate_no} />
                        <Field label="Date of birth" value={employee.date_of_birth} />
                        <Field label="Present address" value={employee.present_address} />
                        <Field label="Permanent address" value={employee.permanent_address} />
                        <Field label="Joining date" value={employee.joining_date} />
                        <Field label="Confirmation date" value={employee.confirmation_date} />
                        <Field label="Contract end date" value={employee.contract_end_date} />
                    </dl>
                </div>

                {can_manage_login && employee.status !== 'separated' && <LoginAccountCard employee={employee} lifetime={password_link_lifetime} />}

                {employee.status !== 'separated' && <RemoteClockInCard employee={employee} />}

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Emergency contact</h3>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label="Name" value={employee.emergency_contact_name} />
                        <Field label="Phone" value={employee.emergency_contact_phone} />
                        <Field label="Relationship" value={employee.emergency_contact_relationship} />
                    </dl>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Bank & Mobile Financial Service</h3>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label="Account name" value={employee.bank_account_name} />
                        <Field label="Account number" value={employee.bank_account_number} />
                        <Field label="Bank name" value={employee.bank_name} />
                        <Field label="Branch" value={employee.bank_branch} />
                        <Field label="Routing number" value={employee.bank_routing_number} />
                        <Field label="MFS provider" value={employee.mfs_provider} />
                        <Field label="MFS account number" value={employee.mfs_account_number} />
                    </dl>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Assignment history</h3>
                    <table className="w-full min-w-[46rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2">Office</th>
                                <th className="py-2">Department</th>
                                <th className="py-2">Section</th>
                                <th className="py-2">Designation</th>
                                <th className="py-2">Supervisor</th>
                                <th className="py-2">From</th>
                                <th className="py-2">To</th>
                            </tr>
                        </thead>
                        <tbody>
                            {employee.assignments.map((assignment) => (
                                <tr key={assignment.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">
                                        {assignment.office ?? '-'}
                                        {assignment.is_primary && <span className="text-muted-foreground ml-2 text-xs">(primary)</span>}
                                    </td>
                                    <td className="text-muted-foreground py-2">{assignment.department ?? '-'}</td>
                                    <td className="text-muted-foreground py-2">{assignment.section ?? '-'}</td>
                                    <td className="text-muted-foreground py-2">{assignment.designation ?? '-'}</td>
                                    <td className="text-muted-foreground py-2">{assignment.supervisor ?? '-'}</td>
                                    <td className="text-muted-foreground py-2">{showDate(assignment.effective_from) || '-'}</td>
                                    <td className="text-muted-foreground py-2">{showDate(assignment.effective_to) || 'Current'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <EmployeeCashCard accounts={employee_cash} />

                <DocumentsCard
                    employeeId={employee.id}
                    documents={employee.documents}
                    documentTypes={document_types}
                    reasons={corrections?.reasons.deletion ?? []}
                />

                {money && <EmployeeMoneyPanel employeeId={employee.id} money={money} />}
            </div>
            <div className="border-border bg-card mx-4 mb-4 rounded-lg border p-4">
                <h2 className="mb-3 text-sm font-semibold">Timeline</h2>
                <RecordTimeline entries={timeline ?? []} />
            </div>
        </AppLayout>
    );
}
