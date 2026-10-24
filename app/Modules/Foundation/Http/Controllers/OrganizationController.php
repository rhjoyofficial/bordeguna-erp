<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Foundation\Corrections\Recycle\BinsFromList;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\MasterRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages the business's organization structure: the Office → Department →
 * Section containment tree plus the flat Designation (job-title) lookup
 * (ORG-05). All four are business-scoped (BelongsToBusiness), so route-bound
 * models are automatically confined to the current business and a unit from
 * another business simply 404s — no manual tenant guard needed.
 *
 * Units deactivate-not-delete once they carry history; hard delete is allowed
 * only to clean up a mistake, and is blocked while the unit still contains
 * children (an office with departments, a department with sections) or while
 * HR records point at it (FND-002, 14 Oct 2026: see inUse()).
 */
class OrganizationController extends Controller
{
    use BinsFromList;

    public function index(): Response
    {
        $this->authorize('foundation.organization.manage');

        $offices = Office::with([
            'departments' => fn ($query) => $query->orderBy('name'),
            'departments.sections' => fn ($query) => $query->orderBy('name'),
        ])->orderBy('name')->get()->map(fn (Office $office) => [
            'id' => $office->id,
            'name' => $office->name,
            'code' => $office->code,
            'is_active' => $office->is_active,
            'geofence_latitude' => $office->geofence_latitude,
            'geofence_longitude' => $office->geofence_longitude,
            'geofence_radius_meters' => $office->geofence_radius_meters,
            'geofence_enforced' => $office->geofence_enforced,
            'departments' => $office->departments->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'is_active' => $department->is_active,
                'sections' => $department->sections->map(fn (Section $section) => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'is_active' => $section->is_active,
                ])->values(),
            ])->values(),
        ]);

        $user = request()->user();

        return Inertia::render('settings/organization', [
            'offices' => $offices,
            'designations' => Designation::orderBy('name')->get(['id', 'name', 'is_active']),
            // ⭐ B24 (24 Oct 2026, Q5 = A): each unit's Delete / Switch off /
            // Reactivate, greyed with what uses it.
            'recordActions' => [
                'office' => MasterRecords::rows('office', Office::all(), $user),
                'department' => MasterRecords::rows('department', Department::all(), $user),
                'section' => MasterRecords::rows('section', Section::all(), $user),
                'designation' => MasterRecords::rows('designation', Designation::all(), $user),
            ],
            'deleteReasons' => MasterRecords::reasons(),
        ]);
    }

    // ---- Offices -----------------------------------------------------------

    public function storeOffice(Request $request): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('offices', 'name')->where('business_id', $businessId)],
            'code' => ['nullable', 'string', 'max:50'],
        ]);

        Office::create($data);

        return back()->with('success', 'Office created.');
    }

    public function updateOffice(Request $request, Office $office): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('offices', 'name')->where('business_id', $businessId)->ignore($office->id)],
            'code' => ['nullable', 'string', 'max:50'],
        ]);

        $office->update($data);

        return back()->with('success', 'Office updated.');
    }

    /**
     * The office's optional clock-in geofence (ATT-05). Kept separate from the
     * rename form because it is an attendance concern, not part of naming an
     * office — and because switching enforcement on is a decision with a
     * visible consequence for every employee who clocks in there.
     */
    public function updateOfficeGeofence(Request $request, Office $office): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $data = $request->validate([
            'geofence_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'geofence_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_radius_meters' => ['nullable', 'integer', 'min:10', 'max:100000'],
            'geofence_enforced' => ['required', 'boolean'],
        ]);

        // Enforcing an unconfigured fence would flag every punch for review, so
        // refuse the combination outright rather than let HR discover it later.
        if ($data['geofence_enforced'] && (
            $data['geofence_latitude'] === null
            || $data['geofence_longitude'] === null
            || $data['geofence_radius_meters'] === null
        )) {
            return back()->withErrors([
                'geofence_enforced' => 'Set the location and radius before enforcing the geofence.',
            ]);
        }

        $office->update($data);

        return back()->with('success', 'Geofence updated.');
    }

    public function toggleOffice(Office $office): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $office->update(['is_active' => ! $office->is_active]);

        return back()->with('success', $office->is_active ? 'Office reactivated.' : 'Office deactivated.');
    }

    public function destroyOffice(Request $request, Office $office): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        if ($office->departments()->exists()) {
            return back()->with('error', "Remove this office's departments before deleting it.");
        }

        // ⚠ Things are kept here (asset register, 16 Sep 2026). The foreign key
        // refuses too; this turns the refusal into a sentence, not a 500.
        if (AssetItem::query()->where('office_id', $office->id)->exists()) {
            return back()->with('error', 'Things in the asset register are kept at this office. Move them or switch the office off instead of deleting it.');
        }

        if ($refusal = $this->inUse('office', $office->id, 'office')) {
            return back()->with('error', $refusal);
        }

        // ⭐ B24 (24 Oct 2026, Q5 = A): to the Recycle bin with a reason.
        return $this->binFromList($request, $office);
    }

    // ---- Departments -------------------------------------------------------

    public function storeDepartment(Request $request): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'office_id' => ['required', Rule::exists('offices', 'id')->where('business_id', $businessId)],
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where('office_id', $request->integer('office_id'))],
        ]);

        Department::create($data);

        return back()->with('success', 'Department created.');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where('office_id', $department->office_id)->ignore($department->id)],
        ]);

        $department->update($data);

        return back()->with('success', 'Department updated.');
    }

    public function toggleDepartment(Department $department): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $department->update(['is_active' => ! $department->is_active]);

        return back()->with('success', $department->is_active ? 'Department reactivated.' : 'Department deactivated.');
    }

    public function destroyDepartment(Request $request, Department $department): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        if ($department->sections()->exists()) {
            return back()->with('error', "Remove this department's sections before deleting it.");
        }

        if ($refusal = $this->inUse('department', $department->id, 'department')) {
            return back()->with('error', $refusal);
        }

        // ⭐ B24 (24 Oct 2026, Q5 = A): to the Recycle bin with a reason.
        return $this->binFromList($request, $department);
    }

    // ---- Sections ----------------------------------------------------------

    public function storeSection(Request $request): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'department_id' => ['required', Rule::exists('departments', 'id')->where('business_id', $businessId)],
            'name' => ['required', 'string', 'max:255', Rule::unique('sections', 'name')->where('department_id', $request->integer('department_id'))],
        ]);

        Section::create($data);

        return back()->with('success', 'Section created.');
    }

    public function updateSection(Request $request, Section $section): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('sections', 'name')->where('department_id', $section->department_id)->ignore($section->id)],
        ]);

        $section->update($data);

        return back()->with('success', 'Section updated.');
    }

    public function toggleSection(Section $section): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $section->update(['is_active' => ! $section->is_active]);

        return back()->with('success', $section->is_active ? 'Section reactivated.' : 'Section deactivated.');
    }

    public function destroySection(Request $request, Section $section): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        if ($refusal = $this->inUse('section', $section->id, 'section')) {
            return back()->with('error', $refusal);
        }

        // ⭐ B24 (24 Oct 2026, Q5 = A): to the Recycle bin with a reason.
        return $this->binFromList($request, $section);
    }

    // ---- Designations ------------------------------------------------------

    public function storeDesignation(Request $request): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('designations', 'name')->where('business_id', $businessId)],
        ]);

        Designation::create($data);

        return back()->with('success', 'Designation created.');
    }

    public function updateDesignation(Request $request, Designation $designation): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('designations', 'name')->where('business_id', $businessId)->ignore($designation->id)],
        ]);

        $designation->update($data);

        return back()->with('success', 'Designation updated.');
    }

    public function toggleDesignation(Designation $designation): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $designation->update(['is_active' => ! $designation->is_active]);

        return back()->with('success', $designation->is_active ? 'Designation reactivated.' : 'Designation deactivated.');
    }

    public function destroyDesignation(Request $request, Designation $designation): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        if ($refusal = $this->inUse('designation', $designation->id, 'designation')) {
            return back()->with('error', $refusal);
        }

        // ⭐ B24 (24 Oct 2026, Q5 = A): to the Recycle bin with a reason.
        return $this->binFromList($request, $designation);
    }

    /**
     * FND-002 (audit 14 Oct 2026). HR records point at these units through
     * foreign keys that are nullOnDelete, so a delete used to quietly rewrite
     * history: a department's own attendance policy lost its department and so
     * became the policy of the WHOLE business (a NULL department_id is exactly
     * how a business-wide policy is stored), and assignment history lost its
     * office, department, section or designation with nothing to tell them
     * apart from people who were never assigned. A unit anything still points
     * at is refused here with a sentence pointing to Deactivate, which keeps
     * every record intact. Read with DB::table on purpose: these are HRM
     * tables (Foundation does not depend on HRM models) and the unit's id is
     * already business-confined by the route binding.
     *
     * Returns the refusal sentence, or null when the unit is free to delete.
     */
    private function inUse(string $unit, int $id, string $label): ?string
    {
        $column = $unit.'_id';

        if (DB::table('employee_assignments')->where($column, $id)->exists()) {
            return "Employee assignments (current or past) use this {$label}. Deactivate it instead of deleting it, so their history keeps its {$label}.";
        }

        if ($unit === 'department' && DB::table('attendance_policies')->where('department_id', $id)->exists()) {
            return "This department has its own attendance policy. Deleting it would turn that policy into the whole business's policy. Deactivate the department instead.";
        }

        if (in_array($unit, ['department', 'designation'], true)
            && DB::table('job_openings')->where($column, $id)->exists()) {
            return "A job opening is recruiting for this {$label}. Deactivate it instead of deleting it.";
        }

        return null;
    }
}
