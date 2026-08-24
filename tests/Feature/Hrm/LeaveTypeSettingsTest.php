<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\Models\LeaveType;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The leave-type rules screen (SmartHR gap G19). The rules were HR-editable
 * data from Corrections C6b/C6c; these pin the screen that finally edits them,
 * and the three refusals that keep the engine's destinations attached.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Type Co', 'slug' => 'type-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.leave.manage', 'guard_name' => 'web']);
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo('hrm.leave.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');

    (new LeaveTypeSeeder)->run();
});

function typeHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

function typeStaff()
{
    return test()->actingAs(test()->staffUser)->withSession(['current_business_id' => test()->business->id]);
}

it('lists inactive types in the settings prop while the request form sees only active ones', function () {
    LeaveType::where('code', 'special')->first()->update(['is_active' => false]);

    typeHr()->get(route('hrm.leave.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('hrm/leave/index')
            // 8 seeded types; the request form must not offer the inactive one.
            ->has('type_settings', 8)
            ->has('leave_types', 7));
});

it('creates a leave type from the screen', function () {
    typeHr()->post(route('hrm.leave.types.store'), [
        'name' => 'Study Leave',
        'code' => 'study',
        'annual_quota' => 5,
        'is_paid' => true,
        'deducts_balance' => true,
        'allows_half_day' => false,
        'can_carry_forward' => true,
        'carry_forward_cap' => 10,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $type = LeaveType::where('code', 'study')->first();
    expect($type)->not->toBeNull()
        ->and($type->business_id)->toBe($this->business->id)
        ->and((float) $type->annual_quota)->toBe(5.0)
        ->and($type->allows_half_day)->toBeFalse()
        ->and((float) $type->carry_forward_cap)->toBe(10.0)
        ->and($type->is_active)->toBeTrue();
});

it('never accepts encashment from the screen - switched off by policy on 23 Aug 2026', function () {
    typeHr()->post(route('hrm.leave.types.store'), [
        'name' => 'Sneaky Leave',
        'code' => 'sneaky',
        'annual_quota' => 5,
        'is_paid' => true,
        'deducts_balance' => true,
        'allows_half_day' => true,
        'can_carry_forward' => false,
        'can_encash' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(LeaveType::where('code', 'sneaky')->first()->can_encash)->toBeFalse();

    // Nor on update: earned stays encashable in data, casual stays not.
    $casual = LeaveType::where('code', 'casual')->first();
    typeHr()->put(route('hrm.leave.types.update', $casual), [
        'name' => $casual->name,
        'annual_quota' => 10,
        'is_paid' => true,
        'deducts_balance' => true,
        'allows_half_day' => true,
        'can_carry_forward' => false,
        'can_encash' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($casual->fresh()->can_encash)->toBeFalse();
});

it('rejects a duplicate code in the same business but allows it in another', function () {
    typeHr()->post(route('hrm.leave.types.store'), [
        'name' => 'Casual Again',
        'code' => 'casual',
        'annual_quota' => 0,
        'is_paid' => true,
        'deducts_balance' => false,
        'allows_half_day' => true,
        'can_carry_forward' => false,
    ])->assertSessionHasErrors('code');

    // A type with the same code in ANOTHER business must not block this one.
    $other = Business::create(['name' => 'Elsewhere', 'slug' => 'elsewhere-types']);
    LeaveType::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'code' => 'festival', 'name' => 'Festival Leave',
        'annual_quota' => 3, 'is_paid' => true, 'deducts_balance' => true,
        'allows_half_day' => true, 'can_carry_forward' => false, 'can_encash' => false,
        'is_active' => true,
    ]);

    typeHr()->post(route('hrm.leave.types.store'), [
        'name' => 'Festival Leave',
        'code' => 'festival',
        'annual_quota' => 3,
        'is_paid' => true,
        'deducts_balance' => true,
        'allows_half_day' => true,
        'can_carry_forward' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(LeaveType::where('code', 'festival')->count())->toBe(1);
});

it('updates the rules but never the code - the engine addresses types by code', function () {
    $sick = LeaveType::where('code', 'sick')->first();

    typeHr()->put(route('hrm.leave.types.update', $sick), [
        'name' => 'Medical Leave',
        'code' => 'renamed_sick',
        'annual_quota' => 12,
        'is_paid' => true,
        'deducts_balance' => true,
        'allows_half_day' => false,
        'can_carry_forward' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $sick->refresh();
    expect($sick->name)->toBe('Medical Leave')
        ->and((float) $sick->annual_quota)->toBe(12.0)
        ->and($sick->allows_half_day)->toBeFalse()
        ->and($sick->code)->toBe('sick');
});

it('nulls the cap when carry-forward is off, so screen and engine agree', function () {
    $earned = LeaveType::where('code', 'earned')->first();

    typeHr()->put(route('hrm.leave.types.update', $earned), [
        'name' => $earned->name,
        'annual_quota' => 20,
        'is_paid' => true,
        'deducts_balance' => true,
        'allows_half_day' => true,
        'can_carry_forward' => false,
        'carry_forward_cap' => 40,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($earned->fresh()->carry_forward_cap)->toBeNull();
});

it('deactivates and reactivates an ordinary type', function () {
    $special = LeaveType::where('code', 'special')->first();

    typeHr()->patch(route('hrm.leave.types.toggle-active', $special))->assertRedirect();
    expect($special->fresh()->is_active)->toBeFalse();

    typeHr()->patch(route('hrm.leave.types.toggle-active', $special))->assertRedirect();
    expect($special->fresh()->is_active)->toBeTrue();
});

it('refuses to deactivate the engine destinations', function () {
    // `unpaid` is LEV-07's fallback, `compensatory` is ADJ-04's credit target.
    foreach (['unpaid', 'compensatory'] as $code) {
        $type = LeaveType::where('code', $code)->first();

        typeHr()->patch(route('hrm.leave.types.toggle-active', $type))
            ->assertRedirect()
            ->assertSessionHas('error');

        expect($type->fresh()->is_active)->toBeTrue();
    }
});

it('blocks a user without the leave permission from every type action', function () {
    $casual = LeaveType::where('code', 'casual')->first();
    $payload = [
        'name' => 'X', 'code' => 'x', 'annual_quota' => 0, 'is_paid' => true,
        'deducts_balance' => false, 'allows_half_day' => true, 'can_carry_forward' => false,
    ];

    typeStaff()->post(route('hrm.leave.types.store'), $payload)->assertForbidden();
    typeStaff()->put(route('hrm.leave.types.update', $casual), $payload)->assertForbidden();
    typeStaff()->patch(route('hrm.leave.types.toggle-active', $casual))->assertForbidden();
});

it('writes the rule change to the audit trail', function () {
    $casual = LeaveType::where('code', 'casual')->first();

    typeHr()->put(route('hrm.leave.types.update', $casual), [
        'name' => $casual->name,
        'annual_quota' => 15,
        'is_paid' => true,
        'deducts_balance' => true,
        'allows_half_day' => true,
        'can_carry_forward' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $entry = Activity::where('subject_type', LeaveType::class)
        ->where('subject_id', $casual->id)
        ->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['attributes']['annual_quota'])->toBe('15.00');
});
