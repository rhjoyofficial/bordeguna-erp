<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy's daily-attendance rework, 23 Aug 2026: a day "can be fixed direct from
 * here", the employee "will see on his profile what who did", and the grid
 * shows the trail.
 *
 * The fix path is the EXISTING privileged correction (file + apply + reprocess
 * in one step) reached from the grid row, so these tests pin the surfaces, not
 * a new engine.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Fix Co', 'slug' => 'fix-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.attendance.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.attendance.manage');
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->hr = User::factory()->create(['name' => 'Musa Islam']);
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->employee = Employee::create([
        'employee_code' => 'FIX-1', 'first_name' => 'Rakib', 'last_name' => 'Hossain',
        'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2026-01-01',
    ]);

    // The real shape of a held day: the person came to work (a morning punch
    // exists) and never punched out. A correction supplying the out closes the
    // pair; without the morning punch the day would rightly STAY missing.
    app(\App\Modules\Hrm\Actions\RecordPunch::class)->handle(
        $this->employee,
        '2026-08-19 08:30:00',
        \App\Modules\Hrm\Enums\PunchSource::Manual,
        ['direction' => 'in', 'recorded_by' => $this->hr->id],
    );

    DailyAttendance::create([
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-19',
        'status' => AttendanceStatus::MissingPunch,
        'consequence' => AttendanceConsequence::None,
        'worked_minutes' => 0, 'payable_minutes' => 0, 'break_minutes' => 0,
        'late_minutes' => 0, 'early_leave_minutes' => 0,
        'is_provisional' => true, 'holds_payroll' => true,
    ]);
});

it('applies a fix filed from the grid immediately, with no approval round-trip', function () {
    $this->actingAs($this->hr)->post('/hrm/attendance/corrections', [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-19',
        'type' => 'add_punch',
        'corrected_at' => '2026-08-19 18:05:00',
        'direction' => 'out',
        'reason' => 'Forgot to punch out, confirmed by supervisor.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $correction = AttendanceCorrection::sole();

    // Applied on the spot - the privileged path - and by the person who filed it.
    expect($correction->isApplied())->toBeTrue()
        ->and($correction->requested_by)->toBe($this->hr->id);

    // The reprocessed day closed its pair from the corrected punch and let go
    // of payroll.
    $day = DailyAttendance::where('employee_id', $this->employee->id)
        ->whereDate('attendance_date', '2026-08-19')->sole();

    expect($day->holds_payroll)->toBeFalse()
        ->and($day->last_out?->format('H:i'))->toBe('18:05');
});

it('shows the fix trail on the daily grid row', function () {
    $this->actingAs($this->hr)->post('/hrm/attendance/corrections', [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-19',
        'type' => 'add_punch',
        'corrected_at' => '2026-08-19 18:05:00',
        'direction' => 'out',
        'reason' => 'Forgot to punch out.',
    ]);

    $this->actingAs($this->hr)
        ->get('/hrm/attendance/daily?from=2026-08-19&to=2026-08-19')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('days.0.fixes.0.by', 'Musa Islam')
            ->where('days.0.fixes.0.summary', fn ($summary) => str_contains($summary, 'add a'))
            // The dialog's vocabulary rides along so the row can offer the fix.
            ->has('correction_types')
            ->has('directions'));
});

// ⚠⚠ REVERSED BY JOY ON 24 AUG 2026, one day after he asked for it. The
// employee's own row used to name the fix and the person who made it; he
// decided a profile carries no such narration while the ERP is starting
// fresh ("this kinds of text disturbing"), and that it can come back when
// he asks. The correction itself is untouched and HR still reads the trail
// on the daily grid - the test above pins that half and stays.
it('keeps the fix trail off the employee\'s own profile', function () {
    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);
    $user->assignRole('Staff');
    $this->employee->update(['user_id' => $user->id]);

    $this->actingAs($this->hr)->post('/hrm/attendance/corrections', [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-19',
        'type' => 'add_punch',
        'corrected_at' => '2026-08-19 18:05:00',
        'direction' => 'out',
        'reason' => 'Forgot to punch out.',
    ]);

    $this->actingAs($user)
        ->get('/hrm/clock?from=2026-08-15&to=2026-08-23')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The day is still there, and still corrected - it simply does
            // not narrate who changed what.
            ->has('recent_days')
            ->missing('recent_days.0.fixes')
            ->missing('my_corrections'));

    // The correction itself survived the screen losing its voice.
    expect(AttendanceCorrection::where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('still routes a non-privileged request through approval instead of applying it', function () {
    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);
    $user->assignRole('Staff');
    $this->employee->update(['user_id' => $user->id]);

    // The employee's own request from the clock page: recorded, NOT applied.
    $this->actingAs($user)->post('/hrm/clock/corrections', [
        'attendance_date' => '2026-08-19',
        'type' => 'add_punch',
        'corrected_at' => '2026-08-19 18:05:00',
        'direction' => 'out',
        'reason' => 'Forgot to punch out.',
    ])->assertRedirect();

    $correction = AttendanceCorrection::sole();

    expect($correction->isApplied())->toBeFalse()
        ->and($correction->approvalRequest)->not->toBeNull();

    // And the day still holds payroll until somebody with authority decides.
    expect(DailyAttendance::where('employee_id', $this->employee->id)
        ->whereDate('attendance_date', '2026-08-19')->sole()->holds_payroll)->toBeTrue();
});
