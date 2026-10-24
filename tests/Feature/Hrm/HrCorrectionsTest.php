<?php

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Recycle\RecycleBin;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyLeave;
use App\Modules\Hrm\Actions\ApplyMovement;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Actions\RequestLeave;
use App\Modules\Hrm\Actions\RequestMovement;
use App\Modules\Hrm\Actions\SeparateEmployee;
use App\Modules\Hrm\Corrections\EmployeeActionPolicy;
use App\Modules\Hrm\Corrections\LeaveRequestActionPolicy;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\HrCorrectionWorkflowSeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\LeaveWorkflowSeeder;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Enums\SeparationType;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\LeaveYearEndRun;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Models\ShowCauseNotice;
use App\Modules\Hrm\Support\LeaveBalance;
use App\Support\ApprovalCards;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Corrections wave 3, HRM (24 Oct 2026; FINAL-PLAN 3.4): leave and movement
 * Cancel, Undo separation (second person), show cause Withdraw, a draft
 * employee to the Recycle bin, an employee document deleted with a reason.
 * The frozen-payroll path of Cancel is in HrCancelAfterPayrollTest.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-20 10:00:00');

    $this->business = Business::create(['name' => 'Fix Co', 'slug' => 'fix-co']);
    app(BusinessContext::class)->set($this->business->id);

    $all = ['hrm.leave.manage', 'hrm.leave.approve', 'hrm.scheduling.manage', 'hrm.attendance.manage', 'hrm.employees.manage', 'recycle_bin.manage'];
    foreach ($all as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Manager', 'HR', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo($all);
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()->givePermissionTo('hrm.leave.approve');
    Role::where('business_id', $this->business->id)->where('name', 'HR')->first()
        ->givePermissionTo(['hrm.leave.manage', 'hrm.employees.manage', 'hrm.attendance.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $make = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $user;
    };
    $this->owner = $make('Owner');
    $this->hr = $make('HR');
    $this->manager = $make('Manager');
    $this->staffUser = $make('Staff');

    (new LeaveTypeSeeder)->run();
    (new LeaveWorkflowSeeder)->run();
    (new HrCorrectionWorkflowSeeder)->run();

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $calendar = HolidayCalendar::create(['name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true]);
    AttendancePolicy::create(['name' => 'Standard', 'version' => 1, 'config' => AttendancePolicySeeder::defaultConfig(), 'effective_from' => '2026-01-01']);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001', 'user_id' => $this->staffUser->id,
        'first_name' => 'Karim', 'last_name' => 'Ahmed', 'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create(['employee_id' => $this->employee->id, 'office_id' => $this->office->id, 'is_primary' => true, 'effective_from' => '2025-01-01']);

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed', 'shift_id' => $shift->id,
        'holiday_calendar_id' => $calendar->id, 'effective_from' => '2025-01-01',
    ]);

    $this->casual = LeaveType::where('code', 'casual')->sole();
    LeaveLedgerEntry::create([
        'employee_id' => $this->employee->id, 'leave_type_id' => $this->casual->id, 'year' => 2026,
        'entry_type' => LeaveLedgerEntryType::Grant, 'days' => 10, 'occurred_on' => '2026-01-01',
    ]);

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    $this->balance = fn () => app(LeaveBalance::class)->for($this->employee->id, $this->casual->id, 2026);
    $this->day = fn (string $date) => DailyAttendance::where('employee_id', $this->employee->id)->onDate($date)->first();
});

afterEach(fn () => Carbon::setTestNow());

/** An approved, applied leave on Tue 6 and Wed 7 Oct 2026 (two days). */
function hrApprovedLeave(string $start = '2026-10-06', string $end = '2026-10-07'): LeaveRequest
{
    $leave = app(RequestLeave::class)->handle(test()->employee, test()->casual, $start, $end, LeaveDayPortion::FullDay, 'Family', test()->staffUser);
    approveIfPending($leave->approvalRequest, test()->owner);
    approveIfPending($leave->fresh()->approvalRequest, test()->owner);
    app(ApplyLeave::class)->handle($leave->fresh());

    return $leave->fresh();
}

function hrApprovedMovement(string $date = '2026-10-08'): Movement
{
    $movement = app(RequestMovement::class)->handle(test()->employee, MovementType::FieldDuty, $date, ['reason' => 'Client visit', 'place' => 'Savar'], test()->staffUser);
    approveIfPending($movement->approvalRequest, test()->owner);
    app(ApplyMovement::class)->handle($movement->fresh());

    return $movement->fresh();
}

// --- Leave Cancel ---------------------------------------------------------------

