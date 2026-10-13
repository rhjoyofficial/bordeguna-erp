<?php

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyCorrection;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\Actions\RequestCorrection;
use App\Modules\Hrm\database\seeders\AttendanceCorrectionWorkflowSeeder;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\CorrectionType;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\Shift;
use App\Support\ApprovalInbox;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Corr Co', 'slug' => 'corr-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.attendance.manage', 'hrm.attendance.approve', 'hrm.scheduling.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Manager', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.attendance.approve', 'hrm.scheduling.manage']);
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()
        ->givePermissionTo('hrm.attendance.approve');
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

    (new AttendanceCorrectionWorkflowSeeder)->run();

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
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
        'effective_from' => '2026-01-01',
    ]);

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $this->calendar->id,
        'effective_from' => '2026-01-01',
    ]);
});

function corrHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

function corrStaff()
{
    return test()->actingAs(test()->staffUser)->withSession(['current_business_id' => test()->business->id]);
}

function corrPunch(string $at, ?string $direction = null): AttendancePunch
{
    return app(RecordPunch::class)->handle(
        test()->employee, $at, PunchSource::Biometric,
        ['device_identifier' => 'K40-1', 'direction' => $direction],
    );
}

// --- The headline: a correction clears a POL-07 hold -------------------------

it('clears a payroll hold by supplying the missing punch, without the engine knowing about corrections', function () {
    corrPunch('2026-08-03 09:00:00', 'in'); // never clocked out

    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');
    expect($day->status)->toBe(AttendanceStatus::MissingPunch);
    expect($day->holds_payroll)->toBeTrue();

    app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot to clock out; confirmed by the manager.',
    ], $this->hr);

    $day->refresh();
    expect($day->status)->toBe(AttendanceStatus::Present);
    expect($day->holds_payroll)->toBeFalse();
    expect($day->worked_minutes)->toBe(540);
    expect($day->payable_minutes)->toBe(480);
});

it('never modifies the raw punches when a correction is applied', function () {
    $punch = corrPunch('2026-08-03 09:47:00', 'in');
    corrPunch('2026-08-03 18:00:00', 'out');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');

    app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AmendPunch->value,
        'punch_id' => $punch->id,
        'corrected_at' => '2026-08-03 09:00:00',
        'direction' => 'in',
        'reason' => 'Device clock drift.',
    ], $this->hr);

    // The raw punch still says what the device said.
    expect($punch->fresh()->punched_at->format('H:i:s'))->toBe('09:47:00');
    expect(AttendancePunch::count())->toBe(2);

    // But the day is judged on the corrected time.
    $day = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->sole();
    expect($day->status)->toBe(AttendanceStatus::Present);
    expect($day->late_minutes)->toBe(0);
});

it('disregards a punch without deleting it', function () {
    corrPunch('2026-08-03 09:00:00', 'in');
    $stray = corrPunch('2026-08-03 09:02:00', 'in'); // double tap
    corrPunch('2026-08-03 18:00:00', 'out');

    app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::IgnorePunch->value,
        'punch_id' => $stray->id,
        'reason' => 'Duplicate scan on entry.',
    ], $this->hr);

    expect(AttendancePunch::find($stray->id))->not->toBeNull();

    $day = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->sole();
    expect($day->status)->toBe(AttendanceStatus::Present);
    expect($day->worked_minutes)->toBe(540);
});

it('recalculates a finalised day when a correction lands on it', function () {
    corrPunch('2026-08-03 09:00:00', 'in');
    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');
    $day->update(['is_provisional' => false, 'finalized_at' => now(), 'finalized_by' => $this->hr->id]);

    app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Missing out punch.',
    ], $this->hr);

    $day->refresh();
    expect($day->status)->toBe(AttendanceStatus::Present);
    // Reprocessing reopens the day — finalising is always a fresh decision.
    expect($day->finalized_at)->toBeNull();
});

// --- COR-01: two paths, both audited -----------------------------------------

it('applies an HR correction immediately as the privileged path', function () {
    corrPunch('2026-08-03 09:00:00', 'in');

    $correction = app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'HR entered from the manual register.',
    ], $this->hr);

    expect($correction->isApplied())->toBeTrue();
    expect($correction->approvalRequest)->toBeNull();
});

