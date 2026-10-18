import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
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

// HRM-007 (14 Oct 2026): the request states a person sees on their own changes.
const requestStatusKey: Record<string, TranslationKey> = {
    pending: 'my_profile.request_status.pending',
    approved: 'my_profile.request_status.approved',
    rejected: 'my_profile.request_status.rejected',
};

function Field({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
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
    const { t } = useTranslation();
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
                    {t('my_profile.request_update')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('my_profile.request_update_for', { group: group.label })}</DialogTitle>
                </DialogHeader>
                <div className="grid max-h-[60vh] gap-4 overflow-y-auto">
                    {group.fields.map((field) => (
                        <div key={field.name} className="grid gap-2">
                            <Label htmlFor={field.name}>{field.label}</Label>
                            {field.name === 'mfs_provider' ? (
                                <SearchSelect
                                    id={field.name}
                                    value={values[field.name]}
                                    onChange={(e) => setValues((v) => ({ ...v, [field.name]: e.target.value }))}
                                >
                                    <option value="">{t('my_profile.none_option')}</option>
                                    {mfsProviders.map((provider) => (
                                        <option key={provider.value} value={provider.value}>
                                            {provider.label}
                                        </option>
                                    ))}
                                </SearchSelect>
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
                        <Button variant="outline">{t('self.cancel')}</Button>
                    </DialogClose>
                    <Button onClick={submit}>{t('my_profile.submit_request')}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function MyProfile({ employee, requestable, mfs_providers, change_requests, salary }: ShowProps) {
    // HRM-007 (14 Oct 2026): every label a staff member reads about themselves.
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('my_profile.title'), href: '/hrm/my-profile' }];
    const money = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('my_profile.title')} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">{employee.name}</h2>
                        <p className="text-muted-foreground text-sm">
                            {employee.employee_code} · {employee.employment_type}
                        </p>
                    </div>
                    <Badge variant={statusVariant[employee.status] ?? 'secondary'} className="capitalize">
                        {employee.status.replace('_', ' ')}
                    </Badge>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h3 className="text-sm font-medium">{t('my_profile.contact_heading')}</h3>
                        {requestable
                            .filter((group) => group.key === 'personal')
                            .map((group) => (
                                <RequestUpdateDialog key={group.key} group={group} mfsProviders={mfs_providers} />
                            ))}
                    </div>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('my_profile.personal_email')} value={employee.personal_email} />
                        <Field label={t('my_profile.phone')} value={employee.phone} />
                        <Field label={t('my_profile.present_address')} value={employee.present_address} />
                        <Field label={t('my_profile.permanent_address')} value={employee.permanent_address} />
                        <Field label={t('my_profile.emergency_name')} value={employee.emergency_contact_name} />
                        <Field label={t('my_profile.emergency_phone')} value={employee.emergency_contact_phone} />
                        <Field label={t('my_profile.emergency_relationship')} value={employee.emergency_contact_relationship} />
                    </dl>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">{t('my_profile.identity')}</h3>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('my_profile.national_id')} value={employee.national_id} />
                        <Field label={t('my_profile.birth_certificate')} value={employee.birth_certificate_no} />
                        <Field label={t('my_profile.date_of_birth')} value={employee.date_of_birth} />
                        <Field label={t('my_profile.joining_date')} value={employee.joining_date} />
                        <Field label={t('my_profile.confirmation_date')} value={employee.confirmation_date} />
                        <Field label={t('my_profile.contract_end_date')} value={employee.contract_end_date} />
                    </dl>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h3 className="text-sm font-medium">{t('my_profile.bank_heading')}</h3>
                        {requestable
                            .filter((group) => group.key === 'bank')
                            .map((group) => (
                                <RequestUpdateDialog key={group.key} group={group} mfsProviders={mfs_providers} />
                            ))}
                    </div>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('my_profile.account_name')} value={employee.bank_account_name} />
                        <Field label={t('my_profile.account_number')} value={employee.bank_account_number} />
                        <Field label={t('my_profile.bank_name')} value={employee.bank_name} />
                        <Field label={t('my_profile.branch')} value={employee.bank_branch} />
                        <Field label={t('my_profile.routing_number')} value={employee.bank_routing_number} />
                        <Field label={t('my_profile.mfs_provider')} value={employee.mfs_provider} />
                        <Field label={t('my_profile.mfs_number')} value={employee.mfs_account_number} />
                    </dl>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">{t('my_profile.where_i_work')}</h3>
                    {employee.placement ? (
                        <dl className="grid gap-4 sm:grid-cols-3">
                            <Field label={t('my_profile.office')} value={employee.placement.office} />
                            <Field label={t('my_profile.department')} value={employee.placement.department} />
                            <Field label={t('my_profile.section')} value={employee.placement.section} />
                            <Field label={t('my_profile.designation')} value={employee.placement.designation} />
                            <Field label={t('my_profile.supervisor')} value={employee.placement.supervisor} />
                            <Field label={t('my_profile.since')} value={employee.placement.since} />
                        </dl>
                    ) : (
                        <p className="text-muted-foreground text-sm">{t('my_profile.no_placement')}</p>
                    )}
                </div>

                {salary !== null && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                        <h3 className="mb-1 text-sm font-medium">{t('my_profile.my_salary')}</h3>
                        <p className="text-muted-foreground mb-3 text-xs">{t('my_profile.salary_since', { date: salary.effective_from })}</p>
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
                                    <td className="py-2">{t('my_profile.gross')}</td>
                                    <td className="py-2 text-right">{money(salary.gross)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">{t('my_profile.my_update_requests')}</h3>
                    {change_requests.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('my_profile.no_update_requests')}</p>
                    ) : (
                        <table className="w-full min-w-[36rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">{t('my_profile.requested_change')}</th>
                                    <th className="py-2">{t('my_profile.status')}</th>
                                    <th className="py-2">{t('my_profile.when')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {change_requests.map((row) => (
                                    <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">{row.summary}</td>
                                        <td className="py-2">
                                            <Badge variant={requestStatusVariant[row.status] ?? 'secondary'} className="capitalize">
                                                {requestStatusKey[row.status] ? t(requestStatusKey[row.status]) : row.status}
                                            </Badge>
                                            {row.stage && (
                                                <span className="text-muted-foreground ml-2 text-xs">{t('self.at_stage', { stage: row.stage })}</span>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2">{showDate(row.requested_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">{t('my_profile.my_documents')}</h3>
                    {employee.documents.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('my_profile.no_documents')}</p>
                    ) : (
                        <table className="w-full min-w-[36rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">{t('my_profile.doc_type')}</th>
                                    <th className="py-2">{t('my_profile.doc_file')}</th>
                                    <th className="py-2">{t('my_profile.doc_expiry')}</th>
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
                                            <span className="text-muted-foreground ml-2 text-xs">{formatBytes(document.size)}</span>
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {showDate(document.expires_at) || '-'}
                                            {document.is_expired && (
                                                <Badge variant="destructive" className="ml-2">
                                                    {t('my_profile.expired')}
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
