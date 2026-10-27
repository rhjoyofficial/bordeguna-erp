<?php

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Notifications\PendingApprovalNotification;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\Actions\RequestLeave;
use App\Modules\Hrm\Actions\ValidatePayrollRun;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\LeaveWorkflowSeeder;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Notifications\AttendanceReminderNotification;
use App\Modules\Hrm\Notifications\EarlyLeaveDecidedNotification;
use App\Support\ApprovalCards;
use App\Support\Attention\Sources\AttendanceReviewSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Early leave request (Joy's HRM queue, 27 Oct 2026): asked from My
 * attendance, approved by a Manager or the Owner like any movement. Approved =
 * no deduction for that early departure and the day finalised; rejected = the
 * day stays as the attendance rules judge it (option A, the half-day rule).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->business = Business::create(['name' => 'Early Co', 'slug' => 'early-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.leave.manage', 'hrm.leave.approve', 'hrm.attendance.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Manager', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.leave.manage', 'hrm.leave.approve', 'hrm.attendance.manage']);
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()
        ->givePermissionTo('hrm.leave.approve');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');

    $this->otherUser = User::factory()->create();
    $this->otherUser->businesses()->attach($this->business->id);
    $this->otherUser->assignRole('Staff');

    (new LeaveTypeSeeder)->run();
    (new LeaveWorkflowSeeder)->run();

    $office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);
    AttendancePolicy::create([
        'name' => 'Standard', 'version' => 1,
        'config' => AttendancePolicySeeder::defaultConfig(),
        'effective_from' => '2026-01-01',
    ]);

    $this->employee = earlyEmployee('BDG-000001', 'Karim', $this->staffUser, $office);
    $this->other = earlyEmployee('BDG-000002', 'Rahim', $this->otherUser, $office);

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $shift->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2025-01-01',
    ]);
});

