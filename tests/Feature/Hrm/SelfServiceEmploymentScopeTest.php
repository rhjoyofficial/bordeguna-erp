<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * HRM-005 (audit 14 Oct 2026). Self-service resolved "who am I" but never "am
 * I employed here": a person linked to an employee of business A who could
 * switch into business B filed expense claims, leave, movements, staff
 * purchases and clock punches in B. B's payroll (assignment-based) never pays
 * or recovers them, and B's leave ledger was charged for A's employee. A
 * self-service write now needs an assignment in the current business, and the
 * Workspace links show only there.
 */

beforeEach(function () {
    $this->home = Business::create(['name' => 'Home Co', 'slug' => 'home-co']);
    $this->sister = Business::create(['name' => 'Sister Co', 'slug' => 'sister-co']);

    foreach ([$this->home, $this->sister] as $business) {
        Role::firstOrCreate(['name' => 'Staff', 'business_id' => $business->id, 'guard_name' => 'web']);
    }

    $this->user = User::factory()->create();
    $this->user->businesses()->attach([$this->home->id, $this->sister->id]);
    foreach ([$this->home, $this->sister] as $business) {
        app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);
        $this->user->assignRole('Staff');
    }

    // Employed in Home only.
    app(BusinessContext::class)->set($this->home->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->home->id);
    $office = Office::create(['business_id' => $this->home->id, 'name' => 'Head Office']);
    $this->employee = app(CreateEmployee::class)->handle(
        ['first_name' => 'Karim', 'last_name' => 'Ahmed', 'employment_type' => 'permanent', 'joining_date' => '2025-01-01'],
        ['office_id' => $office->id],
    );
    $this->employee->update(['user_id' => $this->user->id, 'may_clock_in_remotely' => true]);

    // Working in the sister business from here on.
    app(BusinessContext::class)->set($this->sister->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->sister->id);
});

function inSister()
{
    return test()->actingAs(test()->user)->withSession(['current_business_id' => test()->sister->id]);
}

it('refuses every self-service write in a business where the person is not employed', function () {
    $today = now()->toDateString();

    inSister()->post(route('hrm.my-expenses.store'), ['spent_on' => $today, 'amount' => 120, 'description' => 'Courier'])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'not employed in Sister Co'));

    inSister()->post(route('hrm.my-leave.requests.store'), [
        'start_date' => $today, 'end_date' => $today, 'day_portion' => 'full', 'reason' => 'Family',
    ])->assertSessionHas('error');

    inSister()->post(route('hrm.my-leave.movements.store'), ['reason' => 'Bank visit'])->assertSessionHas('error');
    inSister()->post(route('hrm.staff-purchase.apply'), ['payment_mode' => 'cash', 'lines' => [['product_id' => 1]]])->assertSessionHas('error');
    inSister()->post(route('hrm.clock.store'), ['direction' => 'in'])->assertSessionHas('error');
    inSister()->post(route('hrm.clock.corrections.store'), [
        'attendance_date' => $today, 'type' => 'add_punch', 'corrected_at' => "{$today} 18:00:00", 'reason' => 'Forgot',
    ])->assertSessionHas('error');

    expect(ExpenseClaim::withoutGlobalScopes()->count())->toBe(0)
        ->and(LeaveRequest::withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('movements')->count())->toBe(0)
        ->and(DB::table('staff_purchase_requests')->count())->toBe(0)
        ->and(AttendancePunch::withoutGlobalScopes()->count())->toBe(0)
        ->and(AttendanceCorrection::withoutGlobalScopes()->count())->toBe(0);
});

it('shows the Workspace self-service links only in a business where the person is employed', function () {
    inSister()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('hasEmployeeProfile', false));

    app(BusinessContext::class)->set($this->home->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->home->id);

    $this->actingAs($this->user)->withSession(['current_business_id' => $this->home->id])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('hasEmployeeProfile', true));
});