it('cancels an approved leave: the days go back, the row stays, the days are judged again', function () {
    $leave = hrApprovedLeave();
    expect(($this->balance)())->toBe(8.0)
        ->and(($this->day)('2026-10-06')->status)->toBe(AttendanceStatus::OnLeave);

    ($this->as)($this->hr)->post(route('hrm.leave.cancel', $leave), ['reason_text' => 'She came to work after all'])
        ->assertSessionHas('success');

    $leave->refresh();
    expect($leave->isCancelled())->toBeTrue()
        ->and($leave->cancelled_by)->toBe($this->hr->id)
        ->and($leave->cancel_reason)->toBe('She came to work after all')
        ->and(($this->balance)())->toBe(10.0)
        ->and(($this->day)('2026-10-06')->status)->not->toBe(AttendanceStatus::OnLeave)
        // The invariant: this leave's ledger rows net to zero, nothing was rewritten.
        ->and((float) LeaveLedgerEntry::where('reference_id', $leave->id)->where('reference_type', $leave->getMorphClass())->sum('days'))->toBe(0.0)
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntryType::Cancelled)->sole()->occurred_on->toDateString())->toBe('2026-10-06')
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntryType::Taken)->count())->toBe(1)
        ->and(app(LeaveBalance::class)->summaryFor($this->employee->id, 2026)->firstWhere('id', $this->casual->id)['taken'])->toBe(0.0)
        ->and(Activity::where('log_name', 'corrections')->where('event', 'cancelled')->where('subject_id', $leave->id)->exists())->toBeTrue();

    // The same days may be asked for again.
    $again = app(RequestLeave::class)->handle($this->employee, $this->casual, '2026-10-06', '2026-10-06', LeaveDayPortion::FullDay, 'Again', $this->staffUser);
    expect($again->exists)->toBeTrue();
});

it('refuses a second cancel (double click) and changes nothing more', function () {
    $leave = hrApprovedLeave();
    ($this->as)($this->hr)->post(route('hrm.leave.cancel', $leave), ['reason_text' => 'Entered by mistake'])->assertSessionHas('success');
    ($this->as)($this->hr)->post(route('hrm.leave.cancel', $leave), ['reason_text' => 'Entered by mistake'])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'already cancelled'));

    expect(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntryType::Cancelled)->count())->toBe(1)
        ->and(($this->balance)())->toBe(10.0);
});

it('requires a reason of five characters on the server', function () {
    $leave = hrApprovedLeave();

    ($this->as)($this->hr)->post(route('hrm.leave.cancel', $leave), ['reason_text' => 'no'])->assertSessionHasErrors('reason_text');

    expect($leave->fresh()->isCancelled())->toBeFalse();
});

it('lets only HR cancel, and only an approved leave', function () {
    $leave = hrApprovedLeave();
    ($this->as)($this->manager)->post(route('hrm.leave.cancel', $leave), ['reason_text' => 'Not mine to do'])->assertForbidden();

    $waiting = app(RequestLeave::class)->handle($this->employee, $this->casual, '2026-10-12', '2026-10-12', LeaveDayPortion::FullDay, 'Later', $this->staffUser);
    expect(app(LeaveRequestActionPolicy::class)->actionsFor($waiting, $this->hr)->actions)->toBe([]);

    ($this->as)($this->hr)->post(route('hrm.leave.cancel', $waiting), ['reason_text' => 'Not approved yet'])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'still waiting for approval'));
    expect($leave->fresh()->isCancelled())->toBeFalse();
});

it('refuses when the leave year-end is done or the attendance period is locked', function () {
    $leave = hrApprovedLeave();
    $policy = app(LeaveRequestActionPolicy::class);

    $lock = AttendancePeriodLock::create(['period_start' => '2026-10-01', 'period_end' => '2026-10-10', 'locked_at' => now(), 'locked_by' => $this->owner->id]);
    expect($policy->availability($leave, $this->hr, DocumentAction::Cancel)->blockers[0]->code)->toBe('attendance_locked');
    $lock->update(['unlocked_at' => now(), 'unlocked_by' => $this->owner->id, 'unlock_reason' => 'Test over']);

    LeaveYearEndRun::create(['year' => 2026, 'is_dry_run' => false, 'plan' => [], 'employees_affected' => 0, 'total_carried' => 0, 'total_lapsed' => 0, 'run_by' => $this->owner->id]);
    ($this->as)($this->hr)->post(route('hrm.leave.cancel', $leave), ['reason_text' => 'Too late now'])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'year-end is done'));
    expect($leave->fresh()->isCancelled())->toBeFalse();
});

