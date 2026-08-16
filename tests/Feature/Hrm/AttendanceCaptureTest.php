<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ImportAttendancePunches;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Exceptions\ImmutablePunchException;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Support\AttendanceFileReader;
use App\Modules\Hrm\Support\Geofence;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Att Co', 'slug' => 'att-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Att', 'slug' => 'other-att']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.attendance.manage', 'guard_name' => 'web']);
    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
        Role::where('business_id', $biz->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.attendance.manage');
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
});

function attOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function attEmployee(string $name = 'Karim', ?string $enrollId = null, ?User $user = null): Employee
{
    $employee = Employee::create([
        'employee_code' => 'BDG-'.str_pad((string) (Employee::count() + 1), 6, '0', STR_PAD_LEFT),
        'biometric_enroll_id' => $enrollId,
        'user_id' => $user?->id,
        'first_name' => $name,
        'last_name' => 'Test',
        'employment_type' => 'permanent',
        'status' => 'active',
    ]);

    EmployeeAssignment::create([
        'employee_id' => $employee->id,
        'office_id' => test()->office->id,
        'is_primary' => true,
        'effective_from' => '2026-01-01',
    ]);

    return $employee;
}

// --- ATT-03 immutability -----------------------------------------------------

it('refuses to update a raw punch', function () {
    $employee = attEmployee();
    $punch = app(RecordPunch::class)->handle($employee, '2026-07-25 09:00:00', PunchSource::Manual, ['note' => 'register']);

    expect(fn () => $punch->update(['note' => 'tampered']))->toThrow(ImmutablePunchException::class);
    expect($punch->fresh()->note)->toBe('register');
});

it('refuses to delete a raw punch', function () {
    $employee = attEmployee();
    $punch = app(RecordPunch::class)->handle($employee, '2026-07-25 09:00:00', PunchSource::Manual, ['note' => 'register']);

    expect(fn () => $punch->delete())->toThrow(ImmutablePunchException::class);
    expect(AttendancePunch::count())->toBe(1);
});

it('exposes no route that could edit or delete a punch', function () {
    $names = collect(app('router')->getRoutes())->map(fn ($route) => $route->getName())->filter();

    expect($names->contains('hrm.attendance.update'))->toBeFalse();
    expect($names->contains('hrm.attendance.destroy'))->toBeFalse();
});

// --- ATT-08 idempotency ------------------------------------------------------

it('treats the same employee, time and device as one punch', function () {
    $employee = attEmployee();
    $record = app(RecordPunch::class);

    $first = $record->handle($employee, '2026-07-25 09:00:00', PunchSource::Biometric, ['device_identifier' => 'K40-1']);
    $second = $record->handle($employee, '2026-07-25 09:00:00', PunchSource::Biometric, ['device_identifier' => 'K40-1']);

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();
    expect(AttendancePunch::count())->toBe(1);
});

it('keeps the same timestamp from two different devices as two punches', function () {
    $employee = attEmployee();
    $record = app(RecordPunch::class);

    $record->handle($employee, '2026-07-25 09:00:00', PunchSource::Biometric, ['device_identifier' => 'K40-1']);
    $record->handle($employee, '2026-07-25 09:00:00', PunchSource::Biometric, ['device_identifier' => 'K40-2']);

    expect(AttendancePunch::count())->toBe(2);
});

it('re-imports the same export without creating anything new', function () {
    attEmployee('Karim', '7');

    $csv = <<<'CSV'
    Employee ID,Date,Time,Status,Device
    7,2026-07-25,09:01:00,C/In,K40-1
    7,2026-07-25,18:03:00,C/Out,K40-1
    CSV;

    $first = app(ImportAttendancePunches::class)->handle($csv, 'day1.csv', PunchSource::Biometric);
    expect($first->imported_count)->toBe(2);
    expect($first->duplicate_count)->toBe(0);

    $second = app(ImportAttendancePunches::class)->handle($csv, 'day1.csv', PunchSource::Biometric);
    expect($second->imported_count)->toBe(0);
    expect($second->duplicate_count)->toBe(2);
    expect($second->wasEntirelyDuplicate())->toBeTrue();

    expect(AttendancePunch::count())->toBe(2);
});

it('imports the new rows of a partially overlapping export instead of blocking', function () {
    attEmployee('Karim', '7');

    app(ImportAttendancePunches::class)->handle(
        "Employee ID,Date,Time\n7,2026-07-25,09:01:00\n",
        'day1.csv',
    );

    $batch = app(ImportAttendancePunches::class)->handle(
        "Employee ID,Date,Time\n7,2026-07-25,09:01:00\n7,2026-07-26,09:04:00\n",
        'day1-2.csv',
    );

    expect($batch->imported_count)->toBe(1);
    expect($batch->duplicate_count)->toBe(1);
    expect(AttendancePunch::count())->toBe(2);
});

