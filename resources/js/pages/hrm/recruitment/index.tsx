import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Opening {
    id: number;
    title: string;
    department: string | null;
    designation: string | null;
    description: string | null;
    openings: number;
    still_wanted: number;
    status: string;
    status_label: string;
    opened_on: string | null;
    closes_on: string | null;
    applicants_count: number;
}

interface Applicant {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    source: string | null;
    expected_salary: string | null;
    stage: string;
    stage_label: string;
    closed: boolean;
    note: string | null;
    outcome_reason: string | null;
    opening: string | null;
    opening_id: number;
    applied_on: string | null;
    hired_employee: string | null;
    cv_url: string | null;
}

interface Option {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Recruitment', href: '/hrm/recruitment' }];

const stageTone: Record<string, string> = {
    applied: 'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-100',
    screening: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-100',
    interview: 'bg-violet-100 text-violet-900 dark:bg-violet-950 dark:text-violet-100',
    offer: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
    hired: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100',
    rejected: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-100',
    withdrawn: 'bg-neutral-100 text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100',
};

const today = () => new Date().toISOString().slice(0, 10);

export default function RecruitmentIndex({
    openings,
    applicants,
    filters,
    departments,
    designations,
    stages,
    statuses,
    employees,
    can,
}: {
    openings: Opening[];
    applicants: Paginated<Applicant>;
    filters: { status: string; opening: string; stage: string; search: string };
    departments: { id: number; name: string }[];
    designations: { id: number; name: string }[];
    stages: Option[];
    statuses: Option[];
    employees: { id: number; name: string }[];
    can: { manage: boolean };
}) {
    const [search, setSearch] = useState(filters.search);
    const [editingOpening, setEditingOpening] = useState<Opening | null>(null);
    const [moving, setMoving] = useState<Applicant | null>(null);
    const [moveStage, setMoveStage] = useState('');
    const [moveReason, setMoveReason] = useState('');
    const [moveEmployee, setMoveEmployee] = useState('');

    const openingForm = useForm({
        title: '',
        department_id: '',
        designation_id: '',
        description: '',
        openings: 1,
        status: 'open',
        opened_on: today(),
        closes_on: '',
    });

    const applicantForm = useForm<{
        job_opening_id: string;
        name: string;
        phone: string;
        email: string;
        source: string;
        expected_salary: string;
        note: string;
        applied_on: string;
        cv: File | null;
    }>({
        job_opening_id: '',
        name: '',
        phone: '',
        email: '',
        source: '',
        expected_salary: '',
        note: '',
        applied_on: today(),
        cv: null,
    });

    const applyFilters = (next: Partial<{ status: string; opening: string; stage: string; search: string }>) => {
        router.get(
            '/hrm/recruitment',
            {
                status: next.status ?? filters.status,
                opening: next.opening ?? filters.opening,
                stage: next.stage ?? filters.stage,
                search: next.search ?? search,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitOpening: FormEventHandler = (e) => {
        e.preventDefault();

        if (editingOpening) {
            openingForm.put(route('hrm.recruitment.openings.update', editingOpening.id), {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingOpening(null);
                    openingForm.reset();
                },
            });

            return;
        }

        openingForm.post(route('hrm.recruitment.openings.store'), { preserveScroll: true, onSuccess: () => openingForm.reset() });
    };

    const submitApplicant: FormEventHandler = (e) => {
        e.preventDefault();
        applicantForm.post(route('hrm.recruitment.applicants.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => applicantForm.reset(),
        });
    };

    const submitMove = () => {
        if (!moving) {
            return;
        }

        router.post(
            route('hrm.recruitment.applicants.move', moving.id),
            { stage: moveStage, outcome_reason: moveReason, hired_employee_id: moveEmployee || null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setMoving(null);
                    setMoveReason('');
                    setMoveEmployee('');
                },
            },
        );
    };

    const needsReason = moveStage === 'rejected' || moveStage === 'withdrawn';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recruitment" />
            <div className="flex flex-col gap-8 p-4">
                <section className="flex flex-col gap-4">
                    <div>
                        <h2 className="text-lg font-medium">Openings</h2>
                        <p className="text-muted-foreground text-sm">
                            Roles the business is trying to fill. Two openings with one hire is still an open role, so the number wanted is tracked
                            rather than assumed.
                        </p>
                    </div>

                    {can.manage && (
                        <form onSubmit={submitOpening} className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="title">Role</Label>
                                <Input id="title" value={openingForm.data.title} onChange={(e) => openingForm.setData('title', e.target.value)} />
                                <InputError message={openingForm.errors.title} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="department_id">Department</Label>
                                <SearchSelect
                                    id="department_id"
                                    value={openingForm.data.department_id}
                                    onChange={(e) => openingForm.setData('department_id', e.target.value)}
                                >
                                    <option value="">Not set</option>
                                    {departments.map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="designation_id">Designation</Label>
                                <SearchSelect
                                    id="designation_id"
                                    value={openingForm.data.designation_id}
                                    onChange={(e) => openingForm.setData('designation_id', e.target.value)}
                                >
                                    <option value="">Not set</option>
                                    {designations.map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="openings">How many</Label>
                                <Input
                                    id="openings"
                                    type="number"
                                    min={1}
                                    value={openingForm.data.openings}
                                    onChange={(e) => openingForm.setData('openings', Number(e.target.value))}
                                />
                                <InputError message={openingForm.errors.openings} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="status">Status</Label>
                                <SearchSelect id="status" value={openingForm.data.status} onChange={(e) => openingForm.setData('status', e.target.value)}>
                                    {statuses.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="opened_on">Opened</Label>
                                <Input
                                    id="opened_on"
                                    type="date"
                                    value={openingForm.data.opened_on}
                                    onChange={(e) => openingForm.setData('opened_on', e.target.value)}
                                />
                                <InputError message={openingForm.errors.opened_on} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="closes_on">Closes</Label>
                                <Input
                                    id="closes_on"
                                    type="date"
                                    value={openingForm.data.closes_on}
                                    onChange={(e) => openingForm.setData('closes_on', e.target.value)}
                                />
                                <InputError message={openingForm.errors.closes_on} />
                            </div>
                            <div className="grid gap-2 md:col-span-4">
                                <Label htmlFor="description">What the role is</Label>
                                <textarea
                                    id="description"
                                    className="border-input bg-background min-h-20 rounded-md border p-2 text-sm"
                                    value={openingForm.data.description}
                                    onChange={(e) => openingForm.setData('description', e.target.value)}
                                />
                            </div>
                            <div className="flex gap-2 md:col-span-4">
                                <Button type="submit" disabled={openingForm.processing}>
                                    {editingOpening ? 'Save opening' : 'Add opening'}
                                </Button>
                                {editingOpening && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => {
                                            setEditingOpening(null);
                                            openingForm.reset();
                                        }}
                                    >
                                        Cancel
                                    </Button>
                                )}
                            </div>
                        </form>
                    )}

                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="status_filter">Show</Label>
                            <SearchSelect id="status_filter" value={filters.status} onChange={(e) => applyFilters({ status: e.target.value })}>
                                <option value="all">All openings</option>
                                {statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {openings.map((opening) => (
                            <div key={opening.id} className="flex flex-col gap-2 rounded-lg border p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <h3 className="font-medium">{opening.title}</h3>
                                    <Badge variant={opening.status === 'open' ? 'secondary' : 'outline'}>{opening.status_label}</Badge>
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    {[opening.department, opening.designation].filter(Boolean).join(' / ') || 'No department set'}
                                </p>
                                {opening.description && <p className="text-muted-foreground text-sm">{opening.description}</p>}
                                <div className="text-muted-foreground mt-auto flex flex-wrap gap-2 text-xs">
                                    <Badge variant="outline">
                                        {opening.still_wanted} of {opening.openings} still wanted
                                    </Badge>
                                    <Badge variant="outline">{opening.applicants_count} applicants</Badge>
                                    {opening.closes_on && <span>closes {opening.closes_on}</span>}
                                </div>
                                <div className="flex gap-1">
                                    <Button variant="ghost" size="sm" onClick={() => applyFilters({ opening: String(opening.id) })}>
                                        See applicants
                                    </Button>
                                    {can.manage && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                setEditingOpening(opening);
                                                openingForm.setData({
                                                    title: opening.title,
                                                    department_id: '',
                                                    designation_id: '',
                                                    description: opening.description ?? '',
                                                    openings: opening.openings,
                                                    status: opening.status,
                                                    opened_on: opening.opened_on ?? today(),
                                                    closes_on: opening.closes_on ?? '',
                                                });
                                            }}
                                        >
                                            Edit
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                        {openings.length === 0 && (
                            <p className="text-muted-foreground col-span-full rounded-lg border p-6 text-center text-sm">No openings.</p>
                        )}
                    </div>
                </section>

                <section className="flex flex-col gap-4">
                    <div>
                        <h2 className="text-lg font-medium">Applicants</h2>
                        <p className="text-muted-foreground text-sm">
                            Marking somebody hired records the decision. It does not create an employee: a joining date, an employment type and a
                            salary structure are asked for in People, and guessing them would put a wrong number into their first payroll.
                        </p>
                    </div>

                    {can.manage && (
                        <form onSubmit={submitApplicant} className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="job_opening_id">For which role</Label>
                                <SearchSelect
                                    id="job_opening_id"
                                    value={applicantForm.data.job_opening_id}
                                    onChange={(e) => applicantForm.setData('job_opening_id', e.target.value)}
                                >
                                    <option value="">Choose an opening</option>
                                    {openings.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.title}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={applicantForm.errors.job_opening_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="applicant_name">Name</Label>
                                <Input
                                    id="applicant_name"
                                    value={applicantForm.data.name}
                                    onChange={(e) => applicantForm.setData('name', e.target.value)}
                                />
                                <InputError message={applicantForm.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="applicant_phone">Phone</Label>
                                <Input
                                    id="applicant_phone"
                                    value={applicantForm.data.phone}
                                    onChange={(e) => applicantForm.setData('phone', e.target.value)}
                                    placeholder="01712345678"
                                />
                                <InputError message={applicantForm.errors.phone} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="applicant_email">Email</Label>
                                <Input
                                    id="applicant_email"
                                    value={applicantForm.data.email}
                                    onChange={(e) => applicantForm.setData('email', e.target.value)}
                                />
                                <InputError message={applicantForm.errors.email} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="applicant_source">Where from</Label>
                                <Input
                                    id="applicant_source"
                                    value={applicantForm.data.source}
                                    onChange={(e) => applicantForm.setData('source', e.target.value)}
                                    placeholder="Referral, Facebook, walk-in"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="expected_salary">Salary asked for</Label>
                                <Input
                                    id="expected_salary"
                                    inputMode="decimal"
                                    value={applicantForm.data.expected_salary}
                                    onChange={(e) => applicantForm.setData('expected_salary', e.target.value)}
                                />
                                <InputError message={applicantForm.errors.expected_salary} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="applied_on">Applied on</Label>
                                <Input
                                    id="applied_on"
                                    type="date"
                                    value={applicantForm.data.applied_on}
                                    onChange={(e) => applicantForm.setData('applied_on', e.target.value)}
                                />
                                <InputError message={applicantForm.errors.applied_on} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="cv">CV</Label>
                                <Input id="cv" type="file" onChange={(e) => applicantForm.setData('cv', e.target.files?.[0] ?? null)} />
                                <InputError message={applicantForm.errors.cv} />
                            </div>
                            <div className="grid gap-2 md:col-span-4">
                                <Label htmlFor="applicant_note">Note</Label>
                                <Input
                                    id="applicant_note"
                                    value={applicantForm.data.note}
                                    onChange={(e) => applicantForm.setData('note', e.target.value)}
                                />
                            </div>
                            <div className="md:col-span-4">
                                <Button type="submit" disabled={applicantForm.processing}>
                                    Add applicant
                                </Button>
                            </div>
                        </form>
                    )}

                    <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="applicant_search">Search</Label>
                            <Input
                                id="applicant_search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && applyFilters({ search })}
                                placeholder="Name or email"
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="opening_filter">Opening</Label>
                            <SearchSelect id="opening_filter" value={filters.opening} onChange={(e) => applyFilters({ opening: e.target.value })}>
                                <option value="">Every opening</option>
                                {openings.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.title}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="stage_filter">Stage</Label>
                            <SearchSelect id="stage_filter" value={filters.stage} onChange={(e) => applyFilters({ stage: e.target.value })}>
                                <option value="">Every stage</option>
                                {stages.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                    </div>

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Applicant</th>
                                    <th className="p-3">For</th>
                                    <th className="p-3">Stage</th>
                                    <th className="p-3">Asked for</th>
                                    <th className="p-3">Applied</th>
                                    <th className="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {applicants.data.map((applicant) => (
                                    <tr key={applicant.id} className="border-t align-top">
                                        <td className="p-3">
                                            <div className="font-medium">{applicant.name}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {[applicant.phone, applicant.email].filter(Boolean).join(' / ')}
                                            </div>
                                            {applicant.source && <div className="text-muted-foreground text-xs">via {applicant.source}</div>}
                                        </td>
                                        <td className="p-3">{applicant.opening}</td>
                                        <td className="p-3">
                                            <Badge className={stageTone[applicant.stage]}>{applicant.stage_label}</Badge>
                                            {applicant.outcome_reason && (
                                                <div className="text-muted-foreground mt-1 text-xs">{applicant.outcome_reason}</div>
                                            )}
                                            {applicant.hired_employee && (
                                                <div className="text-muted-foreground mt-1 text-xs">as {applicant.hired_employee}</div>
                                            )}
                                            {applicant.stage === 'hired' && !applicant.hired_employee && (
                                                <div className="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                                    No employee record linked yet
                                                </div>
                                            )}
                                        </td>
                                        <td className="p-3 whitespace-nowrap">{applicant.expected_salary ?? '-'}</td>
                                        <td className="p-3 whitespace-nowrap">{applicant.applied_on}</td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {applicant.cv_url && (
                                                <Button variant="ghost" size="sm" asChild>
                                                    <a href={applicant.cv_url}>CV</a>
                                                </Button>
                                            )}
                                            {can.manage && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        setMoving(applicant);
                                                        setMoveStage(applicant.stage);
                                                        setMoveReason(applicant.outcome_reason ?? '');
                                                        setMoveEmployee('');
                                                    }}
                                                >
                                                    Move
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {applicants.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                            No applicants yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {moving && (
                        <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                            <div className="md:col-span-4">
                                <h3 className="font-medium">Move {moving.name}</h3>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="move_stage">To</Label>
                                <SearchSelect id="move_stage" value={moveStage} onChange={(e) => setMoveStage(e.target.value)}>
                                    {stages.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                            {needsReason && (
                                <div className="grid gap-2 md:col-span-2">
                                    <Label htmlFor="move_reason">Why</Label>
                                    <Input id="move_reason" value={moveReason} onChange={(e) => setMoveReason(e.target.value)} />
                                </div>
                            )}
                            {moveStage === 'hired' && (
                                <div className="grid gap-2 md:col-span-2">
                                    <Label htmlFor="move_employee">Their employee record, once it exists</Label>
                                    <SearchSelect id="move_employee" value={moveEmployee} onChange={(e) => setMoveEmployee(e.target.value)}>
                                        <option value="">Not created yet</option>
                                        {employees.map((e) => (
                                            <option key={e.id} value={e.id}>
                                                {e.name}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </div>
                            )}
                            <div className="flex items-end gap-2 md:col-span-4">
                                <Button type="button" onClick={submitMove}>
                                    Move
                                </Button>
                                <Button type="button" variant="ghost" onClick={() => setMoving(null)}>
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    )}

                    <Pagination meta={applicants} />
                </section>
            </div>
        </AppLayout>
    );
}