function earlyEmployee(string $code, string $name, User $user, Office $office): Employee
{
    $employee = Employee::create([
        'employee_code' => $code,
        'user_id' => $user->id,
        'first_name' => $name, 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    return $employee;
}

function earlyAs(User $user)
{
    return test()->actingAs($user)->withSession(['current_business_id' => test()->business->id]);
}

/** In at 09:00, out at the given time, processed. Monday 12 Oct 2026 by default. */
function earlyDay(string $out, string $date = '2026-10-12', ?Employee $employee = null): DailyAttendance
{
    $employee ??= test()->employee;
    app(RecordPunch::class)->handle($employee, "{$date} 09:00:00", PunchSource::Biometric, ['direction' => 'in']);
    app(RecordPunch::class)->handle($employee, "{$date} {$out}:00", PunchSource::Biometric, ['direction' => 'out']);

    return app(ProcessAttendance::class)->forDay($employee, $date);
}

function requestEarly(string $date = '2026-10-12', string $time = '12:30'): Movement
{
    earlyAs(test()->staffUser)->post(route('hrm.clock.early-leave.store'), [
        'date' => $date, 'time' => $time, 'reason' => 'Child is unwell',
    ])->assertSessionHasNoErrors()->assertRedirect();

    return Movement::where('type', MovementType::EarlyLeave)->latest('id')->firstOrFail();
}

function freshDay(string $date = '2026-10-12', ?Employee $employee = null): DailyAttendance
{
    return DailyAttendance::where('employee_id', ($employee ?? test()->employee)->id)->onDate($date)->sole();
}

it('half-days an early departure without an approved request, as today (option A)', function () {
    $day = earlyDay('12:30');

    expect($day->status)->toBe(AttendanceStatus::HalfDay)
        ->and($day->flags)->toContain('early_departure')
        ->and($day->is_provisional)->toBeTrue();
});

it('approves an early leave: no deduction for leaving early and the day is finalised', function () {
    Notification::fake();
    earlyDay('12:30');
    $movement = requestEarly();

    expect($movement->employee_id)->toBe($this->employee->id)
        ->and($movement->from_time)->toStartWith('12:30')
        ->and($movement->to_time)->toBeNull()
        ->and($movement->approvalRequest->status)->toBe(ApprovalStatus::Pending);

    earlyAs($this->manager)->post(route('hrm.movements.approve', $movement))->assertRedirect();

    $day = freshDay();
    expect($day->status)->toBe(AttendanceStatus::Present)
        ->and($day->flags)->toContain('early_leave_approved')
        ->and($day->flags)->not->toContain('early_departure')
        ->and($day->is_provisional)->toBeFalse()
        ->and($day->finalized_at)->not->toBeNull()
        ->and($day->finalized_by)->toBe($this->manager->id);

    Notification::assertSentTo($this->staffUser, EarlyLeaveDecidedNotification::class,
        fn ($n) => str_contains($n->summary, 'approved') && str_contains($n->summary, '12 Oct 2026'));
});

it('finalises a longer day too, where the only problem was leaving early', function () {
    $before = earlyDay('16:00');
    expect($before->status)->toBe(AttendanceStatus::Present)->and($before->is_provisional)->toBeTrue();

    $movement = requestEarly(time: '16:00');
    earlyAs($this->manager)->post(route('hrm.movements.approve', $movement))->assertRedirect();

    expect(freshDay()->is_provisional)->toBeFalse()->and(freshDay()->isFinalized())->toBeTrue();
});

it('rejects an early leave: the half-day rule applies and the employee is told', function () {
    Notification::fake();
    earlyDay('12:30');
    $movement = requestEarly();

    earlyAs($this->manager)->post(route('hrm.movements.reject', $movement), ['note' => 'Busy day'])->assertRedirect();

    $day = freshDay();
    expect($day->status)->toBe(AttendanceStatus::HalfDay)
        ->and($day->is_provisional)->toBeTrue()
        ->and($day->finalized_at)->toBeNull()
        ->and($movement->fresh()->isApplied())->toBeFalse();

    Notification::assertSentTo($this->staffUser, EarlyLeaveDecidedNotification::class,
        fn ($n) => str_contains($n->summary, 'rejected') && str_contains($n->summary, 'Busy day'));
});

it('judges a departure well before the approved time as unapproved', function () {
    earlyDay('11:00');
    $movement = requestEarly(time: '15:00');
    earlyAs($this->manager)->post(route('hrm.movements.approve', $movement))->assertRedirect();

    $day = freshDay();
    expect($day->status)->toBe(AttendanceStatus::HalfDay)
        ->and($day->flags)->toContain('early_departure')
        ->and($day->is_provisional)->toBeTrue();
});

it('files the request for the signed-in employee only, whatever the body says', function () {
    earlyAs($this->staffUser)->post(route('hrm.clock.early-leave.store'), [
        'employee_id' => $this->other->id,
        'date' => '2026-10-12', 'time' => '12:30', 'reason' => 'Doctor',
    ])->assertSessionHasNoErrors();

    expect(Movement::where('employee_id', $this->other->id)->count())->toBe(0)
        ->and(Movement::where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('lets neither the employee nor another staff member approve it', function () {
    $movement = requestEarly();

    earlyAs($this->staffUser)->post(route('hrm.movements.approve', $movement))->assertForbidden();
    earlyAs($this->otherUser)->post(route('hrm.movements.approve', $movement))->assertForbidden();

    expect($movement->fresh()->isApplied())->toBeFalse();
});

it('needs a time, refuses a second request for the same day, and refuses a past cycle', function () {
    earlyAs($this->staffUser)->post(route('hrm.clock.early-leave.store'), [
        'date' => '2026-10-12', 'reason' => 'x',
    ])->assertSessionHasErrors('time');

    requestEarly();
    earlyAs($this->staffUser)->post(route('hrm.clock.early-leave.store'), [
        'date' => '2026-10-12', 'time' => '13:00', 'reason' => 'again',
    ])->assertSessionHasErrors('movement_date');

    earlyAs($this->staffUser)->post(route('hrm.clock.early-leave.store'), [
        'date' => '2026-09-10', 'time' => '13:00', 'reason' => 'old',
    ])->assertSessionHasErrors('date');
});

it('has no monthly cap by default, and holds one when the setting is given', function () {
    requestEarly('2026-10-12');
    requestEarly('2026-10-13');

    config(['hrm.early_leave.monthly_request_cap' => 2]);

    earlyAs($this->staffUser)->post(route('hrm.clock.early-leave.store'), [
        'date' => '2026-10-14', 'time' => '13:00', 'reason' => 'third',
    ])->assertSessionHasErrors('movement_date');
});

// ⭐ 27 Oct 2026 (Joy): "for employees everything not 1-31 always 24-23".
it('counts the monthly cap over the 24-23 cycle, so the 24th opens a fresh count', function () {
    config(['hrm.early_leave.monthly_request_cap' => 2]);

    // 20 and 22 Oct fill the cycle labelled October (24 Sep - 23 Oct).
    requestEarly('2026-10-20');
    requestEarly('2026-10-22');

    earlyAs($this->staffUser)->post(route('hrm.clock.early-leave.store'), [
        'date' => '2026-10-21', 'time' => '13:00', 'reason' => 'third in the cycle',
    ])->assertSessionHasErrors(['movement_date' => 'Karim Ahmed has already used all 2 early leave request(s) for October 2026 (24 Sep to 23 Oct 2026), counting requests still awaiting approval.']);

    // Saturday 24 Oct is still October on the calendar, but it is the next cycle.
    expect(requestEarly('2026-10-24'))->not->toBeNull();
});

it('approves a request for a day still to come without writing that day', function () {
    $movement = requestEarly('2026-10-20', '15:00');
    earlyAs($this->manager)->post(route('hrm.movements.approve', $movement))->assertRedirect();

    expect($movement->fresh()->isApplied())->toBeTrue()
        ->and(DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-10-20')->exists())->toBeFalse();
});

it('lists the days needing the employee\'s action on My attendance, until something is filed', function () {
    earlyDay('12:30');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-10-13'); // absent, nothing filed
    earlyDay('18:00', '2026-10-14'); // a clean day

    earlyAs($this->staffUser)->get(route('hrm.clock'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/attendance/clock')
            ->has('days_needing_action', 2)
            ->where('days_needing_action.0.date', '2026-10-12')
            ->where('days_needing_action.0.reason_key', 'early.reason.early_departure')
            ->where('days_needing_action.1.date', '2026-10-13')
            ->where('days_needing_action.1.reason_key', 'early.reason.absent'));

    requestEarly();

    earlyAs($this->staffUser)->get(route('hrm.clock'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('days_needing_action', 1)
            ->where('days_needing_action.0.date', '2026-10-13')
            ->has('early_leave_requests', 1)
            ->where('early_leave_requests.0.status', 'pending'));
});

it('shows the approver the early leave on the decision card', function () {
    $movement = requestEarly();

    $card = app(ApprovalCards::class)->present($movement->approvalRequest()->first());

    expect($card['summary'])->toContain('Early leave')->toContain('leaving at 12:30');
});

// --- Payroll: the block names the dates and links to them ---------------------

function payrollRowFor(Employee $employee): ?array
{
    $report = app(ValidatePayrollRun::class)->handle(PayrollPeriod::forMonth(2026, 10));

    return collect($report['employees'])->firstWhere('employee_id', $employee->id) + ['report' => $report];
}

it('names the provisional dates in the payroll issue and links to them on the Daily grid', function () {
    earlyDay('12:30');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-10-13');

    $row = payrollRowFor($this->employee);

    expect(implode(' ', $row['issues']))
        ->toContain('2 days of attendance still await HR review: 12 Oct 2026, 13 Oct 2026.')
        ->and($row['review_days'])->toHaveCount(2)
        ->and($row['review_url'])->toBe('/hrm/attendance/daily?from=2026-10-12&to=2026-10-13&review=1&employee_id='.$this->employee->id)
        ->and($row['report']['review_url'])->toBe('/hrm/attendance/daily?from=2026-10-12&to=2026-10-13&review=1');
});

it('says when a pending leave or early leave request covers a blocked day', function () {
    earlyDay('12:30');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-10-13');

    requestEarly('2026-10-12');
    app(RequestLeave::class)->handle(
        $this->employee,
        LeaveType::where('code', 'casual')->sole(),
        '2026-10-13', '2026-10-13', LeaveDayPortion::FullDay, 'Fever', $this->staffUser,
    );

    $row = payrollRowFor($this->employee);

    expect(implode(' ', $row['issues']))
        ->toContain('12 Oct 2026 (pending early leave request covers this day)')
        ->toContain('13 Oct 2026 (pending leave covers this day)')
        ->and($row['review_days'][1]['pending'])->toBe('pending leave covers this day');
});

it('shows HR the review link on the Daily grid it points at', function () {
    earlyDay('12:30');
    $hr = User::factory()->create();
    $hr->businesses()->attach($this->business->id);
    $hr->assignRole('Owner');

    earlyAs($hr)->get(ValidatePayrollRun::reviewUrl(['2026-10-12'], $this->employee->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/attendance/daily')
            ->where('filters.review', true)
            ->has('days', 1)
            ->where('days.0.date', '2026-10-12'));
});

// --- Reminders and Needs attention --------------------------------------------

it('reminds the approvers once, after a request has waited two days', function () {
    Notification::fake();
    $movement = requestEarly('2026-10-16', '15:00');
    Notification::assertSentToTimes($this->manager, PendingApprovalNotification::class, 1);

    $this->travelTo(Carbon::parse('2026-10-16 10:00:00'));
    $this->artisan('hrm:attendance-reminders')->assertSuccessful();
    Notification::assertSentToTimes($this->manager, PendingApprovalNotification::class, 1);

    $this->travelTo(Carbon::parse('2026-10-17 10:00:00'));
    $this->artisan('hrm:attendance-reminders')->assertSuccessful();
    Notification::assertSentToTimes($this->manager, PendingApprovalNotification::class, 2);
    Notification::assertSentTo($this->manager, PendingApprovalNotification::class,
        fn ($n) => str_starts_with($n->summary, 'Reminder, waiting 2 days:') && str_contains($n->summary, 'Early leave'));
    expect($movement->fresh()->reminded_at)->not->toBeNull();

    $this->travelTo(Carbon::parse('2026-10-18 10:00:00'));
    $this->artisan('hrm:attendance-reminders')->assertSuccessful();
    Notification::assertSentToTimes($this->manager, PendingApprovalNotification::class, 2);
});

it('does not remind about a request already decided', function () {
    Notification::fake();
    $movement = requestEarly('2026-10-16', '15:00');
    earlyAs($this->manager)->post(route('hrm.movements.reject', $movement));

    $this->travelTo(Carbon::parse('2026-10-19 10:00:00'));
    $this->artisan('hrm:attendance-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->manager, PendingApprovalNotification::class, 1);
});

it('sends HR the provisional days on the 20th, three days before the 24-23 cycle ends', function () {
    Notification::fake();
    earlyDay('12:30');
    $hr = User::factory()->create();
    $hr->businesses()->attach($this->business->id);
    $hr->assignRole('Owner');

    $this->travelTo(Carbon::parse('2026-10-19 08:30:00'));
    $this->artisan('hrm:attendance-reminders')->assertSuccessful();
    Notification::assertNotSentTo($hr, AttendanceReminderNotification::class);

    $this->travelTo(Carbon::parse('2026-10-20 08:30:00'));
    $this->artisan('hrm:attendance-reminders')->assertSuccessful();
    Notification::assertSentTo($hr, AttendanceReminderNotification::class, fn ($n) => str_contains($n->summary, '1 attendance day of this payroll cycle is still provisional')
        && str_contains($n->summary, '12 Oct (Karim Ahmed)')
        && str_contains($n->summary, 'ends on 23 Oct 2026')
        && $n->url === '/hrm/attendance/daily?from=2026-10-12&to=2026-10-12&review=1');
    Notification::assertNotSentTo($this->manager, AttendanceReminderNotification::class);
});

it('lists waiting early leave and provisional days, with dates, on Needs attention', function () {
    earlyDay('12:30');
    requestEarly('2026-10-16', '15:00');

    $source = app(AttendanceReviewSource::class);
    expect($source->authorize($this->manager))->toBeTrue()
        ->and($source->authorize($this->staffUser))->toBeFalse();

    $items = collect($source->items($this->manager, $this->business->id))->keyBy(fn ($i) => $i->key);

    $pending = $items['attendance_review:early_leave_pending'];
    expect($pending->reason)->toBe('1 early leave requests are waiting for a decision: 16 Oct.')
        ->and($pending->url)->toBe('/hrm/attendance/daily?from=2026-10-16&to=2026-10-16&review=1')
        ->and($pending->severity)->toBe('normal');

    $provisional = $items['attendance_review:provisional'];
    expect($provisional->reason)->toContain('12 Oct')
        ->and($provisional->url)->toBe('/hrm/attendance/daily?from=2026-10-12&to=2026-10-12&review=1');

    $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
    $items = collect($source->items($this->manager, $this->business->id))->keyBy(fn ($i) => $i->key);
    expect($items['attendance_review:provisional']->severity)->toBe('high')
        ->and($items['attendance_review:early_leave_pending']->severity)->toBe('high');
});

it('shows the attendance lines on the Needs attention page', function () {
    earlyDay('12:30');

    earlyAs($this->manager)->get(route('attention'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('items', fn ($items) => collect($items)->contains(fn ($i) => $i['key'] === 'attendance_review:provisional')));
});