// --- ATT-01/02 import parsing ------------------------------------------------

it('reads direction and device from a ZKTeco-style export', function () {
    attEmployee('Karim', '7');

    app(ImportAttendancePunches::class)->handle(
        "AC-No.,Date,Time,State,Machine\n7,2026-07-25,09:01:00,C/In,K40-Gate1\n",
        'zk.csv',
    );

    $punch = AttendancePunch::sole();
    expect($punch->direction)->toBe(PunchDirection::In);
    expect($punch->device_identifier)->toBe('K40-Gate1');
    expect($punch->enroll_id)->toBe('7');
    expect($punch->punched_at->format('Y-m-d H:i:s'))->toBe('2026-07-25 09:01:00');
});

it('accepts a single date-time column as well as separate date and time', function () {
    attEmployee('Karim', '7');

    app(ImportAttendancePunches::class)->handle(
        "UserID,DateTime\n7,2026-07-25 09:01:00\n",
        'combined.csv',
    );

    expect(AttendancePunch::sole()->punched_at->format('Y-m-d H:i'))->toBe('2026-07-25 09:01');
});

it('leaves direction unknown when the device status is unrecognised', function () {
    attEmployee('Karim', '7');

    app(ImportAttendancePunches::class)->handle(
        "UserID,DateTime,Status\n7,2026-07-25 09:01:00,Fingerprint\n",
        'odd.csv',
    );

    expect(AttendancePunch::sole()->direction)->toBeNull();
});

it('matches enroll IDs despite leading-zero formatting', function () {
    attEmployee('Karim', '7');

    $batch = app(ImportAttendancePunches::class)->handle(
        "UserID,DateTime\n007,2026-07-25 09:01:00\n",
        'padded.csv',
    );

    expect($batch->imported_count)->toBe(1);
});

it('reports unmapped and unreadable rows instead of dropping them', function () {
    attEmployee('Karim', '7');

    $batch = app(ImportAttendancePunches::class)->handle(
        "UserID,DateTime\n7,2026-07-25 09:01:00\n99,2026-07-25 09:02:00\n7,not-a-date\n",
        'mixed.csv',
    );

    expect($batch->imported_count)->toBe(1);
    expect($batch->failed_count)->toBe(2);
    expect($batch->total_rows)->toBe(3);

    $reasons = collect($batch->report)->pluck('reason')->implode(' ');
    expect($reasons)->toContain('No employee is mapped to enroll ID 99');
    expect($reasons)->toContain('valid date/time');
});

it('fails loudly and names the columns it found when the file is unrecognisable', function () {
    expect(fn () => app(ImportAttendancePunches::class)->handle(
        "Colour,Shape\nred,round\n",
        'wrong.csv',
    ))->toThrow(ValidationException::class);
});

// --- Correction C2 / ATT-05 proof and geofence -------------------------------

it('measures distance from the office geofence', function () {
    // Roughly 1.1 km apart at this latitude.
    $metres = Geofence::distanceInMeters(23.8103, 90.4125, 23.8203, 90.4125);

    expect($metres)->toBeGreaterThan(1000)->toBeLessThan(1200);
});

it('accepts and flags a clock-in outside an enforced geofence, never rejecting it', function () {
    $this->office->update([
        'geofence_latitude' => 23.8103,
        'geofence_longitude' => 90.4125,
        'geofence_radius_meters' => 100,
        'geofence_enforced' => true,
    ]);

    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);
    $employee = attEmployee('Karim', null, $user);

    $this->actingAs($user)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.clock.store'), [
            'direction' => 'in',
            'latitude' => 23.8303, // ~2 km away
            'longitude' => 90.4125,
            'gps_accuracy_meters' => 12.5,
        ])->assertRedirect();

    $punch = AttendancePunch::sole();
    expect($punch->employee_id)->toBe($employee->id);
    expect($punch->is_outside_geofence)->toBeTrue();
    expect($punch->needs_review)->toBeTrue();
    expect($punch->review_reason)->toContain('Head Office');
});

it('does not flag a clock-in inside the geofence', function () {
    $this->office->update([
        'geofence_latitude' => 23.8103,
        'geofence_longitude' => 90.4125,
        'geofence_radius_meters' => 500,
        'geofence_enforced' => true,
    ]);

    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);
    attEmployee('Karim', null, $user);

    $this->actingAs($user)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.clock.store'), [
            'direction' => 'in',
            'latitude' => 23.8104,
            'longitude' => 90.4126,
            'gps_accuracy_meters' => 8,
        ])->assertRedirect();

    expect(AttendancePunch::sole()->needs_review)->toBeFalse();
});

