<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Shift;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Shift Co', 'slug' => 'shift-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Shift', 'slug' => 'other-shift']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.scheduling.manage', 'guard_name' => 'web']);
    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
        Role::where('business_id', $biz->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.scheduling.manage');
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

function schOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function shiftPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'General',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'grace_minutes' => 15,
        'break_minutes' => 60,
        'minimum_payable_hours' => 8,
        'late_threshold_minutes' => 30,
        'early_leave_threshold_minutes' => 30,
        'is_overnight' => false,
        'cross_midnight_window_minutes' => null,
    ], $overrides);
}

it('creates a shift scoped to the current business', function () {
    schOwner()->post(route('hrm.shifts.store'), shiftPayload())->assertRedirect();

    $shift = Shift::sole();
    expect($shift->name)->toBe('General');
    expect($shift->business_id)->toBe($this->business->id);
    expect($shift->grace_minutes)->toBe(15);
    expect($shift->is_overnight)->toBeFalse();
});

it('rejects a same-day shift that ends before it starts, but allows an overnight one', function () {
    schOwner()->post(route('hrm.shifts.store'), shiftPayload(['name' => 'Bad', 'start_time' => '18:00', 'end_time' => '09:00', 'is_overnight' => false]))
        ->assertSessionHasErrors('end_time');

    schOwner()->post(route('hrm.shifts.store'), shiftPayload([
        'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'is_overnight' => true, 'cross_midnight_window_minutes' => 360,
    ]))->assertRedirect();

    expect(Shift::where('name', 'Night')->first()->is_overnight)->toBeTrue();
});

it('enforces a unique shift name within a business', function () {
    schOwner()->post(route('hrm.shifts.store'), shiftPayload())->assertRedirect();
    schOwner()->post(route('hrm.shifts.store'), shiftPayload())->assertSessionHasErrors('name');
});

it('validates required timing fields', function () {
    schOwner()->post(route('hrm.shifts.store'), shiftPayload(['grace_minutes' => -5]))->assertSessionHasErrors('grace_minutes');
    schOwner()->post(route('hrm.shifts.store'), shiftPayload(['start_time' => 'nope']))->assertSessionHasErrors('start_time');
});

it('updates, toggles, and deletes a shift', function () {
    schOwner()->post(route('hrm.shifts.store'), shiftPayload())->assertRedirect();
    $shift = Shift::sole();

    schOwner()->put(route('hrm.shifts.update', $shift), shiftPayload(['break_minutes' => 30]))->assertRedirect();
    expect($shift->fresh()->break_minutes)->toBe(30);

    schOwner()->patch(route('hrm.shifts.toggle', $shift))->assertRedirect();
    expect($shift->fresh()->is_active)->toBeFalse();

    schOwner()->delete(route('hrm.shifts.destroy', $shift))->assertRedirect();
    expect(Shift::find($shift->id))->toBeNull();
});

it('blocks a user without hrm.scheduling.manage', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.shifts.index'))->assertForbidden();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.shifts.store'), shiftPayload())->assertForbidden();
});

it('does not touch a shift from another business', function () {
    $otherShift = Shift::create(shiftPayload(['business_id' => $this->otherBusiness->id, 'name' => 'Theirs']));

    schOwner()->put(route('hrm.shifts.update', $otherShift), shiftPayload(['name' => 'Hijacked']))->assertNotFound();
    expect($otherShift->fresh()->name)->toBe('Theirs');
});

it('renders the shifts page for a scheduler', function () {
    schOwner()->post(route('hrm.shifts.store'), shiftPayload())->assertRedirect();

    schOwner()->get(route('hrm.shifts.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('hrm/shifts/index')
            ->has('shifts', 1)
            ->where('shifts.0.name', 'General'));
});