it('shows Cancel on the leave screen with its reasons', function () {
    hrApprovedLeave();

    ($this->as)($this->hr)->get(route('hrm.leave.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('requests.0.actions.actions.0.action', 'cancel')
        ->where('requests.0.actions.actions.0.allowed', true)
        ->has('reasons.cancellation'));
});

// --- Movement Cancel ------------------------------------------------------------

it('cancels an approved movement and judges the day again without it', function () {
    $movement = hrApprovedMovement();
    expect(($this->day)('2026-10-08')->status)->toBe(AttendanceStatus::FieldDuty);

    ($this->as)($this->hr)->post(route('hrm.movements.cancel', $movement), ['reason_text' => 'Visit called off'])->assertSessionHas('success');

    expect($movement->fresh()->isCancelled())->toBeTrue()
        ->and(($this->day)('2026-10-08')->status)->toBe(AttendanceStatus::Absent);

    ($this->as)($this->hr)->post(route('hrm.movements.cancel', $movement), ['reason_text' => 'Visit called off'])
        ->assertSessionHas('error');
});

it('drops a future day that only the leave had judged', function () {
    $leave = hrApprovedLeave('2026-10-26', '2026-10-26');
    expect(($this->day)('2026-10-26'))->not->toBeNull();

    ($this->as)($this->hr)->post(route('hrm.leave.cancel', $leave), ['reason_text' => 'Plans changed'])->assertSessionHas('success');

    expect(($this->day)('2026-10-26'))->toBeNull()->and(($this->balance)())->toBe(10.0);
});

// --- Undo separation (second person) ---------------------------------------------

it('undoes a separation only after a second person approves, and never by the requester', function () {
    app(SeparateEmployee::class)->handle($this->employee, SeparationType::Resignation, '2026-10-15', 'Resigned');
    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Separated);

    ($this->as)($this->hr)->post(route('hrm.employees.undo-separation', $this->employee), ['reason_text' => 'He withdrew his resignation'])
        ->assertSessionHas('success');

    // Nothing changed yet; one request waits.
    $request = HrCorrectionRequest::sole();
    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Separated)
        ->and($request->status)->toBe('pending')
        ->and($request->approvalRequest->status)->toBe(ApprovalStatus::Pending);

    // A second ask while it waits is refused.
    ($this->as)($this->hr)->post(route('hrm.employees.undo-separation', $this->employee), ['reason_text' => 'Asking twice'])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'already waiting'));

    // The requester cannot approve it.
    ($this->as)($this->hr)->post(route('hrm.hr-corrections.approve', $request))->assertForbidden();

    // The inbox card reaches a second person.
    $card = app(ApprovalCards::class)->present($request->approvalRequest->fresh());
    expect($card['type'])->toBe('Undo separation')->and($card['approve_url'])->not->toBeNull();

    ($this->as)($this->manager)->post(route('hrm.hr-corrections.approve', $request))->assertSessionHas('success');

    $employee = $this->employee->fresh();
    expect($employee->status)->toBe(EmployeeStatus::Active)
        ->and($employee->separation_date)->toBeNull()
        ->and(EmployeeAssignment::where('employee_id', $employee->id)->whereNull('effective_to')->exists())->toBeTrue()
        ->and($request->fresh()->status)->toBe('executed')
        // The days after the leaving date are judged again.
        ->and(($this->day)('2026-10-19'))->not->toBeNull();
});

it('fails a waiting undo when the record changed meanwhile, and changes nothing', function () {
    app(SeparateEmployee::class)->handle($this->employee, SeparationType::Resignation, '2026-10-15', 'Resigned');
    ($this->as)($this->hr)->post(route('hrm.employees.undo-separation', $this->employee), ['reason_text' => 'He withdrew his resignation']);
    $request = HrCorrectionRequest::sole();

    $this->employee->fresh()->update(['separation_reason' => 'Resigned, letter on file']);

    ($this->as)($this->manager)->post(route('hrm.hr-corrections.approve', $request))->assertSessionHas('error');

    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Separated)
        ->and($request->fresh()->status)->toBe('failed')
        ->and($request->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Rejected);
});

it('rejecting an undo leaves the separation as it was', function () {
    app(SeparateEmployee::class)->handle($this->employee, SeparationType::Resignation, '2026-10-15', 'Resigned');
    ($this->as)($this->hr)->post(route('hrm.employees.undo-separation', $this->employee), ['reason_text' => 'He withdrew his resignation']);
    $request = HrCorrectionRequest::sole();

    ($this->as)($this->manager)->post(route('hrm.hr-corrections.reject', $request), ['review_note' => 'No'])->assertSessionHas('success');

    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Separated)
        ->and($request->fresh()->status)->toBe('rejected')
        ->and($request->fresh()->pending_key)->toBeNull();
});

it('offers Undo separation only for a separated employee', function () {
    $policy = app(EmployeeActionPolicy::class);
    expect($policy->actionsFor($this->employee, $this->hr)->actions)->toBe([]);

    app(SeparateEmployee::class)->handle($this->employee, SeparationType::Resignation, '2026-10-15', 'Resigned');
    $set = $policy->actionsFor($this->employee->fresh(), $this->hr);
    expect($set->actions[0]->action)->toBe(DocumentAction::Reverse)
        ->and($set->actions[0]->approval->value)->toBe('second_person')
        ->and($policy->actionsFor($this->employee->fresh(), $this->staffUser)->actions)->toBe([]);
});

