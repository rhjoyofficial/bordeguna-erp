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
use App\Modules\Hrm\Support\Geofence;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
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
            ->where('punches.0.employee', $employee->fullName())
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