it('routes an employee request through the workflow instead of applying it', function () {
    corrPunch('2026-08-03 09:00:00', 'in');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');

    $correction = app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'I forgot to clock out.',
    ], $this->staffUser);

    expect($correction->isApplied())->toBeFalse();
    expect($correction->approvalRequest->status)->toBe(ApprovalStatus::Pending);
    expect($correction->approvalRequest->totalStages())->toBe(2);

    // The day is untouched until it is approved.
    $day = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->sole();
    expect($day->status)->toBe(AttendanceStatus::MissingPunch);
});

it('audits every correction', function () {
    corrPunch('2026-08-03 09:00:00', 'in');

    $correction = app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Audited path.',
    ], $this->hr);

    $logged = Activity::where('subject_type', $correction->getMorphClass())
        ->where('subject_id', $correction->id)->exists();

    expect($logged)->toBeTrue();
});

// --- COR-03: Manager then HR --------------------------------------------------

it('advances through Manager then HR before applying', function () {
    corrPunch('2026-08-03 09:00:00', 'in');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');

    $correction = app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot to clock out.',
    ], $this->staffUser);

    // Stage 1 — the manager.
    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.attendance.corrections.approve', $correction))->assertRedirect();

    $correction->refresh();
    expect($correction->isApplied())->toBeFalse();
    expect($correction->approvalRequest->current_stage)->toBe(2);

    // Stage 2 — HR. Only now does it take effect.
    corrHr()->post(route('hrm.attendance.corrections.approve', $correction))->assertRedirect();

    $correction->refresh();
    expect($correction->isApplied())->toBeTrue();

    $day = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->sole();
    expect($day->status)->toBe(AttendanceStatus::Present);
});

it('does not let a stage be approved by someone it does not allow', function () {
    corrPunch('2026-08-03 09:00:00', 'in');

    $correction = app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot.',
    ], $this->staffUser);

    corrStaff()->post(route('hrm.attendance.corrections.approve', $correction))->assertForbidden();
});

it('leaves the day untouched when a correction is rejected', function () {
    corrPunch('2026-08-03 09:00:00', 'in');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');

    $correction = app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot.',
    ], $this->staffUser);

    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.attendance.corrections.reject', $correction), ['note' => 'No evidence.'])
        ->assertRedirect();

    $correction->refresh();
    expect($correction->approvalRequest->status)->toBe(ApprovalStatus::Rejected);
    expect($correction->isApplied())->toBeFalse();

    $day = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->sole();
    expect($day->status)->toBe(AttendanceStatus::MissingPunch);
});

it('shows a pending correction in the shared approval inbox', function () {
    corrPunch('2026-08-03 09:00:00', 'in');

    app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot.',
    ], $this->staffUser);

    $actionable = app(ApprovalInbox::class)->pendingActionableFor($this->manager);

    expect($actionable)->toHaveCount(1);
    expect($actionable->first()->approvable)->toBeInstanceOf(AttendanceCorrection::class);
});

// --- COR-04: evidence ---------------------------------------------------------

it('accepts optional evidence through the attachment engine', function () {
    Storage::fake('local');
    corrPunch('2026-08-03 09:00:00', 'in');

    $correction = app(RequestCorrection::class)->handle(
        $this->employee, '2026-08-03',
        [
            'type' => CorrectionType::AddPunch->value,
            'corrected_at' => '2026-08-03 18:00:00',
            'direction' => 'out',
            'reason' => 'Was at the client site.',
        ],
        $this->hr,
        UploadedFile::fake()->create('gate-log.pdf', 40, 'application/pdf'),
    );

    expect($correction->attachments()->count())->toBe(1);
});

// --- COR-02 / COR-05 / C1: the period lock ------------------------------------

it('blocks an employee from correcting a locked period', function () {
    corrPunch('2026-08-03 09:00:00', 'in');

    AttendancePeriodLock::create([
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'locked_at' => now(), 'locked_by' => $this->hr->id,
    ]);

    expect(fn () => app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot.',
    ], $this->staffUser))->toThrow(ValidationException::class);

    expect(AttendanceCorrection::count())->toBe(0);
});

