<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Onboarding / offboarding checklists (Module 5, Slice 4).
 *
 * A joiner and a leaver each have a short list of things that must actually
 * happen. The items are DATA (defaults planted on first visit, editable
 * later); the progress records WHO ticked WHAT and WHEN, per employee.
 * Ticking is reversible - people mis-click - the final state is the record.
 */
class ChecklistController extends Controller
{
    private const DEFAULTS = [
        'onboarding' => [
            'NID / birth certificate copy collected',
            'Appointment letter printed and signed',
            'Photo taken and uploaded',
            'Biometric attendance enrolled',
            'Salary structure entered',
            'bKash / bank details for salary collected',
            'ERP login account created',
            'Introduced to supervisor and team',
        ],
        'offboarding' => [
            'Resignation / separation recorded with date',
            'Company assets returned (phone, keys, ID card)',
            'Handover to the supervisor completed',
            'Advances and loans checked (settlement statement)',
            'Final settlement statement printed',
            'Experience certificate drafted',
            'ERP login disabled',
            'Biometric enrolment removed',
        ],
    ];

    public function show(Employee $employee): Response
    {
        $this->authorize('hrm.employees.manage');

        $businessId = app(BusinessContext::class)->id();

        // First visit plants the defaults, idempotently. Items are data:
        // renaming or adding later touches rows, not code.
        foreach (self::DEFAULTS as $kind => $titles) {
            foreach ($titles as $sort => $title) {
                DB::table('hr_checklist_items')->updateOrInsert(
                    ['business_id' => $businessId, 'kind' => $kind, 'title' => $title],
                    ['sort' => $sort, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }

        $progress = DB::table('hr_checklist_progress')
            ->where('employee_id', $employee->id)
            ->get()
            ->keyBy('item_id');

        $userNames = DB::table('users')->whereIn('id', $progress->pluck('done_by'))->pluck('name', 'id');

        $list = fn (string $kind) => DB::table('hr_checklist_items')
            ->where('business_id', $businessId)
            ->where('kind', $kind)
            ->where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'done' => isset($progress[$item->id]),
                'done_by' => isset($progress[$item->id]) ? ($userNames[$progress[$item->id]->done_by] ?? null) : null,
                'done_at' => $progress[$item->id]->done_at ?? null,
            ])
            ->values();

        return Inertia::render('hrm/employees/checklist', [
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->fullName(),
                'is_separated' => $employee->separation_date !== null,
            ],
            'onboarding' => $list('onboarding'),
            'offboarding' => $list('offboarding'),
            // ⚠ What the person holds NOW from the asset register (slice A2),
            // not what was ever issued: current custody, plus counted pieces.
            'still_holds' => AssetItem::query()->withoutGlobalScopes()->where('custodian_employee_id', $employee->id)->orderBy('tag')->get()
                ->map(fn (AssetItem $item) => ['id' => $item->id, 'tag' => $item->tag, 'name' => $item->name, 'quantity' => 1])
                ->concat(AssetHolding::query()->withoutGlobalScopes()->with('item:id,tag,name')->where('employee_id', $employee->id)->get()
                    ->map(fn (AssetHolding $holding) => ['id' => $holding->asset_item_id, 'tag' => $holding->item?->tag, 'name' => $holding->item?->name, 'quantity' => $holding->quantity]))
                ->values(),
            'can_move_assets' => request()->user()->can('assets.items.manage'),
        ]);
    }

    public function toggle(Request $request, Employee $employee, int $item): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');

        $businessId = app(BusinessContext::class)->id();

        $exists = DB::table('hr_checklist_items')
            ->where('business_id', $businessId)->where('id', $item)->exists();
        abort_unless($exists, 404);

        $current = DB::table('hr_checklist_progress')
            ->where('employee_id', $employee->id)
            ->where('item_id', $item)
            ->first();

        if ($current !== null) {
            // A mis-click is reversible; the final state is the record.
            DB::table('hr_checklist_progress')->where('id', $current->id)->delete();
        } else {
            DB::table('hr_checklist_progress')->insert([
                'business_id' => $businessId,
                'employee_id' => $employee->id,
                'item_id' => $item,
                'done_at' => now(),
                'done_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return back();
    }
}
