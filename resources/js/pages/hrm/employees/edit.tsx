import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Option {
    value: string;
    label: string;
}

// Every editable master field (mirrors Employee::EDITABLE_FIELDS). Nullable
// fields arrive as null and are edited as empty strings.
interface EditableEmployee {
    id: number;
    employee_code: string;
    name: string;
    first_name: string | null;
    last_name: string | null;
    employment_type: string | null;
    probation_months: number | string | null;
    biometric_enroll_id: string | null;
    personal_email: string | null;
    phone: string | null;
    present_address: string | null;
    permanent_address: string | null;
    national_id: string | null;
    birth_certificate_no: string | null;
    referred_by: string | null;
    date_of_birth: string | null;
    joining_date: string | null;
    confirmation_date: string | null;
    contract_end_date: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    emergency_contact_relationship: string | null;
    blood_group: string | null;
    bank_account_name: string | null;
    bank_account_number: string | null;
    bank_name: string | null;
    bank_branch: string | null;
    bank_routing_number: string | null;
    mfs_provider: string | null;
    mfs_account_number: string | null;
    salary_payment_method: string | null;
}

interface EditProps {
    employee: EditableEmployee;
    employment_types: Option[];
    mfs_providers: Option[];
    payment_methods: Option[];
}

type FormData = Omit<EditableEmployee, 'id' | 'employee_code' | 'name'>;

const FIELD_KEYS: (keyof FormData)[] = [
    'first_name',
    'last_name',
    'employment_type',
    'probation_months',
    'biometric_enroll_id',
    'personal_email',
    'phone',
    'present_address',
    'permanent_address',
    'national_id',
    'birth_certificate_no',
    'referred_by',
    'date_of_birth',
    'joining_date',
    'confirmation_date',
    'contract_end_date',
    'emergency_contact_name',
    'emergency_contact_phone',
    'emergency_contact_relationship',
    'blood_group',
    'bank_account_name',
    'bank_account_number',
    'bank_name',
    'bank_branch',
    'bank_routing_number',
    'mfs_provider',
    'mfs_account_number',
    'salary_payment_method',
];

export default function EmployeeEdit({ employee, employment_types, mfs_providers, payment_methods }: EditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Employees', href: '/hrm/employees' },
        { title: employee.employee_code, href: `/hrm/employees/${employee.id}` },
        { title: 'Edit', href: `/hrm/employees/${employee.id}/edit` },
    ];

    const initial = Object.fromEntries(FIELD_KEYS.map((key) => [key, employee[key] ?? ''])) as unknown as FormData;

    const { data, setData, patch, errors, processing, transform } = useForm<FormData>(initial);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // Store blanks as null, not empty strings, so absence is unambiguous.
        transform((payload) => Object.fromEntries(Object.entries(payload).map(([key, value]) => [key, value === '' ? null : value])));
        patch(route('hrm.employees.update', employee.id));
    };

    const Text = ({ field, label, type = 'text' }: { field: keyof FormData; label: string; type?: string }) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <Input id={field} type={type} value={data[field] ?? ''} onChange={(e) => setData(field, e.target.value)} />
            <InputError message={errors[field]} />
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${employee.name}`} />
            <form onSubmit={submit} className="flex max-w-3xl flex-col gap-8 p-4">
                <div>
                    <h2 className="text-lg font-medium">Edit {employee.name}</h2>
                    <p className="text-muted-foreground text-sm">{employee.employee_code} · master record</p>
                </div>

                <section className="grid gap-4">
                    <h3 className="text-sm font-medium">Identity & employment</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Text field="first_name" label="First name" />
                        <Text field="last_name" label="Last name" />
                        <div className="grid gap-2">
                            <Label htmlFor="employment_type" required>
                                Employment type
                            </Label>
                            <SearchSelect
                                id="employment_type"
                                value={data.employment_type ?? ''}
                                onChange={(e) => setData('employment_type', e.target.value)}
                            >
                                {employment_types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.employment_type} />
                        </div>
                        {/* Blank = the 6-month company default; a number only for
                            the person whose offer letter says otherwise. */}
                        <Text field="probation_months" label="Probation length in months (blank = 6)" type="number" />
                        {/* The attendance device's Enroll ID - imported punches are
                            matched on this, so an employee without one is invisible
                            to the biometric import. */}
                        <Text field="biometric_enroll_id" label="Biometric enroll ID" />
                        <Text field="national_id" label="National ID" />
                        <Text field="birth_certificate_no" label="Birth certificate no." />
                        <Text field="date_of_birth" label="Date of birth" type="date" />
                        {/* Joy, 23 Aug 2026: who brought this person in. Free
                            text - referrers are often outside the company. */}
                        <Text field="referred_by" label="Referred by" />
                    </div>
                </section>

                <section className="grid gap-4">
                    <h3 className="text-sm font-medium">Contact & address</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Text field="personal_email" label="Personal email" type="email" />
                        <Text field="phone" label="Phone" />
                        <Text field="present_address" label="Present address" />
                        <Text field="permanent_address" label="Permanent address" />
                    </div>
                </section>

                <section className="grid gap-4">
                    <h3 className="text-sm font-medium">Emergency contact</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Text field="emergency_contact_name" label="Name" />
                        <Text field="emergency_contact_phone" label="Phone" />
                        <Text field="emergency_contact_relationship" label="Relationship" />
                        <Text field="blood_group" label="Blood group" />
                    </div>
                </section>

                <section className="grid gap-4">
                    <h3 className="text-sm font-medium">Employment dates</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Text field="joining_date" label="Joining date" type="date" />
                        <Text field="confirmation_date" label="Confirmation date" type="date" />
                        <Text field="contract_end_date" label="Contract end date" type="date" />
                    </div>
                </section>

                <section className="grid gap-4">
                    <h3 className="text-sm font-medium">Bank account</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Text field="bank_account_name" label="Account name" />
                        <Text field="bank_account_number" label="Account number" />
                        <Text field="bank_name" label="Bank name" />
                        <Text field="bank_branch" label="Branch" />
                        <Text field="bank_routing_number" label="Routing number" />
                    </div>
                </section>

                <section className="grid gap-4">
                    <h3 className="text-sm font-medium">Mobile Financial Service</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="mfs_provider">Provider</Label>
                            <SearchSelect id="mfs_provider" value={data.mfs_provider ?? ''} onChange={(e) => setData('mfs_provider', e.target.value)}>
                                <option value="">- None -</option>
                                {mfs_providers.map((provider) => (
                                    <option key={provider.value} value={provider.value}>
                                        {provider.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.mfs_provider} />
                        </div>
                        <Text field="mfs_account_number" label="Account number" />
                    </div>
                </section>

                <section className="grid gap-4">
                    <h3 className="text-sm font-medium">Salary payment</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="salary_payment_method">How this employee is paid</Label>
                            <SearchSelect
                                id="salary_payment_method"
                                value={data.salary_payment_method ?? ''}
                                onChange={(e) => setData('salary_payment_method', e.target.value)}
                            >
                                <option value="">- Not stated -</option>
                                {payment_methods.map((method) => (
                                    <option key={method.value} value={method.value}>
                                        {method.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.salary_payment_method} />
                            <p className="text-muted-foreground text-xs">
                                Payroll checks the account details this method actually needs, and disbursement groups the payslips by it - cash never
                                mixes with a bank transfer.
                            </p>
                        </div>
                    </div>
                </section>

                <div className="flex gap-3">
                    <Button disabled={processing}>Save changes</Button>
                </div>
            </form>
        </AppLayout>
    );
}