it('requires an override reason when HR corrects a locked period', function () {
    corrPunch('2026-08-03 09:00:00', 'in');

    AttendancePeriodLock::create([
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'locked_at' => now(), 'locked_by' => $this->hr->id,
    ]);

    $payload = [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot.',
    ];

    expect(fn () => app(RequestCorrection::class)->handle($this->employee, '2026-08-03', $payload, $this->hr))
        ->toThrow(ValidationException::class);

    // With a stated override it goes through, and the justification is stored.
    $correction = app(RequestCorrection::class)->handle(
        $this->employee, '2026-08-03',
        [...$payload, 'override_reason' => 'Payroll re-run authorised by the director.'],
        $this->hr,
    );

    expect($correction->isApplied())->toBeTrue();
    expect($correction->override_reason)->toBe('Payroll re-run authorised by the director.');
});

it('covers the boundary days of a locked period', function () {
    AttendancePeriodLock::create([
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'locked_at' => now(), 'locked_by' => $this->hr->id,
    ]);

    expect(AttendancePeriodLock::isLocked('2026-08-01'))->toBeTrue();
    expect(AttendancePeriodLock::isLocked('2026-08-31'))->toBeTrue();
    expect(AttendancePeriodLock::isLocked('2026-07-31'))->toBeFalse();
    expect(AttendancePeriodLock::isLocked('2026-09-01'))->toBeFalse();
});

it('reopens a period through an audited unlock rather than a delete', function () {
    $lock = AttendancePeriodLock::create([
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'locked_at' => now(), 'locked_by' => $this->hr->id,
    ]);

    corrHr()->patch(route('hrm.attendance.locks.unlock', $lock), ['unlock_reason' => 'Corrections agreed with Finance.'])
        ->assertRedirect();

    $lock->refresh();
    expect($lock->isActive())->toBeFalse();
    expect($lock->unlock_reason)->toBe('Corrections agreed with Finance.');
    expect(AttendancePeriodLock::isLocked('2026-08-03'))->toBeFalse();
    expect(AttendancePeriodLock::count())->toBe(1);
});

it('locks a period from the screen', function () {
    corrHr()->post(route('hrm.attendance.locks.store'), [
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-07',
        'note' => 'Week 32',
    ])->assertRedirect();

    expect(AttendancePeriodLock::isLocked('2026-08-05'))->toBeTrue();
});

// --- Self-scope and access ----------------------------------------------------

it('lets an employee raise a correction on their own day from self-service', function () {
    // A real past day: self-service rejects correcting a date that has not
    // happened yet, so this cannot use the fixed 2026-08 dates above.
    $date = now()->subDays(3)->toDateString();
    corrPunch("{$date} 09:00:00", 'in');

    corrStaff()->post(route('hrm.clock.corrections.store'), [
        'attendance_date' => $date,
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => "{$date} 18:00:00",
        'direction' => 'out',
        'reason' => 'Forgot to clock out.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $correction = AttendanceCorrection::sole();
    expect($correction->employee_id)->toBe($this->employee->id);
    expect($correction->isApplied())->toBeFalse();
});

it('refuses a self-service correction for a day that has not happened yet', function () {
    corrStaff()->post(route('hrm.clock.corrections.store'), [
        'attendance_date' => now()->addDay()->toDateString(),
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => now()->addDay()->format('Y-m-d 18:00:00'),
        'direction' => 'out',
        'reason' => 'Pre-emptive.',
    ])->assertSessionHasErrors('attendance_date');
});

it('stops an employee referencing a punch that is not theirs', function () {
    $other = Employee::create([
        'employee_code' => 'BDG-000002',
        'first_name' => 'Rahim', 'last_name' => 'Test',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $other->id, 'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2026-01-01',
    ]);
    $theirPunch = app(RecordPunch::class)->handle($other, '2026-08-03 09:00:00', PunchSource::Biometric, []);

    corrStaff()->post(route('hrm.clock.corrections.store'), [
        'attendance_date' => '2026-08-03',
        'type' => CorrectionType::IgnorePunch->value,
        'punch_id' => $theirPunch->id,
        'reason' => 'Not mine to touch.',
    ])->assertSessionHasErrors('punch_id');
});

it('blocks a user without the attendance permission from the corrections screen', function () {
    corrStaff()->get(route('hrm.attendance.corrections.index'))->assertForbidden();
});

it('renders the corrections screen for HR', function () {
    corrPunch('2026-08-03 09:00:00', 'in');
    app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot.',
    ], $this->hr);

    corrHr()->get(route('hrm.attendance.corrections.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/attendance/corrections')
            ->has('corrections', 1)
            ->where('corrections.0.is_applied', true));
});

