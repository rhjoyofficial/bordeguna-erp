import SelfServiceTabs from '@/components/self-service-tabs';
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
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Placement {
    office: string | null;
    department: string | null;
    section: string | null;
    designation: string | null;
    supervisor: string | null;
    since: string | null;
}

interface Document {
    id: number;
    category: string;
    original_name: string;
    size: number;
    expires_at: string | null;
    is_expired: boolean;
    url: string;
}

interface Profile {
    employee_code: string;
    name: string;
    employment_type: string;
    status: string;
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
    placement: Placement | null;
    documents: Document[];
}

interface RequestableField {
    name: string;
    label: string;
    value: string | null;
}

interface RequestableGroup {
    key: string;
    label: string;
    fields: RequestableField[];
}

interface Option {
    value: string;
    label: string;
}

interface ChangeRequestRow {
    id: number;
    summary: string;
    status: string;
    stage: string | null;
    requested_at: string;
}

interface SalaryLine {
    name: string;
    type: string;
    amount: number;
    formula: string;
}

interface Salary {
    effective_from: string;
    basic: number;
    gross: number;
    lines: SalaryLine[];
}

interface ShowProps {
    employee: Profile;
    requestable: RequestableGroup[];
    mfs_providers: Option[];
    change_requests: ChangeRequestRow[];
    /** ACL-02 lists the employee themselves among those who may see salary - their own only. */
    salary: Salary | null;
}

const statusVariant: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    draft: 'secondary',
    active: 'default',
    on_leave: 'outline',
    suspended: 'destructive',
    separated: 'destructive',
};

const requestStatusVariant: Record<string, 'secondary' | 'default' | 'destructive'> = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My profile', href: '/hrm/my-profile' }];
const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

function Field({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value ?? '-'}</dd>
        </div>
    );
}