// --- Show cause Withdraw ----------------------------------------------------------

it('withdraws a delivered notice with a date and a reason; the employee sees it withdrawn', function () {
    ($this->as)($this->hr)->post(route('hrm.notices.store'), ['employee_id' => $this->employee->id, 'subject' => 'Late three times', 'body' => 'Please explain.'])->assertRedirect();
    $notice = ShowCauseNotice::sole();
    expect($notice->wasDelivered())->toBeTrue();

    ($this->as)($this->hr)->post(route('hrm.notices.withdraw', $notice), ['withdrawn_on' => '2026-10-21', 'reason_text' => 'Future date'])
        ->assertSessionHasErrors('withdrawn_on');
    ($this->as)($this->hr)->post(route('hrm.notices.withdraw', $notice), ['withdrawn_on' => '2026-10-19', 'reason_text' => 'Before it was issued'])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'before the day it was issued'));
    ($this->as)($this->hr)->post(route('hrm.notices.withdraw', $notice), ['withdrawn_on' => '2026-10-20', 'reason_text' => 'The machine clock was wrong'])
        ->assertSessionHas('success');

    $notice->refresh();
    expect($notice->isWithdrawn())->toBeTrue()
        ->and($notice->withdrawn_on->toDateString())->toBe('2026-10-20')
        ->and($notice->subject)->toBe('Late three times');

    ($this->as)($this->staffUser)->get(route('hrm.my-notices.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notices.0.withdrawn_on', '20/10/2026'));

    ($this->as)($this->hr)->post(route('hrm.notices.withdraw', $notice), ['withdrawn_on' => '2026-10-20', 'reason_text' => 'Twice by mistake'])
        ->assertSessionHas('error');
});

it('hides a withdrawn notice that never reached the employee', function () {
    $notice = ShowCauseNotice::create(['employee_id' => $this->employee->id, 'issued_by' => $this->hr->id, 'subject' => 'Printed only', 'body' => 'x', 'issued_at' => now()]);

    ($this->as)($this->hr)->post(route('hrm.notices.withdraw', $notice), ['withdrawn_on' => '2026-10-20', 'reason_text' => 'Never handed over'])->assertSessionHas('success');

    ($this->as)($this->staffUser)->get(route('hrm.my-notices.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('notices', 0));
    ($this->as)($this->staffUser)->get(route('hrm.my-notices.show', $notice))->assertNotFound();
});

// --- Draft employee to the Recycle bin -------------------------------------------

it('deletes an unused draft to the bin, refuses a used one, and only the Owner restores', function () {
    $draft = app(CreateEmployee::class)->handle(['first_name' => 'Typo', 'last_name' => 'Twice', 'employment_type' => 'permanent'], ['office_id' => $this->office->id]);
    expect($draft->status)->toBe(EmployeeStatus::Draft);

    // Active employees are never deleted.
    ($this->as)($this->hr)->post(route('hrm.employees.destroy', $this->employee), ['reason_text' => 'Not allowed'])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Only a draft'));

    $used = app(CreateEmployee::class)->handle(['first_name' => 'Used', 'last_name' => 'Draft', 'employment_type' => 'permanent'], ['office_id' => $this->office->id]);
    LeaveLedgerEntry::create(['employee_id' => $used->id, 'leave_type_id' => $this->casual->id, 'year' => 2026,
        'entry_type' => LeaveLedgerEntryType::Grant, 'days' => 5, 'occurred_on' => '2026-10-01']);
    ($this->as)($this->hr)->post(route('hrm.employees.destroy', $used), ['reason_text' => 'Entered twice'])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'leave balance rows'));

    ($this->as)($this->hr)->post(route('hrm.employees.destroy', $draft), ['reason_text' => 'Entered twice'])->assertRedirect(route('hrm.employees.index'));

    expect(Employee::find($draft->id))->toBeNull()
        ->and(Employee::onlyTrashed()->find($draft->id)?->delete_reason)->toBe('Entered twice');

    ($this->as)($this->hr)->get(route('hrm.employees.show', $draft->id))->assertNotFound();

    $entries = app(RecycleBin::class)->entries($this->owner);
    expect($entries->firstWhere('id', $draft->id)['label'])->toContain('Typo Twice');

    expect(fn () => app(RecycleBin::class)->restore('employee', $draft->id, $this->hr, new ActionReason(null, 'Bring back')))
        ->toThrow(ValidationException::class);
    app(RecycleBin::class)->restore('employee', $draft->id, $this->owner, new ActionReason(null, 'Was not a duplicate'));
    expect(Employee::find($draft->id))->not->toBeNull();
});
