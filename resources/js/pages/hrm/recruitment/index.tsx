import { Pagination } from '@/components/data-table/pagination';
import { Field, FormRow } from '@/components/form/field';
import { ApplicantDialog } from '@/components/hrm/applicant-dialog';
import { JobOpeningDialog } from '@/components/hrm/job-opening-dialog';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Opening {
    id: number;
    title: string;
    department_id: number | null;
    department: string | null;
    designation_id: number | null;
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
    // ⭐ Add and edit happen in popups (Joy, 14 Oct 2026: "click add button pop
    // up will add, same when edit"). `undefined` = closed, `null` = a new one.
    const [openingDialog, setOpeningDialog] = useState<Opening | null | undefined>(undefined);
    const [applicantOpen, setApplicantOpen] = useState(false);
    const [moving, setMoving] = useState<Applicant | null>(null);
    const [moveStage, setMoveStage] = useState('');
    const [moveReason, setMoveReason] = useState('');
    const [moveEmployee, setMoveEmployee] = useState('');

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
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-medium">Openings</h2>
                        {can.manage && <Button onClick={() => setOpeningDialog(null)}>Add opening</Button>}
                    </div>

                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="status_filter">Show</Label>
                            <SearchSelect fit id="status_filter" value={filters.status} onChange={(e) => applyFilters({ status: e.target.value })}>
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
                                        <Button variant="ghost" size="sm" onClick={() => setOpeningDialog(opening)}>
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
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-medium">Applicants</h2>
                        {can.manage && <Button onClick={() => setApplicantOpen(true)}>Add applicant</Button>}
                    </div>

                    <FormRow className="rounded-lg border p-4">
                        <Field label="Search" htmlFor="applicant_search" size="lg">
                            <Input
                                id="applicant_search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && applyFilters({ search })}
                                placeholder="Name or email"
                            />
                        </Field>
                        <Field label="Opening" htmlFor="opening_filter">
                            <SearchSelect id="opening_filter" value={filters.opening} onChange={(e) => applyFilters({ opening: e.target.value })}>
                                <option value="">Every opening</option>
                                {openings.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.title}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Stage" htmlFor="stage_filter">
                            <SearchSelect id="stage_filter" value={filters.stage} onChange={(e) => applyFilters({ stage: e.target.value })}>
                                <option value="">Every stage</option>
                                {stages.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormRow>

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
                                                <div className="mt-1 text-xs text-amber-700 dark:text-amber-400">No employee record linked yet</div>
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
                        <div className="flex flex-col gap-3 rounded-lg border p-4">
                            <h3 className="font-medium">Move {moving.name}</h3>
                            <FormRow>
                                <Field label="To" htmlFor="move_stage" required>
                                    <SearchSelect id="move_stage" value={moveStage} onChange={(e) => setMoveStage(e.target.value)}>
                                        {stages.map((s) => (
                                            <option key={s.value} value={s.value}>
                                                {s.label}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </Field>
                                {needsReason && (
                                    <Field label="Why" htmlFor="move_reason" size="grow" required>
                                        <Input id="move_reason" aria-required value={moveReason} onChange={(e) => setMoveReason(e.target.value)} />
                                    </Field>
                                )}
                                {moveStage === 'hired' && (
                                    <Field label="Their employee record, once it exists" htmlFor="move_employee">
                                        <SearchSelect id="move_employee" value={moveEmployee} onChange={(e) => setMoveEmployee(e.target.value)}>
                                            <option value="">Not created yet</option>
                                            {employees.map((e) => (
                                                <option key={e.id} value={e.id}>
                                                    {e.name}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </Field>
                                )}
                                <div className="flex items-center gap-2 self-end">
                                    <Button type="button" onClick={submitMove}>
                                        Move
                                    </Button>
                                    <Button type="button" variant="ghost" onClick={() => setMoving(null)}>
                                        Cancel
                                    </Button>
                                </div>
                            </FormRow>
                        </div>
                    )}

                    <Pagination meta={applicants} />
                </section>

                {can.manage && (
                    <>
                        <JobOpeningDialog
                            open={openingDialog !== undefined}
                            onOpenChange={(open) => !open && setOpeningDialog(undefined)}
                            opening={openingDialog ?? null}
                            departments={departments}
                            designations={designations}
                            statuses={statuses}
                        />
                        <ApplicantDialog open={applicantOpen} onOpenChange={setApplicantOpen} openings={openings} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
