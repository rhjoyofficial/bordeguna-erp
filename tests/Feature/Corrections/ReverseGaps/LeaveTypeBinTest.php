<?php

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\Recycle\RecycleBin;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a leave type never used is
 * deleted to the Recycle bin with a reason, and the Owner restores it; a used
 * one is switched off with a reason and says what uses it; the system
 * destinations (unpaid, compensatory) are neither.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Type Co', 'slug' => 'type-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.leave.manage', 'guard_name' => 'web']);
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.leave.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    (new LeaveTypeSeeder)->run();

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    $this->type = fn (string $code) => LeaveType::query()->where('code', $code)->sole();
});

it('deletes a never used type to the Recycle bin with a reason, and the Owner restores it', function () {
    $special = ($this->type)('special');
    expect($special->whyNotRemovable())->toBeNull();

    ($this->as)($this->owner)->post(route('hrm.leave.types.bin', $special), ['reason_text' => 'x'])->assertSessionHasErrors('reason_text');
    ($this->as)($this->owner)->post(route('hrm.leave.types.bin', $special), ['reason_text' => 'Made by mistake'])->assertSessionHasNoErrors();

    expect(LeaveType::query()->where('code', 'special')->exists())->toBeFalse()
        ->and(LeaveType::onlyTrashed()->where('code', 'special')->sole()->delete_reason)->toBe('Made by mistake');

    // Making it again is refused in words while it waits in the bin.
    ($this->as)($this->owner)->post(route('hrm.leave.types.store'), [
        'name' => 'Special again', 'code' => 'special', 'annual_quota' => 1, 'is_paid' => true, 'deducts_balance' => true,
        'allows_half_day' => false, 'can_carry_forward' => false, 'carry_forward_cap' => null,
    ])->assertSessionHasErrors(['code' => 'A leave type with this code is in the Recycle bin. Ask the Owner to restore it.']);

    app(RecycleBin::class)->restore('hrm.leave_type', $special->id, $this->owner, new ActionReason(null, 'Needed after all'));
    expect(($this->type)('special')->trashed())->toBeFalse();
});

it('keeps a used type: Delete is refused with what uses it, Switch off asks a reason', function () {
    $casual = ($this->type)('casual');
    $employee = Employee::create(['employee_code' => 'BDG-000901', 'first_name' => 'Rahim', 'last_name' => 'Uddin', 'employment_type' => 'permanent', 'status' => 'active']);
    LeaveLedgerEntry::create(['business_id' => $this->business->id, 'employee_id' => $employee->id, 'leave_type_id' => $casual->id, 'year' => 2026, 'entry_type' => 'grant', 'days' => 10, 'occurred_on' => '2026-01-01']);

    expect($casual->whyNotRemovable())->toBe("{$casual->name} is used (1 leave balance entry), so it stays. Switch it off instead.");

    ($this->as)($this->owner)->post(route('hrm.leave.types.bin', $casual), ['reason_text' => 'Not needed now'])->assertSessionHasErrors('reason_text');
    expect(($this->type)('casual')->is_active)->toBeTrue();

    ($this->as)($this->owner)->post(route('hrm.leave.types.switch-off', $casual), [])->assertSessionHasErrors('reason_text');
    ($this->as)($this->owner)->post(route('hrm.leave.types.switch-off', $casual), ['reason_text' => 'Policy changed in October'])->assertSessionHasNoErrors();
    expect(($this->type)('casual')->is_active)->toBeFalse();
});

it('never deletes or switches off a system destination, and staff may do neither', function () {
    $unpaid = ($this->type)('unpaid');

    ($this->as)($this->owner)->post(route('hrm.leave.types.bin', $unpaid), ['reason_text' => 'Trying to remove'])->assertSessionHasErrors('reason_text');
    ($this->as)($this->owner)->post(route('hrm.leave.types.switch-off', $unpaid), ['reason_text' => 'Trying to stop'])->assertSessionHasErrors('reason_text');
    ($this->as)($this->staff)->post(route('hrm.leave.types.bin', ($this->type)('special')), ['reason_text' => 'Not mine to do'])->assertForbidden();

    expect(LeaveType::query()->where('code', 'unpaid')->exists())->toBeTrue();
});

it('tells the screen which types may be deleted', function () {
    ($this->as)($this->owner)->get(route('hrm.leave.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('hrm/leave/index')
            ->where('type_settings', fn ($types) => collect($types)->firstWhere('code', 'special')['used'] === null)
            ->has('reasons.deletion')
            ->has('reasons.deactivation'));
});
