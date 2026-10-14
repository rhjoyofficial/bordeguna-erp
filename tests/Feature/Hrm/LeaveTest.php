<?php

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyLeave;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\GrantAnnualLeaveQuota;
use App\Modules\Hrm\Actions\GrantPaidLeaveOverride;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\Actions\RequestLeave;
use App\Modules\Hrm\Actions\RequestMovement;
use App\Modules\Hrm\Actions\YearEndLeaveProcessor;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\LeaveWorkflowSeeder;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\LeaveYearEndRun;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Support\LeaveBalance;
use App\Support\ApprovalCards;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Leave Co', 'slug' => 'leave-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.leave.manage', 'hrm.leave.approve', 'hrm.scheduling.manage', 'hrm.attendance.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Manager', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.leave.manage', 'hrm.leave.approve', 'hrm.scheduling.manage', 'hrm.attendance.manage']);
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()
        ->givePermissionTo('hrm.leave.approve');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');

    (new LeaveTypeSeeder)->run();
    (new LeaveWorkflowSeeder)->run();

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    // Friday is the weekly off (SCH-06 Bangladesh default).
    $this->calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);
    AttendancePolicy::create([
        'name' => 'Standard', 'version' => 1,
        'config' => AttendancePolicySeeder::defaultConfig(),
        'effective_from' => '2026-01-01',
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'user_id' => $this->staffUser->id,
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $this->calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    $this->earned = LeaveType::where('code', 'earned')->sole();
    $this->casual = LeaveType::where('code', 'casual')->sole();
});

function leaveHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

function leaveStaff()
{
    return test()->actingAs(test()->staffUser)->withSession(['current_business_id' => test()->business->id]);
}

function grantDays(LeaveType $type, float $days, int $year = 2026): void
{
    LeaveLedgerEntry::create([
        'employee_id' => test()->employee->id,
        'leave_type_id' => $type->id,
        'year' => $year,
        'entry_type' => LeaveLedgerEntryType::Grant,
        'days' => $days,
        'occurred_on' => "{$year}-01-01",
    ]);
}

// --- LEV-02 annual grant ------------------------------------------------------

it('grants the full annual quota at the start of the year', function () {
    $result = app(GrantAnnualLeaveQuota::class)->handle(2026);

    expect($result['granted'])->toBeGreaterThan(0);
    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(20.0);
});

it('grants once however many times it runs', function () {
    app(GrantAnnualLeaveQuota::class)->handle(2026);
    $second = app(GrantAnnualLeaveQuota::class)->handle(2026);

    expect($second['granted'])->toBe(0);
    expect($second['skipped'])->toBeGreaterThan(0);
    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(20.0);
});

// --- LEV-06 holidays inside a leave -------------------------------------------

it('does not charge a weekly off that falls inside the leave', function () {
    grantDays($this->earned, 20);

    // 2026-08-06 Thu, 2026-08-07 Fri (weekly off), 2026-08-08 Sat.
    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-06', '2026-08-08',
        LeaveDayPortion::FullDay, 'Family trip', $this->staffUser,
    );

    expect((float) $request->days)->toBe(2.0);
    expect($request->covered_dates)->toBe(['2026-08-06', '2026-08-08']);
});

it('does not charge a dated holiday inside the leave', function () {
    grantDays($this->earned, 20);
    Holiday::create(['holiday_calendar_id' => $this->calendar->id, 'date' => '2026-08-04', 'name' => 'National day']);

    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-05',
        LeaveDayPortion::FullDay, 'Trip', $this->staffUser,
    );

    expect((float) $request->days)->toBe(2.0);
    expect($request->covered_dates)->not->toContain('2026-08-04');
});

it('refuses a request where every day is a holiday or weekly off', function () {
    grantDays($this->earned, 20);

    expect(fn () => app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-07', '2026-08-07',
        LeaveDayPortion::FullDay, 'Friday only', $this->staffUser,
    ))->toThrow(ValidationException::class);
});

// --- LEV-04 half day ----------------------------------------------------------

