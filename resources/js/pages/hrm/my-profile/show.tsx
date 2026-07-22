import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

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

interface ShowProps {
    employee: Profile;
}

const statusVariant: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    draft: 'secondary',
    active: 'default',
    on_leave: 'outline',
    suspended: 'destructive',
    separated: 'destructive',
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My profile', href: '/hrm/my-profile' }];

function Field({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value ?? '—'}</dd>
        </div>
    );
}

function formatBytes(size: number): string {
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(0)} KB`;
    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

export default function MyProfile({ employee }: ShowProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My profile" />
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
                    <h3 className="mb-3 text-sm font-medium">Personal details</h3>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label="Personal email" value={employee.personal_email} />
                        <Field label="Phone" value={employee.phone} />
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
                                            {document.expires_at ?? '—'}
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
