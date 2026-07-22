<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Enums\MfsProvider;
use App\Modules\Hrm\Models\Employee;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Upd Co', 'slug' => 'upd-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.employees.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Head Office']);

    $this->employee = app(CreateEmployee::class)->handle(
        ['first_name' => 'Karim', 'last_name' => 'Ahmed', 'employment_type' => 'permanent', 'joining_date' => '2025-01-01'],
        ['office_id' => $this->office->id],
    );
});

function updOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function fullPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Karim',
        'last_name' => 'Ahmed',
        'employment_type' => 'permanent',
        'personal_email' => 'karim@example.com',
        'phone' => '01711000000',
        'present_address' => '12 Road, Dhaka',
        'permanent_address' => 'Village, Comilla',
        'national_id' => '1990123456789',
        'birth_certificate_no' => 'BC-77',
        'date_of_birth' => '1990-05-10',
        'joining_date' => '2025-01-01',
        'confirmation_date' => '2025-07-01',
        'contract_end_date' => null,
        'emergency_contact_name' => 'Rahim',
        'emergency_contact_phone' => '01822000000',
        'emergency_contact_relationship' => 'Brother',
        'bank_account_name' => 'Karim Ahmed',
        'bank_account_number' => '00112233',
        'bank_name' => 'BRAC Bank',
        'bank_branch' => 'Gulshan',
        'bank_routing_number' => '060270000',
        'mfs_provider' => 'bkash',
        'mfs_account_number' => '01711000000',
    ], $overrides);
}

it('lets HR update the full EMP-04 field set', function () {
    updOwner()->patch(route('hrm.employees.update', $this->employee), fullPayload())
        ->assertRedirect(route('hrm.employees.show', $this->employee));

    $this->employee->refresh();
    expect($this->employee->present_address)->toBe('12 Road, Dhaka');
    expect($this->employee->emergency_contact_name)->toBe('Rahim');
    expect($this->employee->bank_name)->toBe('BRAC Bank');
    expect($this->employee->mfs_provider)->toBe(MfsProvider::Bkash);
    expect($this->employee->confirmation_date->toDateString())->toBe('2025-07-01');
});

it('records an audit trail for a changed field', function () {
    updOwner()->patch(route('hrm.employees.update', $this->employee), fullPayload(['bank_name' => 'City Bank']))->assertRedirect();

    $latest = Activity::where('subject_type', $this->employee->getMorphClass())
        ->where('subject_id', $this->employee->id)->latest('id')->first();

    expect($latest)->not->toBeNull();
    expect($latest->properties['attributes']['bank_name'])->toBe('City Bank');
});

it('never writes non-editable fields through the update path', function () {
    $originalCode = $this->employee->employee_code;

    updOwner()->patch(route('hrm.employees.update', $this->employee), fullPayload([
        'employee_code' => 'BDG-999999',
        'status' => 'separated',
        'user_id' => 999,
    ]))->assertRedirect();

    $this->employee->refresh();
    expect($this->employee->employee_code)->toBe($originalCode);
    expect($this->employee->status->value)->toBe('draft'); // new employees default to Draft; update never changes status
});

it('clears an optional field when sent as null', function () {
    updOwner()->patch(route('hrm.employees.update', $this->employee), fullPayload())->assertRedirect();
    updOwner()->patch(route('hrm.employees.update', $this->employee), fullPayload(['bank_name' => null]))->assertRedirect();

    expect($this->employee->refresh()->bank_name)->toBeNull();
});

it('validates email, mfs provider, and date of birth', function () {
    updOwner()->patch(route('hrm.employees.update', $this->employee), fullPayload(['personal_email' => 'not-an-email']))
        ->assertSessionHasErrors('personal_email');

    updOwner()->patch(route('hrm.employees.update', $this->employee), fullPayload(['mfs_provider' => 'paypal']))
        ->assertSessionHasErrors('mfs_provider');

    updOwner()->patch(route('hrm.employees.update', $this->employee), fullPayload(['date_of_birth' => now()->addDay()->toDateString()]))
        ->assertSessionHasErrors('date_of_birth');
});

it('shows HR the edit form pre-filled', function () {
    $this->employee->update(['phone' => '01700000000']);

    updOwner()->get(route('hrm.employees.edit', $this->employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/employees/edit')
            ->where('employee.first_name', 'Karim')
            ->where('employee.phone', '01700000000'));
});

it('blocks a non-admin from the edit form and the update', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.employees.edit', $this->employee))->assertForbidden();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->patch(route('hrm.employees.update', $this->employee), fullPayload())->assertForbidden();
});