it('charges half a day for a half-day request', function () {
    grantDays($this->earned, 20);

    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-03',
        LeaveDayPortion::FirstHalf, 'Appointment', $this->staffUser,
    );

    expect((float) $request->days)->toBe(0.5);
});

it('refuses a half day spanning more than one date', function () {
    grantDays($this->earned, 20);

    expect(fn () => app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-05',
        LeaveDayPortion::FirstHalf, 'Nope', $this->staffUser,
    ))->toThrow(ValidationException::class);
});

// --- LEV-07 negative balance becomes unpaid -----------------------------------

it('converts the part with no balance behind it into unpaid days', function () {
    grantDays($this->earned, 1);

    // Mon-Thu = 4 chargeable days, only 1 day of balance.
    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-06',
        LeaveDayPortion::FullDay, 'Longer than my balance', $this->staffUser,
    );

    expect((float) $request->days)->toBe(4.0);
    expect((float) $request->unpaid_days)->toBe(3.0);
});

it('never drives a balance negative', function () {
    grantDays($this->earned, 1);

    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-06',
        LeaveDayPortion::FullDay, 'Longer than my balance', $this->staffUser,
    );
    app(ApplyLeave::class)->handle($request);

    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(0.0);
});

it('does not let two separate requests each draw the same balance below zero', function () {
    grantDays($this->earned, 2);

    // Both requests are made while the balance still reads 2, so each computes
    // 2 paid days at request time.
    $first = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-04',
        LeaveDayPortion::FullDay, 'First', $this->staffUser,
    );
    $second = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-10', '2026-08-11',
        LeaveDayPortion::FullDay, 'Second', $this->staffUser,
    );

    expect((float) $first->unpaid_days)->toBe(0.0)
        ->and((float) $second->unpaid_days)->toBe(0.0);

    app(ApplyLeave::class)->handle($first);
    app(ApplyLeave::class)->handle($second);

    // The second request re-checks the balance at approval: it is now empty, so
    // its days become unpaid and the balance never goes negative.
    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(0.0)
        ->and((float) $second->fresh()->unpaid_days)->toBe(2.0);
});

it('treats a type that draws on no balance as entirely unpaid', function () {
    $unpaid = LeaveType::where('code', 'unpaid')->sole();

    $request = app(RequestLeave::class)->handle(
        $this->employee, $unpaid, '2026-08-03', '2026-08-04',
        LeaveDayPortion::FullDay, 'Personal', $this->staffUser,
    );

    expect((float) $request->unpaid_days)->toBe(2.0);
    app(ApplyLeave::class)->handle($request);
    expect(LeaveLedgerEntry::where('leave_type_id', $unpaid->id)->count())->toBe(0);
});

// --- LEV-05 approval + the attendance seam ------------------------------------

it('charges the balance and marks the days on leave once approved', function () {
    grantDays($this->earned, 20);
    app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');
    expect(DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->sole()->status)
        ->toBe(AttendanceStatus::Absent);

    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-04',
        LeaveDayPortion::FullDay, 'Family', $this->staffUser,
    );

    // Manager, then HR.
    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.leave.approve', $request))->assertRedirect();
    leaveHr()->post(route('hrm.leave.approve', $request))->assertRedirect();

    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(18.0);

    // The engine was never told about leave directly — it read the seam.
    $day = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->sole();
    expect($day->status)->toBe(AttendanceStatus::OnLeave);
});

it('does not charge the balance until the final stage approves', function () {
    grantDays($this->earned, 20);

    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-03',
        LeaveDayPortion::FullDay, 'Family', $this->staffUser,
    );

    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.leave.approve', $request))->assertRedirect();

    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(20.0);
    expect($request->fresh()->isApplied())->toBeFalse();
});

it('leaves the balance alone when a request is rejected', function () {
    grantDays($this->earned, 20);

    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-03',
        LeaveDayPortion::FullDay, 'Family', $this->staffUser,
    );

    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.leave.reject', $request), ['note' => 'Too short notice'])->assertRedirect();

    expect($request->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Rejected);
    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(20.0);
});