it('ignores the geofence entirely when the office does not enforce one', function () {
    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);
    attEmployee('Karim', null, $user);

    $this->actingAs($user)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.clock.store'), [
            'direction' => 'in',
            'latitude' => 1.0,
            'longitude' => 1.0,
            'gps_accuracy_meters' => 9,
        ])->assertRedirect();

    expect(AttendancePunch::sole()->needs_review)->toBeFalse();
});

it('captures IP and device metadata on a self-service punch', function () {
    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);
    attEmployee('Karim', null, $user);

    $this->actingAs($user)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.clock.store'), [
            'direction' => 'in',
            'latitude' => 23.81,
            'longitude' => 90.41,
            'gps_accuracy_meters' => 5,
            'device_metadata' => ['platform' => 'Win32'],
        ])->assertRedirect();

    $punch = AttendancePunch::sole();
    expect($punch->ip_address)->not->toBeNull();
    expect($punch->device_metadata)->toBe(['platform' => 'Win32']);
    expect($punch->source)->toBe(PunchSource::Web);
});

it('requires location on a self-service punch', function () {
    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);
    attEmployee('Karim', null, $user);

    $this->actingAs($user)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.clock.store'), ['direction' => 'in'])
        ->assertSessionHasErrors(['latitude', 'longitude', 'gps_accuracy_meters']);

    expect(AttendancePunch::count())->toBe(0);
});

it('lets an employee reach their own clock page without any management permission', function () {
    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);
    $user->assignRole('Staff');
    attEmployee('Karim', null, $user);

    $this->actingAs($user)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.clock'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('hrm/attendance/clock'));
});

it('redirects a user with no employee record away from the clock page', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.clock'))
        ->assertRedirect(route('dashboard'));
});

// --- ATT-07 manual entry -----------------------------------------------------

it('records a manual punch with a mandatory reason', function () {
    $employee = attEmployee();

    attOwner()->post(route('hrm.attendance.store'), [
        'employee_id' => $employee->id,
        'punched_at' => '2026-07-25 09:15:00',
        'direction' => 'in',
        'note' => 'Device offline, from the manual register',
    ])->assertRedirect();

    $punch = AttendancePunch::sole();
    expect($punch->source)->toBe(PunchSource::Manual);
    expect($punch->note)->toBe('Device offline, from the manual register');
    expect($punch->recorded_by)->toBe($this->owner->id);
});

it('rejects a manual punch with no reason', function () {
    $employee = attEmployee();

    attOwner()->post(route('hrm.attendance.store'), [
        'employee_id' => $employee->id,
        'punched_at' => '2026-07-25 09:15:00',
    ])->assertSessionHasErrors('note');
});

it('rejects a manual punch for an employee outside the business', function () {
    app(BusinessContext::class)->set($this->otherBusiness->id);
    $foreignOffice = Office::create(['name' => 'Their Office', 'is_active' => true]);
    $foreign = Employee::create([
        'employee_code' => 'BDG-999999',
        'first_name' => 'Outsider', 'last_name' => 'Test',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $foreign->id, 'office_id' => $foreignOffice->id,
        'is_primary' => true, 'effective_from' => '2026-01-01',
    ]);
    app(BusinessContext::class)->set($this->business->id);

    attOwner()->post(route('hrm.attendance.store'), [
        'employee_id' => $foreign->id,
        'punched_at' => '2026-07-25 09:15:00',
        'note' => 'should not work',
    ])->assertSessionHasErrors('employee_id');
});

// --- Screens and scoping -----------------------------------------------------