it('accepts a correction time that carries seconds', function () {
    // ⚠⚠ The screen built the timestamp as value.replace('T',' ') + ':00', which
    // appended seconds whether or not the value already had them. A time that
    // carried seconds became "2026-08-08 11:13:33:00" - not a timestamp - and
    // the request failed validation with nothing shown to the user.
    //
    // Seconds matter more here than anywhere: the commonest reason to amend a
    // punch back is to restore what the DEVICE recorded, and the device records
    // to the second. Found by using the form to undo a demonstration.
    $punch = corrPunch('2026-08-08 11:13:33');

    corrHr()->post(route('hrm.attendance.corrections.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-08',
        'type' => 'amend_punch',
        'punch_id' => $punch->id,
        'corrected_at' => '2026-08-08 09:00:17',
        'reason' => 'Restoring a time that carries seconds.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(AttendanceCorrection::latest('id')->first()->corrected_at->format('H:i:s'))
        ->toBe('09:00:17');
});

it('re-runs the later days of the cycle so a corrected late no longer counts on them', function () {
    $lateIn = corrPunch('2026-08-03 09:47:00', 'in');
    corrPunch('2026-08-03 18:00:00', 'out');
    corrPunch('2026-08-04 09:47:00', 'in');
    corrPunch('2026-08-04 18:00:00', 'out');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');
    $later = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-04');
    expect($later->occurrence_number)->toBe(2);

    app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AmendPunch->value,
        'punch_id' => $lateIn->id,
        'corrected_at' => '2026-08-03 09:00:00',
        'direction' => 'in',
        'reason' => 'Device clock drift.',
    ], $this->hr);

    // 4 Aug was counted as the second late; with 3 Aug corrected it is the first.
    expect($later->fresh()->occurrence_number)->toBe(1);
    // Nothing is invented for days that have no record yet.
    expect(DailyAttendance::where('employee_id', $this->employee->id)->count())->toBe(2);
});

it('rolls the final approval back when applying the correction fails', function () {
    corrPunch('2026-08-03 09:00:00', 'in');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');
    $correction = app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot to clock out.',
    ], $this->staffUser);
    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.attendance.corrections.approve', $correction))->assertRedirect();

    $this->mock(ApplyCorrection::class)->shouldReceive('handle')->andThrow(new RuntimeException('apply failed'));

    corrHr()->post(route('hrm.attendance.corrections.approve', $correction))->assertStatus(500);

    $approval = $correction->fresh()->approvalRequest;
    expect($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->current_stage)->toBe(2)
        ->and($correction->fresh()->isApplied())->toBeFalse();
});

it('refuses an HR correction with no date or a future date', function () {
    // ⚠ Audit 13 Oct 2026: the date was read before it was validated.
    $payload = [
        'employee_id' => $this->employee->id,
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot.',
    ];

    corrHr()->post(route('hrm.attendance.corrections.store'), $payload)->assertSessionHasErrors('attendance_date');
    corrHr()->post(route('hrm.attendance.corrections.store'), [...$payload, 'attendance_date' => now()->addDay()->toDateString()])
        ->assertSessionHasErrors('attendance_date');

    expect(AttendanceCorrection::count())->toBe(0);
});

it('refuses the final approval once the period has been locked since the request', function () {
    corrPunch('2026-08-03 09:00:00', 'in');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');
    $correction = app(RequestCorrection::class)->handle($this->employee, '2026-08-03', [
        'type' => CorrectionType::AddPunch->value,
        'corrected_at' => '2026-08-03 18:00:00',
        'direction' => 'out',
        'reason' => 'Forgot to clock out.',
    ], $this->staffUser);
    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.attendance.corrections.approve', $correction))->assertRedirect();

    AttendancePeriodLock::create([
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'locked_at' => now(), 'locked_by' => $this->hr->id,
    ]);

    corrHr()->post(route('hrm.attendance.corrections.approve', $correction))->assertSessionHasErrors('attendance_date');

    expect($correction->fresh()->isApplied())->toBeFalse()
        ->and($correction->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Pending);
});