it('charges a balance once even if applied twice', function () {
    grantDays($this->earned, 20);

    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-03',
        LeaveDayPortion::FullDay, 'Family', $this->staffUser,
    );

    app(ApplyLeave::class)->handle($request);
    app(ApplyLeave::class)->handle($request);

    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(19.0);
});

// --- POL-09 / POL-10 movement --------------------------------------------------

it('makes an approved movement count as present', function () {
    app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');

    $movement = app(RequestMovement::class)->handle(
        $this->employee, MovementType::FieldDuty, '2026-08-03',
        ['reason' => 'Client site visit', 'place' => 'Gulshan'], $this->staffUser,
    );

    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.movements.approve', $movement))->assertRedirect();

    $day = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->sole();
    expect($day->status)->toBe(AttendanceStatus::FieldDuty);
});

it('counts holiday work only once it is approved', function () {
    Holiday::create(['holiday_calendar_id' => $this->calendar->id, 'date' => '2026-08-04', 'name' => 'National day']);

    app(RecordPunch::class)->handle(
        $this->employee, '2026-08-04 09:00:00', PunchSource::Biometric, ['direction' => 'in'],
    );
    app(RecordPunch::class)->handle(
        $this->employee, '2026-08-04 18:00:00', PunchSource::Biometric, ['direction' => 'out'],
    );

    $before = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-04');
    expect($before->payable_minutes)->toBe(0);
    expect($before->flags)->toContain('unapproved_holiday_work');

    $movement = app(RequestMovement::class)->handle(
        $this->employee, MovementType::HolidayWork, '2026-08-04',
        ['reason' => 'Stock take'], $this->staffUser,
    );
    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.movements.approve', $movement))->assertRedirect();

    $after = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-04')->sole();
    expect($after->payable_minutes)->toBe(480);
    expect($after->flags)->not->toContain('unapproved_holiday_work');
});

// --- C6b / C6d year-end --------------------------------------------------------

it('carries a balance within the cap forward in full', function () {
    grantDays($this->earned, 20, 2026);

    $plan = app(YearEndLeaveProcessor::class)->plan(2026);
    $row = collect($plan['rows'])->firstWhere('leave_type_id', $this->earned->id);

    expect($row['closing_balance'])->toBe(20.0);
    expect($row['cap'])->toBe(40.0); // twice the annual quota (C6b default)
    expect($row['carry_forward'])->toBe(20.0);
    expect($row['lapse'])->toBe(0.0);
});

it('lapses only the days above the cap', function () {
    grantDays($this->earned, 46, 2026);

    $plan = app(YearEndLeaveProcessor::class)->plan(2026);
    $row = collect($plan['rows'])->firstWhere('leave_type_id', $this->earned->id);

    expect($row['carry_forward'])->toBe(40.0);
    expect($row['lapse'])->toBe(6.0);
    expect($row['explanation'])->toContain('will lapse on 31 December 2026');
});

it('never touches a type that may not carry forward', function () {
    grantDays($this->casual, 10, 2026);

    $plan = app(YearEndLeaveProcessor::class)->plan(2026);

    expect(collect($plan['rows'])->firstWhere('leave_type_id', $this->casual->id))->toBeNull();
});

it('previews without changing anything', function () {
    grantDays($this->earned, 46, 2026);

    $run = app(YearEndLeaveProcessor::class)->preview(2026);

    expect($run->is_dry_run)->toBeTrue();
    expect($run->total_lapsed)->toBe('6.00');
    // Balance untouched, nothing posted.
    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(46.0);
    expect(LeaveLedgerEntry::whereNotNull('source_key')->count())->toBe(0);
});

