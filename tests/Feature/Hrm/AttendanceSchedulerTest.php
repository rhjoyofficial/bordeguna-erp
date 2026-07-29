<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\AttendanceProcessingRun;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * The nightly attendance processor (Corrections C15a to C15e).
 *
 * The most important test here is the business-context one. `BusinessScope`
 * fails closed with `1 = 0`, so a command that forgets to set the context reads
 * no employees, writes nothing, and reports success — a silent no-op that looks
 * exactly like a business with no staff.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Sched Co', 'slug' => 'sched-co']);
    $this->other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);

    app(BusinessContext::class)->set($this->business->id);

    (new AttendancePolicySeeder)->run();

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
        'joining_date' => '2025-01-01',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    $shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 0, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $shift->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2025-01-01',
    ]);
});

/**
 * Run the command, then restore the test's business context.
 *
 * ⚠ The command deliberately calls `BusinessContext::forget()` when it finishes,
 * so nothing downstream inherits the last business it touched. That is correct
 * and is pinned by its own test — but it means any assertion made after the
 * command would otherwise query with no tenant and hit `BusinessScope`'s
 * fail-closed `1 = 0`, reading as "nothing happened" when plenty did.
 */
function schedRun(array $options = [])
{
    $result = test()->artisan('hrm:process-attendance', $options);
    $result->run();

    app(BusinessContext::class)->set(test()->business->id);

    return $result;
}

function schedDay(string $date, array $overrides = []): DailyAttendance
{
    return DailyAttendance::create([
        'employee_id' => test()->employee->id,
        'attendance_date' => $date,
        'status' => $overrides['status'] ?? AttendanceStatus::Present,
        'consequence' => $overrides['consequence'] ?? AttendanceConsequence::None,
        'worked_minutes' => $overrides['worked_minutes'] ?? 480,
        'payable_minutes' => 480,
        'late_minutes' => $overrides['late_minutes'] ?? 0,
        'is_provisional' => $overrides['is_provisional'] ?? false,
        'holds_payroll' => $overrides['holds_payroll'] ?? false,
        'finalized_at' => $overrides['finalized_at'] ?? null,
        'finalized_by' => $overrides['finalized_by'] ?? null,
        'processed_at' => now(),
    ]);
}

// =============================================================================
// ⚠ THE SAFETY TEST. A missing business context is a silent no-op.
// =============================================================================

it('sets business context per business, so the run is not a silent no-op', function () {
    // Deliberately cleared: the command must establish context itself, exactly
    // as it will when the scheduler invokes it with no request behind it.
    app(BusinessContext::class)->forget();

    $this->artisan('hrm:process-attendance', ['--to' => '2026-07-15'])->assertSuccessful();

    app(BusinessContext::class)->set($this->business->id);

    // Had the context not been set, BusinessScope's 1=0 would have found no
    // employees and written nothing, while still exiting 0.
    expect(DailyAttendance::count())->toBeGreaterThan(0);
    expect(AttendanceProcessingRun::count())->toBeGreaterThan(0);
});

it('does not leave a business context set behind it', function () {
    app(BusinessContext::class)->forget();

    $this->artisan('hrm:process-attendance', ['--to' => '2026-07-15'])->assertSuccessful();

    expect(app(BusinessContext::class)->id())->toBeNull();
});

it('records a run per business', function () {
    app(BusinessContext::class)->forget();

    $this->artisan('hrm:process-attendance', ['--to' => '2026-07-15'])->assertSuccessful();

    app(BusinessContext::class)->set($this->business->id);

    expect(AttendanceProcessingRun::withoutGlobalScopes()->count())->toBe(2);
});

// =============================================================================
// C15a — the healing window, and finalisation only once it passes.
// =============================================================================

it('does not silently recompute a day a human finalised', function () {
    $day = schedDay('2026-07-14', [
        'status' => AttendanceStatus::Absent,
        'finalized_at' => now(),
        'finalized_by' => User::factory()->create()->id,
    ]);

    schedRun(['--to' => '2026-07-15', '--business' => $this->business->id])->assertSuccessful();

    // Untouched: status still what the human left, finalisation intact.
    $day->refresh();
    expect($day->status)->toBe(AttendanceStatus::Absent);
    expect($day->finalized_at)->not->toBeNull();
});

it('leaves days inside the window unfinalised so late punches can still reach them', function () {
    schedRun(['--to' => '2026-07-15', '--business' => $this->business->id])->assertSuccessful();

    $inWindow = DailyAttendance::whereDate('attendance_date', '2026-07-14')->sole();

    expect($inWindow->finalized_at)->toBeNull();
    expect($inWindow->processed_at)->not->toBeNull();
});

it('finalises a clean day once it has left the window', function () {
    // Older than the 7-day window, clean, never finalised.
    $old = schedDay('2026-06-20');

    schedRun(['--to' => '2026-07-15', '--business' => $this->business->id])->assertSuccessful();

    $old->refresh();
    expect($old->finalized_at)->not->toBeNull();
    expect($old->is_provisional)->toBeFalse();
});