function formatBytes(size: number): string {
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(0)} KB`;
    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

function RequestUpdateDialog({ group, mfsProviders }: { group: RequestableGroup; mfsProviders: Option[] }) {
    const [open, setOpen] = useState(false);
    const [values, setValues] = useState<Record<string, string>>(() =>
        Object.fromEntries(group.fields.map((field) => [field.name, field.value ?? ''])),
    );

    const submit = () => {
        router.post(
            route('hrm.my-profile.change-requests.store'),
            { group: group.key, ...values },
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Request update
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Request update - {group.label}</DialogTitle>
                    <DialogDescription>
                        Your request is sent for approval; the change is applied only once approved. Nothing changes immediately.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[60vh] gap-4 overflow-y-auto">
                    {group.fields.map((field) => (
                        <div key={field.name} className="grid gap-2">
                            <Label htmlFor={field.name}>{field.label}</Label>
                            {field.name === 'mfs_provider' ? (
                                <select
                                    id={field.name}
                                    className={selectClass}
                                    value={values[field.name]}
                                    onChange={(e) => setValues((v) => ({ ...v, [field.name]: e.target.value }))}
                                >
                                    <option value="">- None -</option>
                                    {mfsProviders.map((provider) => (
                                        <option key={provider.value} value={provider.value}>
                                            {provider.label}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                <Input
                                    id={field.name}
                                    value={values[field.name]}
                                    onChange={(e) => setValues((v) => ({ ...v, [field.name]: e.target.value }))}
                                />
                            )}
                        </div>
                    ))}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit}>Submit request</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function MyProfile({ employee, requestable, mfs_providers, change_requests, salary }: ShowProps) {
    const money = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My profile" />
            <div className="px-4 pt-4">
                <SelfServiceTabs active="/hrm/my-profile" />
            </div>
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">{employee.name}</h2>
                        <p className="text-sm text-muted-foreground">
                            {employee.employee_code} · {employee.employment_type}
                        </p>
                    </div>
                    <Badge variant={statusVariant[employee.status] ?? 'secondary'} className="capitalize">
                        {employee.status.replace('_', ' ')}
                    </Badge>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h3 className="text-sm font-medium">Contact & emergency details</h3>
                        {requestable
                            .filter((group) => group.key === 'personal')
                            .map((group) => (
                                <RequestUpdateDialog key={group.key} group={group} mfsProviders={mfs_providers} />
                            ))}
                    </div>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label="Personal email" value={employee.personal_email} />
                        <Field label="Phone" value={employee.phone} />
                        <Field label="Present address" value={employee.present_address} />
                        <Field label="Permanent address" value={employee.permanent_address} />
                        <Field label="Emergency contact name" value={employee.emergency_contact_name} />
                        <Field label="Emergency contact phone" value={employee.emergency_contact_phone} />
                        <Field label="Emergency contact relationship" value={employee.emergency_contact_relationship} />
                    </dl>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Identity</h3>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label="National ID" value={employee.national_id} />
                        <Field label="Birth certificate no." value={employee.birth_certificate_no} />
                        <Field label="Date of birth" value={employee.date_of_birth} />
                        <Field label="Joining date" value={employee.joining_date} />
                        <Field label="Confirmation date" value={employee.confirmation_date} />
                        <Field label="Contract end date" value={employee.contract_end_date} />
                    </dl>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h3 className="text-sm font-medium">Bank & Mobile Financial Service</h3>
                        {requestable
                            .filter((group) => group.key === 'bank')
                            .map((group) => (
                                <RequestUpdateDialog key={group.key} group={group} mfsProviders={mfs_providers} />
                            ))}
                    </div>
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

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Where I work</h3>
                    {employee.placement ? (
                        <dl className="grid gap-4 sm:grid-cols-3">
                            <Field label="Office" value={employee.placement.office} />
                            <Field label="Department" value={employee.placement.department} />
                            <Field label="Section" value={employee.placement.section} />
                            <Field label="Designation" value={employee.placement.designation} />
                            <Field label="Supervisor" value={employee.placement.supervisor} />
                            <Field label="Since" value={employee.placement.since} />
                        </dl>
                    ) : (
                        <p className="text-sm text-muted-foreground">No current placement in this business.</p>
                    )}
                </div>

                {salary !== null && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                        <h3 className="mb-1 text-sm font-medium">My salary</h3>
                        <p className="mb-3 text-xs text-muted-foreground">
                            In effect since {salary.effective_from}. These are your contractual figures - what a month actually pays also depends on
                            attendance, leave and any adjustments, and appears on your payslip.
                        </p>
                        <table className="w-full min-w-[28rem] text-sm">
                            <tbody>
                                {salary.lines
                                    .filter((l) => l.type === 'earning')
                                    .map((l) => (
                                        <tr key={l.name} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td className="py-2">{l.name}</td>
                                            <td className="py-2 text-right">{money(l.amount)}</td>
                                        </tr>
                                    ))}
                                <tr className="font-medium">
                                    <td className="py-2">Gross</td>
                                    <td className="py-2 text-right">{money(salary.gross)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">My update requests</h3>
                    {change_requests.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No update requests yet.</p>
                    ) : (
                        <table className="w-full min-w-[36rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Requested change</th>
                                    <th className="py-2">Status</th>
                                    <th className="py-2">When</th>
                                </tr>
                            </thead>
                            <tbody>
                                {change_requests.map((row) => (
                                    <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">{row.summary}</td>
                                        <td className="py-2">
                                            <Badge variant={requestStatusVariant[row.status] ?? 'secondary'} className="capitalize">
                                                {row.status}
                                            </Badge>
                                            {row.stage && <span className="ml-2 text-xs text-muted-foreground">at {row.stage}</span>}
                                        </td>
                                        <td className="py-2 text-muted-foreground">{row.requested_at}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">My documents</h3>
                    {employee.documents.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No documents on file.</p>
                    ) : (
                        <table className="w-full min-w-[36rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Type</th>
                                    <th className="py-2">File</th>
                                    <th className="py-2">Expiry</th>
                                </tr>
                            </thead>
                            <tbody>
                                {employee.documents.map((document) => (
                                    <tr key={document.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">{document.category}</td>
                                        <td className="py-2">
                                            <a href={document.url} target="_blank" rel="noopener noreferrer" className="text-primary underline">
                                                {document.original_name}
                                            </a>
                                            <span className="ml-2 text-xs text-muted-foreground">{formatBytes(document.size)}</span>
                                        </td>
                                        <td className="py-2 text-muted-foreground">
                                            {document.expires_at ?? '-'}
                                            {document.is_expired && (
                                                <Badge variant="destructive" className="ml-2">
                                                    Expired
                                                </Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