it('renders the punch log for a date', function () {
    $employee = attEmployee();
    app(RecordPunch::class)->handle($employee, '2026-07-25 09:00:00', PunchSource::Manual, ['note' => 'register']);

    attOwner()->get(route('hrm.attendance.index', ['on' => '2026-07-25']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/attendance/index')
            // Paginated since the log became a range rather than a single day.
            ->where('punches.data.0.employee', $employee->fullName())
            ->where('punches.data.0.date', '2026-07-25')
            ->where('summary.total', 1)
            ->where('filters.from', '2026-07-25')
            ->where('filters.to', '2026-07-25')
        );
});

it('shows a whole range of punches in date order, not just one day', function () {
    $employee = attEmployee();

    foreach (['2026-07-25 09:00:00', '2026-07-27 09:05:00', '2026-08-01 08:55:00'] as $at) {
        app(RecordPunch::class)->handle($employee, $at, PunchSource::Biometric, []);
    }

    attOwner()->get(route('hrm.attendance.index', ['from' => '2026-07-25', 'to' => '2026-08-01', 'dir' => 'asc']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.total', 3)
            ->where('summary.days', 3)
            ->where('punches.data.0.date', '2026-07-25')
            ->where('punches.data.2.date', '2026-08-01')
        );
});

it('anchors the default window on the newest punch rather than on today', function () {
    // ⚠ The defect this screen was reported for: 136 punches imported cleanly,
    // the log opened on a day the device recorded none, and it said nothing was
    // captured. Defaulting to today is what made a full import look empty.
    $employee = attEmployee();
    app(RecordPunch::class)->handle($employee, '2026-07-25 09:00:00', PunchSource::Biometric, []);

    attOwner()->get(route('hrm.attendance.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.to', '2026-07-25')
            ->where('summary.total', 1)
        );
});

it('uploads a file through the import screen', function () {
    attEmployee('Karim', '7');

    attOwner()->post(route('hrm.attendance.import.store'), [
        'file' => UploadedFile::fake()->createWithContent('zk.csv', "UserID,DateTime\n7,2026-07-25 09:01:00\n"),
        'source' => 'biometric',
        'device_identifier' => 'K40-1',
    ])->assertRedirect();

    expect(AttendancePunch::count())->toBe(1);
});

it('blocks a user without the attendance permission', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.attendance.index'))->assertForbidden();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.attendance.import.index'))->assertForbidden();
});

it('does not leak punches across businesses', function () {
    $employee = attEmployee();
    app(RecordPunch::class)->handle($employee, '2026-07-25 09:00:00', PunchSource::Manual, ['note' => 'register']);

    app(BusinessContext::class)->set($this->otherBusiness->id);
    expect(AttendancePunch::count())->toBe(0);
});

it('keeps one biometric enroll ID from being shared by two employees', function () {
    $first = attEmployee('Karim', '7');
    $second = attEmployee('Rahim');

    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo('hrm.employees.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    attOwner()->patch(route('hrm.employees.update', $second->id), [
        'first_name' => 'Rahim',
        'last_name' => 'Test',
        'employment_type' => 'permanent',
        'biometric_enroll_id' => '7',
    ])->assertSessionHasErrors('biometric_enroll_id');

    expect($first->fresh()->biometric_enroll_id)->toBe('7');
});

// --- Spreadsheet uploads (ATT-02): the device's own export, unconverted ------

/**
 * Writes a spreadsheet shaped like Bor De Guna's real ZKTeco export, including
 * the two things that file does which a hand-made fixture would not:
 *
 *  - `Date/Time` is a genuine date CELL, not text, so it carries a serial
 *    number and a format rather than anything a date parser could read;
 *  - `CardNo` is the FORMULA `="bdg-"&F{row}`, not a literal.
 */
function attSpreadsheet(string $extension, array $rows, bool $padColumns = false): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Department', 'Name', 'No.', 'Date/Time', 'Location ID', 'ID Number', 'VerifyCode', 'CardNo'], null, 'A1');

    foreach ($rows as $index => $row) {
        $line = $index + 2;
        $sheet->setCellValue("A{$line}", $row['dept'] ?? 'Head Office');
        $sheet->setCellValue("B{$line}", $row['name'] ?? 'Karim Test');
        $sheet->setCellValueExplicit("C{$line}", $row['no'], DataType::TYPE_STRING);
        $sheet->setCellValue("D{$line}", Date::PHPToExcel(new DateTime($row['at'])));
        $sheet->getStyle("D{$line}")->getNumberFormat()->setFormatCode('m/d/yyyy h:mm:ss AM/PM');
        $sheet->setCellValue("E{$line}", $row['location'] ?? '104');
        $sheet->setCellValueExplicit("F{$line}", $row['id'] ?? '000020', DataType::TYPE_STRING);
        $sheet->setCellValue("G{$line}", 'FP');
        $sheet->setCellValue("H{$line}", '="bdg-"&F'.$line);

        if ($padColumns) {
            // The legacy .xls reports its width as the sheet maximum, so every
            // row arrives with a long tail of empty cells.
            $sheet->setCellValue("IV{$line}", null);
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'att').'.'.$extension;
    $writer = IOFactory::createWriter($spreadsheet, $extension === 'xls' ? 'Xls' : 'Xlsx');
    $writer->save($path);

    return $path;
}

