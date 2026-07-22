<?php

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ChangeRequest;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\database\seeders\HrmChangeRequestWorkflowSeeder;
use App\Modules\Hrm\Models\Employee;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'CR Emp Co', 'slug' => 'cr-emp-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.employees.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    // HR + Finance approver.
    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    // The employee's own login (no approve permissions).
    $this->selfUser = User::factory()->create();
    $this->selfUser->businesses()->attach($this->business->id);
    $this->selfUser->assignRole('Staff');

    $this->employee = app(CreateEmployee::class)->handle(
        ['first_name' => 'Karim', 'last_name' => 'Ahmed', 'employment_type' => 'permanent'],
        [],
    );
    $this->employee->update(['user_id' => $this->selfUser->id, 'personal_email' => 'old@example.com', 'bank_name' => 'Old Bank']);

    // Seed the employee change-request workflows (also grants Finance to Owner).
    app(HrmChangeRequestWorkflowSeeder::class)->run();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
});

function essUser()
{
    return test()->actingAs(test()->selfUser)->withSession(['current_business_id' => test()->business->id]);
}

function hrUser()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

it('lets an employee submit a contact-detail change request that stays pending and unapplied', function () {
    essUser()->post(route('hrm.my-profile.change-requests.store'), [
        'group' => 'personal',
        'personal_email' => 'new@example.com',
    ])->assertRedirect()->assertSessionHas('success');

    $cr = ChangeRequest::sole();
    expect($cr->workflow_key)->toBe('employee_personal_change');
    expect($cr->proposed_changes)->toBe(['personal_email' => 'new@example.com']);
    // The subject-described summary must render (regression: a "changes" column
    // collided with Eloquent's internal $changes and read empty inside the model).
    expect($cr->describedChanges())->toBe(['Personal email' => 'new@example.com']);
    expect($cr->summaryLine())->toContain('Personal email');
    expect($cr->approvalRequest->status)->toBe(ApprovalStatus::Pending);
    expect($this->employee->fresh()->personal_email)->toBe('old@example.com'); // not applied yet
});

it('applies the change through UpdateEmployee when HR approves a single-stage request', function () {
    essUser()->post(route('hrm.my-profile.change-requests.store'), ['group' => 'personal', 'personal_email' => 'new@example.com'])->assertRedirect();
    $cr = ChangeRequest::sole();

    hrUser()->post(route('change-requests.approve', $cr))->assertRedirect();

    expect($cr->approvalRequest->fresh()->status)->toBe(ApprovalStatus::Approved);
    expect($this->employee->fresh()->personal_email)->toBe('new@example.com');
});

it('routes a bank change through HR then Finance before applying', function () {
    essUser()->post(route('hrm.my-profile.change-requests.store'), ['group' => 'bank', 'bank_name' => 'City Bank'])->assertRedirect();
    $cr = ChangeRequest::sole();
    expect($cr->workflow_key)->toBe('employee_bank_change');
    expect($cr->approvalRequest->totalStages())->toBe(2);

    // Stage 1 (HR): advances, not applied.
    hrUser()->post(route('change-requests.approve', $cr))->assertRedirect();
    expect($cr->approvalRequest->fresh()->status)->toBe(ApprovalStatus::Pending);
    expect($cr->approvalRequest->fresh()->current_stage)->toBe(2);
    expect($this->employee->fresh()->bank_name)->toBe('Old Bank');

    // Stage 2 (Finance — the same owner also holds the seeded Finance role): applies.
    hrUser()->post(route('change-requests.approve', $cr))->assertRedirect();
    expect($cr->approvalRequest->fresh()->status)->toBe(ApprovalStatus::Approved);
    expect($this->employee->fresh()->bank_name)->toBe('City Bank');
});

it('does not create a request when nothing actually changed', function () {
    essUser()->post(route('hrm.my-profile.change-requests.store'), [
        'group' => 'personal',
        'personal_email' => 'old@example.com', // unchanged
    ])->assertRedirect()->assertSessionHas('error');

    expect(ChangeRequest::count())->toBe(0);
});

it('ignores fields that are not part of the requested group', function () {
    // national_id is not in the personal group; it must not be requestable here.
    essUser()->post(route('hrm.my-profile.change-requests.store'), [
        'group' => 'personal',
        'national_id' => '999',
    ])->assertRedirect()->assertSessionHas('error'); // nothing valid changed

    expect(ChangeRequest::count())->toBe(0);
    expect($this->employee->fresh()->national_id)->toBeNull();
});

it('redirects a user with no linked employee', function () {
    $stranger = User::factory()->create();
    $stranger->businesses()->attach($this->business->id);
    $stranger->assignRole('Staff');

    $this->actingAs($stranger)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.my-profile.change-requests.store'), ['group' => 'personal', 'phone' => '01711'])
        ->assertRedirect(route('dashboard'));

    expect(ChangeRequest::count())->toBe(0);
});

it('shows the requestable groups and the employee own requests on My Profile', function () {
    essUser()->post(route('hrm.my-profile.change-requests.store'), ['group' => 'personal', 'phone' => '01711000000'])->assertRedirect();

    essUser()->get(route('hrm.my-profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/my-profile/show')
            ->has('requestable', 2)
            ->has('change_requests', 1)
            ->where('change_requests.0.status', 'pending'));
});