it('never finalises a day still awaiting review, however old it is', function () {
    // C15b's teeth: the window passing is not a substitute for HR looking.
    $old = schedDay('2026-06-20', ['status' => AttendanceStatus::Absent, 'is_provisional' => true]);

    schedRun(['--to' => '2026-07-15', '--business' => $this->business->id])->assertSuccessful();

    expect($old->refresh()->finalized_at)->toBeNull();
});

// =============================================================================
// C15c — the system identity.
// =============================================================================

it('records the system identity, not a null, when it finalises', function () {
    $old = schedDay('2026-06-20');

    schedRun(['--to' => '2026-07-15', '--business' => $this->business->id])->assertSuccessful();

    $old->refresh();
    expect($old->finalized_by)->toBe(SystemActor::id());
    expect(SystemActor::isSystem($old->finalizer))->toBeTrue();
});

it('gives the system identity no way to authenticate', function () {
    $user = SystemActor::user();

    expect($user->is_active)->toBeFalse();
    expect($user->is_super_admin)->toBeFalse();
    expect($user->businesses()->count())->toBe(0);
    expect($user->roles()->count())->toBe(0);
    // Seeding an actor must not seed a credential.
    expect(Hash::check('password', $user->password))->toBeFalse();
});

it('resolves the same system identity every time', function () {
    expect(SystemActor::id())->toBe(SystemActor::id());
    expect(User::where('email', SystemActor::EMAIL)->count())->toBe(1);
});

// =============================================================================
// C15d — the run history.
// =============================================================================

it('records duration and counts on a completed run', function () {
    schedDay('2026-06-20');

    schedRun(['--to' => '2026-07-15', '--business' => $this->business->id])->assertSuccessful();

    $run = AttendanceProcessingRun::latestFirst()->first();

    expect($run->status)->toBe(AttendanceProcessingRun::STATUS_COMPLETED);
    expect($run->finished_at)->not->toBeNull();
    expect($run->duration_ms)->not->toBeNull();
    expect($run->days_processed)->toBeGreaterThan(0);
    expect($run->days_finalized)->toBe(1);
    expect($run->trigger)->toBe('manual'); // options were passed
    expect($run->from_date->toDateString())->toBe('2026-07-09');
    expect($run->to_date->toDateString())->toBe('2026-07-15');
});

it('writes nothing during a dry run', function () {
    schedRun(['--to' => '2026-07-15', '--business' => $this->business->id, '--dry-run' => true])->assertSuccessful();

    expect(DailyAttendance::count())->toBe(0);
    expect(AttendanceProcessingRun::count())->toBe(0);
});

// =============================================================================
// C15e — locked periods are never reprocessed.
// =============================================================================

it('skips a date inside an active period lock', function () {
    $existing = schedDay('2026-07-14', ['status' => AttendanceStatus::Absent]);

    AttendancePeriodLock::create([
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'locked_at' => now(),
    ]);

    schedRun(['--to' => '2026-07-15', '--business' => $this->business->id])->assertSuccessful();

    // Untouched, and the skip is counted rather than merely absent.
    expect($existing->refresh()->status)->toBe(AttendanceStatus::Absent);
    expect(AttendanceProcessingRun::latestFirst()->first()->days_skipped_locked)->toBeGreaterThan(0);
});

it('processes again once the lock is released', function () {
    $lock = AttendancePeriodLock::create([
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'locked_at' => now(),
    ]);

    schedRun(['--to' => '2026-07-15', '--business' => $this->business->id]);
    expect(DailyAttendance::count())->toBe(0);

    $lock->update(['unlocked_at' => now(), 'unlock_reason' => 'reopened']);

    schedRun(['--to' => '2026-07-15', '--business' => $this->business->id]);
    expect(DailyAttendance::count())->toBeGreaterThan(0);
});

// =============================================================================
// Idempotency.
// =============================================================================

it('changes nothing the second time it runs', function () {
    $args = ['--to' => '2026-07-15', '--business' => $this->business->id];

    // Compared as scalars: the casts hand back fresh Carbon and enum instances
    // on every read, so an object comparison would fail on identity while the
    // values were identical.
    $snapshot = fn () => DailyAttendance::orderBy('attendance_date')->get()
        ->map(fn (DailyAttendance $d) => sprintf(
            '%s|%s|%s|%d|%d',
            $d->attendance_date->toDateString(),
            $d->status->value,
            $d->consequence->value,
            $d->worked_minutes,
            (int) $d->is_provisional,
        ))->all();

    schedRun($args)->assertSuccessful();
    $first = $snapshot();

    schedRun($args)->assertSuccessful();
    $second = $snapshot();

    expect($second)->toBe($first);
    expect(DailyAttendance::count())->toBe(count($first));
});

it('refuses a from date after the to date', function () {
    $this->artisan('hrm:process-attendance', ['--from' => '2026-07-20', '--to' => '2026-07-15'])->assertFailed();
});
