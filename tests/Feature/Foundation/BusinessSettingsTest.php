<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Biz Co', 'slug' => 'biz-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Biz', 'slug' => 'other-biz']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'foundation.organization.manage', 'guard_name' => 'web']);
    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
        Role::where('business_id', $biz->id)->where('name', 'Owner')->first()->givePermissionTo('foundation.organization.manage');
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

function bizOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function bizPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Updated Co',
        'legal_name' => 'Updated Co Ltd',
        'address' => 'Dhaka, Bangladesh',
        'phone' => '01700000000',
        'email' => 'hr@updated.test',
        'currency' => 'BDT',
        'timezone' => 'Asia/Dhaka',
        'fiscal_year_start_month' => 7,
        'payroll_cycle_start_day' => 24,
    ], $overrides);
}

it('lets an Owner update the business profile', function () {
    bizOwner()->patch(route('business.update'), bizPayload(['legal_name' => 'LaLaDia Foods Ltd']))->assertRedirect();

    $this->business->refresh();
    expect($this->business->legal_name)->toBe('LaLaDia Foods Ltd');
    expect($this->business->currency)->toBe('BDT');
    expect($this->business->timezone)->toBe('Asia/Dhaka');
    expect($this->business->fiscal_year_start_month)->toBe(7);
});

it('edits only the current business', function () {
    bizOwner()->patch(route('business.update'), bizPayload(['name' => 'Only A']))->assertRedirect();

    expect($this->business->fresh()->name)->toBe('Only A');
    expect($this->otherBusiness->fresh()->name)->toBe('Other Biz');
});

it('rejects an unsupported currency', function () {
    bizOwner()->patch(route('business.update'), bizPayload(['currency' => 'XYZ']))->assertSessionHasErrors('currency');
});

it('rejects an invalid timezone', function () {
    bizOwner()->patch(route('business.update'), bizPayload(['timezone' => 'Mars/Phobos']))->assertSessionHasErrors('timezone');
});

it('rejects an out-of-range fiscal start month', function () {
    bizOwner()->patch(route('business.update'), bizPayload(['fiscal_year_start_month' => 13]))->assertSessionHasErrors('fiscal_year_start_month');
});

it('blocks a non-admin from business settings', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('business.edit'))->assertForbidden();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->patch(route('business.update'), bizPayload())->assertForbidden();
});
