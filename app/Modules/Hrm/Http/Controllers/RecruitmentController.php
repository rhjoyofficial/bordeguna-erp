<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\ApplicantStage;
use App\Modules\Hrm\Enums\JobOpeningStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\JobApplicant;
use App\Modules\Hrm\Models\JobOpening;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Openings, and the people who applied for them.
 *
 * ⚠⚠ NOTHING HERE CREATES AN EMPLOYEE. Marking somebody hired records the
 * decision; the employee record is made in HRM, where a joining date, an
 * employment type, a probation length and a salary structure are asked for. A
 * recruitment screen knows none of those, and inventing them is a wrong number
 * in that person's first payroll.
 */
class RecruitmentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'status' => (string) $request->query('status', 'open'),
            'opening' => (string) $request->query('opening', ''),
            'stage' => (string) $request->query('stage', ''),
            'search' => trim((string) $request->query('search', '')),
        ];

        $openings = JobOpening::query()
            ->with(['department:id,name', 'designation:id,name'])
            ->withCount('applicants')
            ->when($filters['status'] !== '' && $filters['status'] !== 'all',
                fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderByDesc('opened_on')
            ->get()
            ->map(fn (JobOpening $o) => [
                'id' => $o->id,
                'title' => $o->title,
                'department' => $o->department?->name,
                'designation' => $o->designation?->name,
                'description' => $o->description,
                'openings' => $o->openings,
                // ⚠ Two openings with one hire is STILL an open role.
                'still_wanted' => $o->stillWanted(),
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'opened_on' => $o->opened_on?->toDateString(),
                'closes_on' => $o->closes_on?->toDateString(),
                'applicants_count' => $o->applicants_count,
            ]);

        $applicants = JobApplicant::query()
            ->with(['opening:id,title', 'hiredEmployee:id,first_name,last_name'])
            ->when($filters['opening'] !== '', fn (Builder $q) => $q->where('job_opening_id', $filters['opening']))
            ->when($filters['stage'] !== '', fn (Builder $q) => $q->where('stage', $filters['stage']))
            ->when($filters['search'] !== '', function (Builder $q) use ($filters) {
                $term = $filters['search'];

                $q->where(fn (Builder $inner) => $inner->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
            })
            ->orderByDesc('applied_on')
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (JobApplicant $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'phone' => $a->phone,
                'email' => $a->email,
                'source' => $a->source,
                'expected_salary' => $a->expected_salary,
                'stage' => $a->stage->value,
                'stage_label' => $a->stage->label(),
                'closed' => $a->stage->isClosed(),
                'note' => $a->note,
                'outcome_reason' => $a->outcome_reason,
                'opening' => $a->opening?->title,
                'opening_id' => $a->job_opening_id,
                'applied_on' => $a->applied_on?->toDateString(),
                'hired_employee' => $a->hiredEmployee?->fullName(),
                'cv_url' => ($cv = $a->attachmentInCollection('cv')) ? route('attachments.show', $cv) : null,
            ]);

        return Inertia::render('hrm/recruitment/index', [
            'openings' => $openings,
            'applicants' => $applicants,
            'filters' => $filters,
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'designations' => Designation::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages' => collect(ApplicantStage::cases())
                ->map(fn (ApplicantStage $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            'statuses' => collect(JobOpeningStatus::cases())
                ->map(fn (JobOpeningStatus $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            // ⚠ Offered so a hire can be LINKED to a record that already exists.
            // Nothing here creates one.
            'employees' => Employee::query()->orderBy('employee_code')->get(['id', 'first_name', 'last_name'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullName()]),
            'can' => ['manage' => $request->user()->can('recruitment.manage')],
        ]);
    }

    public function storeOpening(Request $request): RedirectResponse
    {
        JobOpening::create(array_merge($this->openingRules($request), [
            'business_id' => app(BusinessContext::class)->id(),
            'created_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Opening added.');
    }

    public function updateOpening(Request $request, JobOpening $opening): RedirectResponse
    {
        $opening->update($this->openingRules($request));

        return back()->with('success', 'Opening saved.');
    }

    public function storeApplicant(Request $request, StoreAttachment $store): RedirectResponse
    {
        $data = $this->applicantRules($request);

        $applicant = JobApplicant::create(array_merge($data, [
            'business_id' => app(BusinessContext::class)->id(),
            'created_by' => $request->user()->id,
        ]));

        if ($request->hasFile('cv')) {
            $store->handle(
                attachable: $applicant,
                file: $request->file('cv'),
                collection: 'cv',
                uploadedBy: $request->user(),
                singleton: true,
            );
        }

        return back()->with('success', $this->savedMessage($applicant));
    }

    public function updateApplicant(Request $request, JobApplicant $applicant, StoreAttachment $store): RedirectResponse
    {
        $applicant->update($this->applicantRules($request));

        if ($request->hasFile('cv')) {
            $store->handle($applicant, $request->file('cv'), 'cv', $request->user(), true);
        }

        return back()->with('success', $this->savedMessage($applicant));
    }

    /**
     * Move somebody along the pipeline.
     */
    public function moveApplicant(Request $request, JobApplicant $applicant): RedirectResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(ApplicantStage::values())],
            'outcome_reason' => ['nullable', 'string', 'max:255'],
            'hired_employee_id' => ['nullable', Rule::exists('employees', 'id')],
        ]);

        $stage = ApplicantStage::from($data['stage']);

        // ⚠⚠ A rejection with no reason is a name on a list nobody can explain,
        // and the same person is invited back next year. Required at the moment
        // it is decided, because nobody ever goes back to fill it in.
        if ($stage->needsReason() && trim((string) ($data['outcome_reason'] ?? '')) === '') {
            return back()->withErrors(['outcome_reason' => 'Say why, or nobody will remember in six months.']);
        }

        $applicant->update([
            'stage' => $stage,
            'outcome_reason' => $stage->needsReason() ? $data['outcome_reason'] : null,
            // ⚠ Only ever a link to an employee somebody already created.
            'hired_employee_id' => $stage === ApplicantStage::Hired ? ($data['hired_employee_id'] ?? null) : null,
        ]);

        if ($stage === ApplicantStage::Hired && ($data['hired_employee_id'] ?? null) === null) {
            return back()->with('success', "{$applicant->name} marked hired. Create their employee record in People, then come back and link it, or nobody will be able to answer who was hired for this role.");
        }

        return back()->with('success', "{$applicant->name} moved to {$stage->label()}.");
    }

    /**
     * @return array<string,mixed>
     */
    private function openingRules(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'designation_id' => ['nullable', Rule::exists('designations', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'description' => ['nullable', 'string', 'max:5000'],
            'openings' => ['required', 'integer', 'min:1', 'max:500'],
            'status' => ['required', Rule::in(JobOpeningStatus::values())],
            'opened_on' => ['required', 'date'],
            'closes_on' => ['nullable', 'date', 'after_or_equal:opened_on'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function applicantRules(Request $request): array
    {
        $data = $request->validate([
            'job_opening_id' => ['required', Rule::exists('job_openings', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'name' => ['required', 'string', 'max:255'],
            // ⚠ One way to reach them at least, or the application cannot be
            // acted on at all.
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'source' => ['nullable', 'string', 'max:255'],
            'expected_salary' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'applied_on' => ['required', 'date'],
            // ⚠ An allow-list, not a size cap alone. Same reasoning as the file
            // manager: this box is reachable by anyone who may record applicants.
            'cv' => ['nullable', 'file', 'max:5120', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
        ], [
            'phone.required_without' => 'Give a phone number or an email, or nobody can contact this applicant.',
            'email.required_without' => 'Give a phone number or an email, or nobody can contact this applicant.',
        ]);

        unset($data['cv']);

        return $data;
    }

    /**
     * ⚠ Duplicates WARN and never block: the same person applying for two roles
     * is normal, and a family sharing a number is as real here as in CRM.
     */
    private function savedMessage(JobApplicant $applicant): string
    {
        $others = $applicant->possibleDuplicates()->pluck('name');

        if ($others->isEmpty()) {
            return "{$applicant->name} saved.";
        }

        return "{$applicant->name} saved. That number has applied before, under: ".$others->implode(', ').'.';
    }
}