it('imports the legacy .xls the device actually exports, without a hand conversion', function () {
    attEmployee('Karim', '13');

    $path = attSpreadsheet('xls', [
        ['no' => '13', 'at' => '2026-07-25 08:12:15'],
        ['no' => '13', 'at' => '2026-07-25 18:27:32'],
    ], padColumns: true);

    $batch = app(ImportAttendancePunches::class)->handleFile($path, 'BDGAllInOutData.xls', PunchSource::Biometric);

    expect($batch->imported_count)->toBe(2)
        ->and($batch->failed_count)->toBe(0)
        ->and($batch->total_rows)->toBe(2);

    expect(AttendancePunch::orderBy('punched_at')->pluck('punched_at')->map->format('Y-m-d H:i:s')->all())
        ->toBe(['2026-07-25 08:12:15', '2026-07-25 18:27:32']);
});

it('imports an .xlsx export just as well', function () {
    attEmployee('Karim', '13');

    $path = attSpreadsheet('xlsx', [['no' => '13', 'at' => '2026-07-25 08:12:15']]);

    $batch = app(ImportAttendancePunches::class)->handleFile($path, 'BDGAllInOutData.xlsx', PunchSource::Biometric);

    expect($batch->imported_count)->toBe(1)->and($batch->failed_count)->toBe(0);
});

/**
 * ⚠ The regression that matters most. A date CELL carries a number, so the
 * US-vs-day-first question the CSV path agonises over cannot arise — but only
 * if the reader converts it from the serial rather than letting a formatted
 * string reach the parser. Read wrongly, 7/8 becomes 8 July instead of 7 August
 * and nothing looks broken.
 */
it('reads a spreadsheet date cell as the date it is, not as a day-first string', function () {
    attEmployee('Karim', '13');

    // The 8th month, 7th day. Day-first reading gives 7 August.
    $path = attSpreadsheet('xlsx', [['no' => '13', 'at' => '2026-08-07 09:30:00']]);

    app(ImportAttendancePunches::class)->handleFile($path, 'dates.xlsx', PunchSource::Biometric);

    expect(AttendancePunch::sole()->punched_at->format('Y-m-d H:i:s'))->toBe('2026-08-07 09:30:00');
});

it('resolves a formula cell rather than handing on its source text', function () {
    attEmployee('Karim', '13');

    $path = attSpreadsheet('xlsx', [['no' => '13', 'at' => '2026-07-25 08:12:15', 'id' => '000020']]);

    $rows = app(AttendanceFileReader::class)->read($path, 'formula.xlsx');

    // CardNo is `="bdg-"&F2` in the file; the reader must hand on its value.
    expect($rows[1][7])->toBe('bdg-000020');
});

it('trims the empty trailing columns a legacy .xls pads every row with', function () {
    $path = attSpreadsheet('xls', [['no' => '13', 'at' => '2026-07-25 08:12:15']], padColumns: true);

    $rows = app(AttendanceFileReader::class)->read($path, 'wide.xls');

    expect($rows[0])->toHaveCount(8)
        ->and($rows[1])->toHaveCount(8);
});

it('reports an unmapped enroll id from a spreadsheet the same way it does from a CSV', function () {
    attEmployee('Karim', '13');

    $path = attSpreadsheet('xlsx', [
        ['no' => '13', 'at' => '2026-07-25 08:12:15'],
        ['no' => '99', 'at' => '2026-07-25 08:14:00'],
    ]);

    $batch = app(ImportAttendancePunches::class)->handleFile($path, 'mixed.xlsx', PunchSource::Biometric);

    expect($batch->imported_count)->toBe(1)
        ->and($batch->failed_count)->toBe(1)
        ->and($batch->report[0]['enroll_id'])->toBe('99');
});

it('accepts a spreadsheet upload through the import screen', function () {
    attEmployee('Karim', '13');

    $path = attSpreadsheet('xlsx', [['no' => '13', 'at' => '2026-07-25 08:12:15']]);

    $this->actingAs($this->owner)
        ->post(route('hrm.attendance.import.store'), [
            'file' => new UploadedFile($path, 'BDGAllInOutData.xlsx', null, null, true),
            'source' => PunchSource::Biometric->value,
        ])
        ->assertRedirect();

    expect(AttendancePunch::count())->toBe(1);
});

it('refuses a file type that is not an attendance export', function () {
    $path = tempnam(sys_get_temp_dir(), 'att').'.pdf';
    file_put_contents($path, '%PDF-1.4 not a spreadsheet');

    $this->actingAs($this->owner)
        ->post(route('hrm.attendance.import.store'), [
            'file' => new UploadedFile($path, 'notes.pdf', null, null, true),
            'source' => PunchSource::Biometric->value,
        ])
        ->assertSessionHasErrors('file');

    expect(AttendancePunch::count())->toBe(0);
});
