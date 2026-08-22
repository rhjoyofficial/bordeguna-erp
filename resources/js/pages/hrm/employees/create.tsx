import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Option {
    id: number;
    name: string;
}

interface CreateProps {
    employment_types: { value: string; label: string }[];
    offices: Option[];
    designations: Option[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Employees', href: '/hrm/employees' },
    { title: 'Add employee', href: '/hrm/employees/create' },
];

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

export default function EmployeeCreate({ employment_types, offices, designations }: CreateProps) {
    const { data, setData, post, errors, processing } = useForm({
        first_name: '',
        last_name: '',
        employment_type: employment_types[0]?.value ?? '',
        joining_date: '',
        personal_email: '',
        phone: '',
        national_id: '',
        date_of_birth: '',
        office_id: '',
        designation_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('hrm.employees.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add employee" />
            <form onSubmit={submit} className="flex max-w-2xl flex-col gap-6 p-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="first_name">First name</Label>
                        <Input id="first_name" value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} />
                        <InputError message={errors.first_name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="last_name">Last name</Label>
                        <Input id="last_name" value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} />
                        <InputError message={errors.last_name} />
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="employment_type">Employment type</Label>
                        <select id="employment_type" className={selectClass} value={data.employment_type} onChange={(e) => setData('employment_type', e.target.value)}>
                            {employment_types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.employment_type} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="joining_date">Joining date</Label>
                        <Input id="joining_date" type="date" value={data.joining_date} onChange={(e) => setData('joining_date', e.target.value)} />
                        <InputError message={errors.joining_date} />
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="office_id">Office</Label>
                        <select id="office_id" className={selectClass} value={data.office_id} onChange={(e) => setData('office_id', e.target.value)}>
                            <option value="">- None -</option>
                            {offices.map((office) => (
                                <option key={office.id} value={office.id}>
                                    {office.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.office_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="designation_id">Designation</Label>
                        <select id="designation_id" className={selectClass} value={data.designation_id} onChange={(e) => setData('designation_id', e.target.value)}>
                            <option value="">- None -</option>
                            {designations.map((designation) => (
                                <option key={designation.id} value={designation.id}>
                                    {designation.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.designation_id} />
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="personal_email">Personal email</Label>
                        <Input id="personal_email" type="email" value={data.personal_email} onChange={(e) => setData('personal_email', e.target.value)} />
                        <InputError message={errors.personal_email} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="phone">Phone</Label>
                        <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                        <InputError message={errors.phone} />
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="national_id">National ID</Label>
                        <Input id="national_id" value={data.national_id} onChange={(e) => setData('national_id', e.target.value)} />
                        <InputError message={errors.national_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="date_of_birth">Date of birth</Label>
                        <Input id="date_of_birth" type="date" value={data.date_of_birth} onChange={(e) => setData('date_of_birth', e.target.value)} />
                        <InputError message={errors.date_of_birth} />
                    </div>
                </div>

                <div>
                    <Button disabled={processing}>Create employee</Button>
                </div>
            </form>
        </AppLayout>
    );
}
