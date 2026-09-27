<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance, phase 1 (Module 5, Slice 5): goals with an owner and an
 * outcome, and ONE review per person per period - saving again replaces
 * the draft, because two reviews for one quarter is an argument, not a
 * record. ⚠ Nothing here touches pay; the targets screen calculates
 * commission and payroll pays, as always.
 */
class PerformanceController extends Controller
{
    public function show(Employee $employee): Response
    {
        $this->authorize('hrm.employees.manage');

        // ⭐ 27 Sep 2026 (HRM audit): confine to the current business.
        abort_unless($employee->assignments()->exists(), 404);

        $businessId = app(BusinessContext::class)->id();

        $userNames = fn ($ids) => DB::table('users')->whereIn('id', $ids)->pluck('name', 'id');

        $goals = DB::table('performance_goals')
            ->where('business_id', $businessId)
            ->where('employee_id', $employee->id)
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();
        $goalUsers = $userNames($goals->pluck('set_by')->merge($goals->pluck('decided_by'))->filter());

        $reviews = DB::table('performance_reviews')
            ->where('business_id', $businessId)
            ->where('employee_id', $employee->id)
            ->orderByDesc('period')
            ->get();
        $reviewUsers = $userNames($reviews->pluck('reviewed_by'));

        return Inertia::render('hrm/employees/performance', [
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->fullName(),
            ],
            'goals' => $goals->map(fn ($goal) => [
                'id' => $goal->id,
                'title' => $goal->title,
                'description' => $goal->description,
                'due_on' => $goal->due_on,
                'status' => $goal->status,
                'set_by' => $goalUsers[$goal->set_by] ?? null,
                'decided_by' => $goal->decided_by ? ($goalUsers[$goal->decided_by] ?? null) : null,
                'decided_at' => $goal->decided_at,
            ]),
            'reviews' => $reviews->map(fn ($review) => [
                'id' => $review->id,
                'period' => $review->period,
                'rating' => $review->rating,
                'strengths' => $review->strengths,
                'improvements' => $review->improvements,
                'reviewed_by' => $reviewUsers[$review->reviewed_by] ?? null,
            ]),
        ]);
    }

    public function storeGoal(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');

        // ⭐ 27 Sep 2026 (HRM audit): confine to the current business.
        abort_unless($employee->assignments()->exists(), 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'due_on' => ['nullable', 'date'],
        ]);

        DB::table('performance_goals')->insert($data + [
            'business_id' => app(BusinessContext::class)->id(),
            'employee_id' => $employee->id,
            'status' => 'open',
            'set_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Goal set.');
    }

    public function outcome(Request $request, int $goal): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');

        $row = DB::table('performance_goals')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('id', $goal)
            ->where('status', 'open')
            ->first();
        abort_if($row === null, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['achieved', 'missed'])],
        ]);

        DB::table('performance_goals')->where('id', $row->id)->update([
            'status' => $data['status'],
            'decided_at' => now(),
            'decided_by' => $request->user()->id,
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Outcome recorded.');
    }

    public function storeReview(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');

        // ⭐ 27 Sep 2026 (HRM audit): confine to the current business.
        abort_unless($employee->assignments()->exists(), 404);

        $data = $request->validate([
            'period' => ['required', 'string', 'max:20'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'strengths' => ['nullable', 'string'],
            'improvements' => ['nullable', 'string'],
        ]);

        // One review per person per period: saving again REPLACES the draft.
        DB::table('performance_reviews')->updateOrInsert(
            [
                'employee_id' => $employee->id,
                'period' => $data['period'],
            ],
            [
                'business_id' => app(BusinessContext::class)->id(),
                'rating' => $data['rating'],
                'strengths' => $data['strengths'] ?? null,
                'improvements' => $data['improvements'] ?? null,
                'reviewed_by' => $request->user()->id,
                'updated_at' => now(), 'created_at' => now(),
            ],
        );

        return back()->with('success', 'Review saved for '.$data['period'].'.');
    }
}