it('commits exactly what the preview projected', function () {
    grantDays($this->earned, 46, 2026);

    $processor = app(YearEndLeaveProcessor::class);
    $preview = $processor->preview(2026);
    $committed = $processor->commit(2026);

    // Same computation, so the same numbers.
    expect($committed->total_carried)->toBe($preview->total_carried);
    expect($committed->total_lapsed)->toBe($preview->total_lapsed);
    expect($committed->is_dry_run)->toBeFalse();

    // 2026 closes at zero; 2027 opens with the carried balance.
    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(0.0);
    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2027))->toBe(40.0);
});

it('is safe to rerun the commit', function () {
    grantDays($this->earned, 46, 2026);

    $processor = app(YearEndLeaveProcessor::class);
    $processor->commit(2026);
    $entriesAfterFirst = LeaveLedgerEntry::count();

    $processor->commit(2026);

    expect(LeaveLedgerEntry::count())->toBe($entriesAfterFirst);
    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2027))->toBe(40.0);
    // The rerun is still recorded, so the audit shows it happened.
    expect(LeaveYearEndRun::where('is_dry_run', false)->count())->toBe(2);
});

it('records the reason a day lapsed', function () {
    grantDays($this->earned, 46, 2026);
    app(YearEndLeaveProcessor::class)->commit(2026);

    $lapse = LeaveLedgerEntry::where('entry_type', LeaveLedgerEntryType::Lapse)->sole();

    expect((float) $lapse->days)->toBe(-6.0);
    expect($lapse->note)->toContain('exceeds the 40 day carry-forward cap');
});

it('honours a per-type cap set by HR', function () {
    $this->earned->update(['carry_forward_cap' => 25]);
    grantDays($this->earned, 46, 2026);

    $row = collect(app(YearEndLeaveProcessor::class)->plan(2026)['rows'])->firstWhere('leave_type_id', $this->earned->id);

    expect($row['carry_forward'])->toBe(25.0);
    expect($row['lapse'])->toBe(21.0);
});

it('runs the year-end command as a dry run by default', function () {
    grantDays($this->earned, 46, 2026);

    $this->artisan('hrm:leave-year-end', ['--year' => 2026])
        ->expectsOutputToContain('dry run')
        ->assertSuccessful();

    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(46.0);
});

it('commits from the command only when asked', function () {
    grantDays($this->earned, 46, 2026);

    $this->artisan('hrm:leave-year-end', ['--year' => 2026, '--commit' => true])->assertSuccessful();

    expect(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2027))->toBe(40.0);
});

// --- Screens and access ---------------------------------------------------------

