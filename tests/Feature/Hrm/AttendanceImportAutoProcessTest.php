<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\ImportAttendancePunches;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The auto-recompute an import now carries (24 Aug 2026).
 *
 * The lesson these pin: an import lands PUNCHES, and every screen kept
 * showing the old world until the nightly run - "imported but showing
 * nothing", three times in one week. Importing now recomputes exactly the
 * days it touched, with force, because a new punch on a judged day is new
 * evidence for that day.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'AutoProc Co', 'slug' => 'autoproc-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.attendance.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.attendance.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

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
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $this->calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'biometric_enroll_id' => '7',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);
});

function autoProcCsv(array $lines): string
{
    return "Employee ID,Date,Time,Status,Device\n".implode("\n", $lines)."\n";
}

it('recomputes exactly the imported days, and only those, without any command', function () {
    // 2026-07-27 is a Monday; 2026-07-26 gets no rows and must stay untouched.
    $batch = app(ImportAttendancePunches::class)->handle(autoProcCsv([
        '7,2026-07-27,08:55:00,C/In,K40-1',
        '7,2026-07-27,18:03:00,C/Out,K40-1',
        '7,2026-07-28,08:56:00,C/In,K40-1',
        '7,2026-07-28,18:01:00,C/Out,K40-1',
    ]), 'two-days.csv', PunchSource::Biometric);

    expect($batch->imported_count)->toBe(4)
        ->and($batch->recomputedDays)->toBe(2)
        ->and($batch->processingError)->toBeNull();

    $days = DailyAttendance::where('employee_id', $this->employee->id)->get()->keyBy(fn ($d) => $d->attendance_date->toDateString());
    expect($days->get('2026-07-27')?->status->value)->toBe('present')
        ->and($days->get('2026-07-28')?->status->value)->toBe('present')
        ->and($days->has('2026-07-26'))->toBeFalse();
});

it('recomputes a finalised day that receives a new punch - a punch is new evidence', function () {
    app(ImportAttendancePunches::class)->handle(autoProcCsv([
        '7,2026-07-27,08:55:00,C/In,K40-1',
    ]), 'morning.csv', PunchSource::Biometric);

    $day = DailyAttendance::where('employee_id', $this->employee->id)->whereDate('attendance_date', '2026-07-27')->firstOrFail();
    expect($day->status->value)->toBe('missing_punch');

    // Judged and closed - and then the evening punch arrives in a later export.
    $day->forceFill(['finalized_at' => now(), 'finalized_by' => $this->owner->id])->save();

    $batch = app(ImportAttendancePunches::class)->handle(autoProcCsv([
        '7,2026-07-27,08:55:00,C/In,K40-1',
        '7,2026-07-27,18:04:00,C/Out,K40-1',
    ]), 'evening.csv', PunchSource::Biometric);

    expect($batch->imported_count)->toBe(1)
        ->and($batch->recomputedDays)->toBe(1);

    expect($day->fresh()->status->value)->toBe('present');
});

it('does not recompute anything when every row is a duplicate', function () {
    $file = autoProcCsv(['7,2026-07-27,08:55:00,C/In,K40-1', '7,2026-07-27,18:03:00,C/Out,K40-1']);

    app(ImportAttendancePunches::class)->handle($file, 'day.csv', PunchSource::Biometric);
    $rerun = app(ImportAttendancePunches::class)->handle($file, 'day.csv', PunchSource::Biometric);

    expect($rerun->wasEntirelyDuplicate())->toBeTrue()
        ->and($rerun->recomputedDays)->toBeNull();
});

it('tells the uploader how many days were recomputed, right in the flash', function () {
    $file = UploadedFile::fake()->createWithContent('punches.csv', autoProcCsv([
        '7,2026-07-27,08:55:00,C/In,K40-1',
        '7,2026-07-27,18:03:00,C/Out,K40-1',
    ]));

    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.attendance.import.store'), ['file' => $file, 'source' => 'biometric'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, '2 imported') && str_contains($message, '1 day recomputed.'));
});

it('keeps the import when the recompute blows up, and says where it stopped', function () {
    $this->mock(ProcessAttendance::class)
        ->shouldReceive('forDate')
        ->andThrow(new RuntimeException('The engine had a very bad day'));

    $batch = app(ImportAttendancePunches::class)->handle(autoProcCsv([
        '7,2026-07-27,08:55:00,C/In,K40-1',
        '7,2026-07-28,08:56:00,C/In,K40-1',
    ]), 'doomed.csv', PunchSource::Biometric);

    // The punches and the batch survived; the failure is reported, not thrown.
    expect(AttendancePunch::count())->toBe(2)
        ->and($batch->exists)->toBeTrue()
        ->and($batch->imported_count)->toBe(2)
        ->and($batch->recomputedDays)->toBe(0)
        ->and($batch->processingError)->toContain('2026-07-27')
        ->and($batch->processingError)->toContain('The engine had a very bad day')
        ->and($batch->processingError)->toContain('nightly run');
});

it('handles a realistic month-size import in one request', function () {
    // 300 rows over 31 days for 9 employees - the shape of Joy's real file.
    // Measured here so the synchronous design carries its own evidence; see
    // the comment in ImportAttendancePunches::recomputeImportedDays.
    $employees = collect(range(1, 9))->map(function (int $n) {
        $employee = Employee::create([
            'employee_code' => 'BDG-10000'.$n,
            'biometric_enroll_id' => (string) (100 + $n),
            'first_name' => 'Emp'.$n, 'last_name' => 'Bulk',
            'employment_type' => 'permanent', 'status' => 'active',
        ]);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'office_id' => $this->office->id,
            'is_primary' => true, 'effective_from' => '2025-01-01',
        ]);

        return $employee;
    });

    $lines = [];
    foreach (range(0, 33) as $offset) {
        $date = Illuminate\Support\Carbon::parse('2026-07-24')->addDays($offset)->toDateString();
        foreach ($employees as $i => $employee) {
            if (count($lines) >= 300) {
                break 2;
            }
            $lines[] = sprintf('%s,%s,09:%02d:00,C/In,K40-1', $employee->biometric_enroll_id, $date, ($i + $offset) % 50);
        }
    }

    $batch = app(ImportAttendancePunches::class)->handle(autoProcCsv($lines), 'month.csv', PunchSource::Biometric);

    expect($batch->imported_count)->toBe(300)
        ->and($batch->recomputedDays)->toBe(34)
        ->and($batch->processingError)->toBeNull();

    expect(DailyAttendance::count())->toBeGreaterThan(0);
});
