<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\RequestLeave;
use App\Modules\Hrm\Actions\RequestMovement;
use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Support\LeaveBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee self-service leave (LEV-05) and movement (POL-10).
 *
 * Self-scope, like the rest of ESS: the employee comes from the signed-in user,
 * so nobody can request against someone else's balance. Balances are shown with
 * the ledger behind them, because "you have 8 days" is only useful if the
 * employee can see how it got there.
 */
class SelfLeaveController extends Controller
{
    public function show(Request $request, LeaveBalance $balances): Response|RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')
                ->with('error', 'Your user account is not linked to an employee record yet.');
        }

        $year = $request->integer('year') ?: (int) Carbon::today()->year;

        return Inertia::render('hrm/leave/my-leave', [
            'year' => $year,
            'employee' => ['name' => $employee->fullName(), 'employee_code' => $employee->employee_code],
            'balances' => $balances->summaryFor($employee->id, $year),
            'ledger' => $balances->entriesFor($employee->id, $year)->map(fn ($entry) => [
                'id' => $entry->id,
                'date' => Carbon::parse($entry->occurred_on)->toDateString(),
                'type' => $entry->leaveType?->name,
                'entry_type' => $entry->entry_type->label(),
                'days' => (float) $entry->days,
                'note' => $entry->note,
            ]),
            'requests' => LeaveRequest::with(['leaveType:id,name', 'approvalRequest.workflow.stages'])
                ->where('employee_id', $employee->id)
                ->orderByDesc('id')->limit(25)->get()
                ->map(fn (LeaveRequest $r) => [
                    'id' => $r->id,
                    'leave_type' => $r->leaveType?->name,
                    'start_date' => Carbon::parse($r->start_date)->toDateString(),
                    'end_date' => Carbon::parse($r->end_date)->toDateString(),
                    'days' => (float) $r->days,
                    'unpaid_days' => (float) $r->unpaid_days,
                    'status' => $r->approvalRequest?->status->value ?? 'pending',
                    'stage' => $r->approvalRequest && $r->approvalRequest->totalStages() > 1
                        ? $r->approvalRequest->currentStage()?->name : null,
                ]),
            'movements' => Movement::with('approvalRequest')
                ->where('employee_id', $employee->id)
                ->orderByDesc('id')->limit(25)->get()
                ->map(fn (Movement $m) => [
                    'id' => $m->id,
                    'summary' => $m->summaryLine(),
                    'status' => $m->approvalRequest?->status->value ?? 'pending',
                ]),
            'leave_types' => LeaveType::active()->orderBy('name')->get()
                ->map(fn (LeaveType $t) => [
                    'id' => $t->id, 'name' => $t->name,
                    'allows_half_day' => $t->allows_half_day,
                    'deducts_balance' => $t->deducts_balance,
                ]),
            'portions' => collect(LeaveDayPortion::cases())
                ->map(fn (LeaveDayPortion $p) => ['value' => $p->value, 'label' => $p->label()]),
            'movement_types' => collect(MovementType::cases())
                ->map(fn (MovementType $t) => ['value' => $t->value, 'label' => $t->label()]),
        ]);
    }

    public function storeLeave(Request $request, RequestLeave $action): RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')->with('error', 'Your user account is not linked to an employee record yet.');
        }

        $validated = $request->validate([
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'day_portion' => ['required', new EnumRule(LeaveDayPortion::class)],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $action->handle(
            $employee,
            LeaveType::findOrFail($validated['leave_type_id']),
            $validated['start_date'],
            $validated['end_date'],
            LeaveDayPortion::from($validated['day_portion']),
            $validated['reason'],
            $request->user(),
        );

        return back()->with('success', 'Leave requested. Your manager will review it.');
    }

    public function storeMovement(Request $request, RequestMovement $action): RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')->with('error', 'Your user account is not linked to an employee record yet.');
        }

        $validated = $request->validate([
            'type' => ['required', new EnumRule(MovementType::class)],
            'movement_date' => ['required', 'date'],
            'from_time' => ['nullable', 'date_format:H:i'],
            'to_time' => ['nullable', 'date_format:H:i', 'after:from_time'],
            'place' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $action->handle(
            $employee,
            MovementType::from($validated['type']),
            $validated['movement_date'],
            $validated,
            $request->user(),
        );

        return back()->with('success', 'Movement requested.');
    }
}
