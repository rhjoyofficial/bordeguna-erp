<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 * children (an office with departments, a department with sections).
 */
class OrganizationController extends Controller
{
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

        return Inertia::render('settings/organization', [
            'offices' => $offices,
            'designations' => Designation::orderBy('name')->get(['id', 'name', 'is_active']),
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

    public function destroyOffice(Office $office): RedirectResponse
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

        $office->delete();

        return back()->with('success', 'Office deleted.');
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

    public function destroyDepartment(Department $department): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        if ($department->sections()->exists()) {
            return back()->with('error', "Remove this department's sections before deleting it.");
        }

        $department->delete();

        return back()->with('success', 'Department deleted.');
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

    public function destroySection(Section $section): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $section->delete();

        return back()->with('success', 'Section deleted.');
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

    public function destroyDesignation(Designation $designation): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');
        $designation->delete();

        return back()->with('success', 'Designation deleted.');
    }
}