it('renders the year-end preview for HR', function () {
    grantDays($this->earned, 46, 2026);

    leaveHr()->get(route('hrm.leave.year-end', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/leave/year-end')
            // Compared as a float: JSON renders 6.0 as 6, which a strict
            // comparison would reject.
            ->where('totals.lapsed', fn ($value) => (float) $value === 6.0)
            ->where('totals.carried', fn ($value) => (float) $value === 40.0)
            ->has('plan', 1));
});

it('renders self-service leave with the ledger behind the balance', function () {
    grantDays($this->earned, 20);

    leaveStaff()->get(route('hrm.my-leave'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/leave/my-leave')
            ->has('ledger', 1)
            ->has('balances'));
});

it('blocks a user without the leave permission from the admin screen', function () {
    leaveStaff()->get(route('hrm.leave.index'))->assertForbidden();
});

it('does not leak leave across businesses', function () {
    grantDays($this->earned, 20);
    $other = Business::create(['name' => 'Other', 'slug' => 'other-leave']);

    app(BusinessContext::class)->set($other->id);
    expect(LeaveLedgerEntry::count())->toBe(0);
});

// =============================================================================
// ⚠⚠ The screen listed leave and movements and offered no way to create either.
// Both engine actions worked and both routes existed; nothing in the browser
// reached them, so HR could read the history of a decision but never make one.
// Found 2026-08-16 by trying to record a real leave request through the UI and
// finding no form. These assert the props the forms are built from, because a
// screen test that only checks the component name would have passed throughout.
// =============================================================================

it('gives the leave screen everything a create form needs', function () {
    leaveHr()->get(route('hrm.leave.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/leave/index')
            ->has('employees')
            ->has('leave_types')
            // Without these two the type and portion pickers cannot be drawn.
            ->has('portions')
            ->has('movement_types')
        );
});

it('records a leave request straight from the screen', function () {
    grantDays($this->casual, 10);

    leaveHr()->post(route('hrm.leave.store'), [
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->casual->id,
        'start_date' => '2026-08-05',
        'end_date' => '2026-08-06',
        'day_portion' => 'full_day',
        'reason' => 'Cousin\'s marriage ceremony',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(LeaveRequest::where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('records a movement straight from the screen', function () {
    leaveHr()->post(route('hrm.movements.store'), [
        'employee_id' => $this->employee->id,
        'type' => 'official_work',
        'movement_date' => '2026-08-11',
        'place' => 'Barishal',
        'reason' => 'Outside Dhaka on office work',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Movement::where('employee_id', $this->employee->id)->count())->toBe(1);
});

// =============================================================================
// ⚠⚠ "Draws on no balance" is not "unpaid". Until 24 Aug 2026 the LEV-07 split
// marked EVERY non-balance type entirely unpaid, so a Special Leave day the
// boss granted as paid - and a maternity day - carried a Leave Without Pay
// deduction into payroll. Found live, the day Joy granted exactly such a day.
// =============================================================================

it('charges nothing and deducts nothing for a paid type that draws on no balance', function () {
    $special = LeaveType::where('code', 'special')->first();
    $special->update(['is_active' => true]);

    $request = app(RequestLeave::class)->handle(
        $this->employee, $special, '2026-08-10', '2026-08-10',
        LeaveDayPortion::FullDay, 'Bereavement - paid day granted by the office', $this->hr,
    );

    expect((float) $request->unpaid_days)->toBe(0.0)
        ->and((float) $request->days)->toBe(1.0);
});

it('treats maternity as paid even though it draws on no balance', function () {
    $maternity = LeaveType::where('code', 'maternity')->first();

    $request = app(RequestLeave::class)->handle(
        $this->employee, $maternity, '2026-08-03', '2026-08-05',
        LeaveDayPortion::FullDay, 'Maternity', $this->hr,
    );

    expect((float) $request->unpaid_days)->toBe(0.0);
});

it('still marks the Unpaid type entirely unpaid', function () {
    $unpaid = LeaveType::where('code', 'unpaid')->first();

    $request = app(RequestLeave::class)->handle(
        $this->employee, $unpaid, '2026-08-10', '2026-08-10',
        LeaveDayPortion::FullDay, 'No balance behind it', $this->hr,
    );

    expect((float) $request->unpaid_days)->toBe(1.0);
});

// --- One day is asked for once ------------------------------------------------

it('refuses a second request over days already waiting for approval', function () {
    grantDays($this->earned, 20);
    $ask = fn (string $from, string $to, LeaveDayPortion $portion = LeaveDayPortion::FullDay) => app(RequestLeave::class)
        ->handle($this->employee, $this->earned, $from, $to, $portion, 'Tour', $this->staffUser);

    $ask('2026-08-03', '2026-08-04');

    expect(fn () => $ask('2026-08-04', '2026-08-05'))->toThrow(ValidationException::class);
    expect(LeaveRequest::count())->toBe(1);
});

it('frees the days again once the first request is rejected', function () {
    grantDays($this->earned, 20);
    $ask = fn () => app(RequestLeave::class)
        ->handle($this->employee, $this->earned, '2026-08-03', '2026-08-03', LeaveDayPortion::FullDay, 'Tour', $this->staffUser);

    $first = $ask();
    $first->approvalRequest->reject($this->manager, 'Not now');

    expect($ask()->exists)->toBeTrue();
});

it('allows the two different halves of one date', function () {
    grantDays($this->earned, 20);
    $ask = fn (LeaveDayPortion $portion) => app(RequestLeave::class)
        ->handle($this->employee, $this->earned, '2026-08-03', '2026-08-03', $portion, 'Appointment', $this->staffUser);

    $ask(LeaveDayPortion::FirstHalf);

    expect($ask(LeaveDayPortion::SecondHalf)->exists)->toBeTrue();
    expect(fn () => $ask(LeaveDayPortion::SecondHalf))->toThrow(ValidationException::class);
});

it('shows the approver plain dates, never a timestamp', function () {
    grantDays($this->earned, 20);
    $request = app(RequestLeave::class)
        ->handle($this->employee, $this->earned, '2026-08-03', '2026-08-04', LeaveDayPortion::FullDay, 'Tour', $this->staffUser);

    $dates = collect(app(ApprovalCards::class)->present($request->approvalRequest)['details'])
        ->firstWhere('label', 'Dates')['value'];

    expect($dates)->toBe('3 Aug 2026 → 4 Aug 2026');
});

// --- Authority pays unpaid leave (Joy, 27 Sep 2026) ---------------------------

it('lets the authority pay the unpaid days of an approved leave, drawing on no balance', function () {
    // No casual balance, so a 2-day casual leave is entirely unpaid (LEV-07).
    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->casual, '2026-09-21', '2026-09-22',
        LeaveDayPortion::FullDay, 'Tour', $this->staffUser,
    );
    app(ApplyLeave::class)->handle($request);
    expect((float) $request->fresh()->unpaid_days)->toBe(2.0);

    $balanceBefore = app(LeaveBalance::class)->for($this->employee->id, $this->casual->id, 2026);

    $paid = app(GrantPaidLeaveOverride::class)->handle($request->fresh(), $this->hr, 'Owner granted');

    expect((float) $paid->unpaid_days)->toBe(0.0)
        ->and($paid->isPaidByAuthority())->toBeTrue()
        ->and($paid->paid_override_by)->toBe($this->hr->id)
        ->and($paid->paid_override_reason)->toBe('Owner granted')
        // No quota is drawn: the balance is exactly what it was, never negative.
        ->and(app(LeaveBalance::class)->for($this->employee->id, $this->casual->id, 2026))->toBe($balanceBefore);
});

it('refuses to pay a leave that has no unpaid days', function () {
    grantDays($this->casual, 5);
    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->casual, '2026-09-21', '2026-09-22',
        LeaveDayPortion::FullDay, 'Covered by balance', $this->staffUser,
    );
    app(ApplyLeave::class)->handle($request);
    expect((float) $request->fresh()->unpaid_days)->toBe(0.0);

    expect(fn () => app(GrantPaidLeaveOverride::class)->handle($request->fresh(), $this->hr, 'nothing to pay'))
        ->toThrow(ValidationException::class);
});

it('lets a leave manager pay the days over HTTP and refuses everyone else', function () {
    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->casual, '2026-09-21', '2026-09-22',
        LeaveDayPortion::FullDay, 'Tour', $this->staffUser,
    );
    app(ApplyLeave::class)->handle($request);

    leaveStaff()->post(route('hrm.leave.grant-paid', $request->id), ['reason' => 'nope'])
        ->assertForbidden();

    leaveHr()->post(route('hrm.leave.grant-paid', $request->id), ['reason' => 'Owner granted'])
        ->assertRedirect();

    expect((float) $request->fresh()->unpaid_days)->toBe(0.0)
        ->and($request->fresh()->isPaidByAuthority())->toBeTrue();
});

it('requires a reason to pay the days', function () {
    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->casual, '2026-09-21', '2026-09-22',
        LeaveDayPortion::FullDay, 'Tour', $this->staffUser,
    );
    app(ApplyLeave::class)->handle($request);

    leaveHr()->post(route('hrm.leave.grant-paid', $request->id), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect((float) $request->fresh()->unpaid_days)->toBe(2.0);
});

it('rolls the final approval back when applying the leave fails', function () {
    grantDays($this->earned, 20);
    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-08-03', '2026-08-04',
        LeaveDayPortion::FullDay, 'Family', $this->staffUser,
    );
    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.leave.approve', $request))->assertRedirect();

    $this->mock(ApplyLeave::class)->shouldReceive('handle')->andThrow(new RuntimeException('apply failed'));

    leaveHr()->post(route('hrm.leave.approve', $request))->assertStatus(500);

    // ⚠ Audit 13 Oct 2026: not left approved-but-never-applied.
    $approval = $request->fresh()->approvalRequest;
    expect($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->current_stage)->toBe(2);
});

it('refuses a leave request against a type that has been switched off', function () {
    // ⚠ Audit 13 Oct 2026: both forms accepted any leave type of the business.
    grantDays($this->casual, 10);
    $this->casual->update(['is_active' => false]);

    leaveHr()->post(route('hrm.leave.store'), [
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->casual->id,
        'start_date' => '2026-08-05',
        'end_date' => '2026-08-06',
        'day_portion' => 'full_day',
        'reason' => 'Switched-off type',
    ])->assertSessionHasErrors('leave_type_id');

    expect(LeaveRequest::count())->toBe(0);
});

it('refuses a switched-off leave type on the employee\'s own form too', function () {
    grantDays($this->casual, 10);
    $this->casual->update(['is_active' => false]);

    leaveStaff()->post(route('hrm.my-leave.requests.store'), [
        'leave_type_id' => $this->casual->id,
        'start_date' => '2026-08-05',
        'end_date' => '2026-08-06',
        'day_portion' => 'full_day',
        'reason' => 'Switched-off type',
    ])->assertSessionHasErrors('leave_type_id');

    expect(LeaveRequest::count())->toBe(0);
});

// --- Rule #17 (14 Oct 2026): leave across New Year is split by leave year ------

it('charges each leave year only for its own days when leave crosses New Year', function () {
    grantDays($this->earned, 5, 2026);
    grantDays($this->earned, 10, 2027);
    // Same counting rules as any leave: 31 Dec is a holiday and 1 Jan a Friday.
    Holiday::create(['holiday_calendar_id' => $this->calendar->id, 'date' => '2026-12-31', 'name' => 'Year end']);

    // Wed 30 Dec to Sun 3 Jan: 30 Dec charged to 2026, 2 and 3 Jan to 2027.
    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-12-30', '2027-01-03',
        LeaveDayPortion::FullDay, 'Family visit', $this->staffUser,
    );

    expect((float) $request->days)->toBe(3.0)
        ->and((float) $request->unpaid_days)->toBe(0.0);

    app(ApplyLeave::class)->handle($request);
    app(ApplyLeave::class)->handle($request->fresh());

    $balances = app(LeaveBalance::class);
    expect($balances->for($this->employee->id, $this->earned->id, 2026))->toBe(4.0)
        ->and($balances->for($this->employee->id, $this->earned->id, 2027))->toBe(8.0);

    $taken = LeaveLedgerEntry::where('entry_type', LeaveLedgerEntryType::Taken)->orderBy('year')->get();
    expect($taken)->toHaveCount(2)
        ->and($taken->pluck('year')->all())->toBe([2026, 2027])
        ->and($taken[1]->occurred_on->toDateString())->toBe('2027-01-02');
});

it('makes unpaid only the days of the leave year whose balance is empty', function () {
    grantDays($this->earned, 10, 2027);

    // Thu 31 Dec and Sat 2 Jan, Sun 3 Jan: 2026 has no balance, 2027 has ten.
    $request = app(RequestLeave::class)->handle(
        $this->employee, $this->earned, '2026-12-31', '2027-01-03',
        LeaveDayPortion::FullDay, 'Family visit', $this->staffUser,
    );

    expect((float) $request->days)->toBe(3.0)
        ->and((float) $request->unpaid_days)->toBe(1.0);

    app(ApplyLeave::class)->handle($request);

    expect((float) $request->fresh()->unpaid_days)->toBe(1.0)
        ->and(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2027))->toBe(8.0)
        ->and(app(LeaveBalance::class)->for($this->employee->id, $this->earned->id, 2026))->toBe(0.0);
});
