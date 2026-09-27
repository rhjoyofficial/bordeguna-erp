<?php

use App\Models\User;
use App\Modules\Foundation\Models\ActivityLogEntry;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Actions\SeparateEmployee;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\SeparationType;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Life Co', 'slug' => 'life-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.employees.manage', 'foundation.users.manage'] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo(['hrm.employees.manage', 'foundation.users.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

function lifeOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function makeEmployee(array $data = []): Employee
{
    return app(CreateEmployee::class)->handle(array_merge([
        'first_name' => 'Test',
        'last_name' => 'Person',
        'employment_type' => 'permanent',
        'joining_date' => '2025-01-01',
    ], $data));
}

it('activates a draft employee', function () {
    $employee = makeEmployee();
    expect($employee->status)->toBe(EmployeeStatus::Draft);

    lifeOwner()->patch(route('hrm.employees.status', $employee), ['status' => 'active'])->assertRedirect();

    expect($employee->fresh()->status)->toBe(EmployeeStatus::Active);
});

it('moves an active employee on leave and back', function () {
    $employee = makeEmployee();
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->patch(route('hrm.employees.status', $employee), ['status' => 'on_leave'])->assertRedirect();
    expect($employee->fresh()->status)->toBe(EmployeeStatus::OnLeave);

    lifeOwner()->patch(route('hrm.employees.status', $employee), ['status' => 'active'])->assertRedirect();
    expect($employee->fresh()->status)->toBe(EmployeeStatus::Active);
});

it('rejects an invalid status transition', function () {
    $employee = makeEmployee(); // Draft — may only go Active

    lifeOwner()->patch(route('hrm.employees.status', $employee), ['status' => 'on_leave'])->assertSessionHasErrors('status');

    expect($employee->fresh()->status)->toBe(EmployeeStatus::Draft);
});

it('separates an employee, ends assignments, and disables their login', function () {
    $user = User::factory()->create(['is_active' => true]);
    $employee = makeEmployee(['user_id' => $user->id]);
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->post(route('hrm.employees.separate', $employee), [
        'separation_type' => 'resignation',
        'separation_date' => '2026-03-31',
        'separation_reason' => 'Moving on',
    ])->assertRedirect();

    $employee->refresh();
    expect($employee->status)->toBe(EmployeeStatus::Separated);
    expect($employee->separation_type)->toBe(SeparationType::Resignation);
    expect($employee->separation_date->toDateString())->toBe('2026-03-31');
    expect($user->fresh()->is_active)->toBeFalse();
    expect($employee->assignments()->first()->effective_to->toDateString())->toBe('2026-03-31');
});

it('drops a separated employee from the active list and blocks re-separation', function () {
    $employee = makeEmployee();
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->post(route('hrm.employees.separate', $employee), ['separation_type' => 'termination', 'separation_date' => '2026-02-28'])->assertRedirect();

    app(BusinessContext::class)->set($this->business->id);
    $activeCount = Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))->count();
    expect($activeCount)->toBe(0);

    lifeOwner()->post(route('hrm.employees.separate', $employee), ['separation_type' => 'resignation', 'separation_date' => '2026-03-01'])
        ->assertSessionHas('error');
});

it('records status changes in the activity log', function () {
    $employee = makeEmployee();

    lifeOwner()->patch(route('hrm.employees.status', $employee), ['status' => 'active'])->assertRedirect();

    app(BusinessContext::class)->set($this->business->id);
    expect(
        ActivityLogEntry::where('subject_type', Employee::class)->where('subject_id', $employee->id)->where('event', 'updated')->exists()
    )->toBeTrue();
});

it('blocks a non-admin from changing employee status or separating', function () {
    $employee = makeEmployee();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->patch(route('hrm.employees.status', $employee), ['status' => 'active'])->assertForbidden();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.employees.separate', $employee), ['separation_type' => 'resignation', 'separation_date' => '2026-01-01'])->assertForbidden();
});

it('keeps a future-dated resignation login live until the day after the last working day', function () {
    Carbon::setTestNow('2026-03-01 09:00:00');
    $user = User::factory()->create(['is_active' => true]);
    $employee = makeEmployee(['user_id' => $user->id]);
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->post(route('hrm.employees.separate', $employee), [
        'separation_type' => 'resignation',
        'separation_date' => '2026-03-31',
        'separation_reason' => 'One month notice',
    ])->assertRedirect();

    // Recorded a month early: access stays until the last working day (Joy, 27 Sep 2026).
    expect($user->fresh()->is_active)->toBeTrue();

    // Still live on the last working day itself.
    Carbon::setTestNow('2026-03-31 10:00:00');
    Artisan::call('hrm:disable-due-separations');
    expect($user->fresh()->is_active)->toBeTrue();

    // The morning after, the daily job ends access.
    Carbon::setTestNow('2026-04-01 00:30:00');
    Artisan::call('hrm:disable-due-separations');
    expect($user->fresh()->is_active)->toBeFalse();

    Carbon::setTestNow();
});

it('ends access at once when the resignation is dated before today', function () {
    Carbon::setTestNow('2026-04-15 09:00:00');
    $user = User::factory()->create(['is_active' => true]);
    $employee = makeEmployee(['user_id' => $user->id]);
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->post(route('hrm.employees.separate', $employee), [
        'separation_type' => 'resignation',
        'separation_date' => '2026-04-10',
    ])->assertRedirect();

    expect($user->fresh()->is_active)->toBeFalse();
    Carbon::setTestNow();
});

it('fully revokes access on separation: deactivates, drops live sessions, and revokes API tokens', function () {
    Carbon::setTestNow('2026-05-10 09:00:00');
    $user = User::factory()->create(['is_active' => true]);
    $employee = makeEmployee(['user_id' => $user->id]);
    $employee->update(['status' => EmployeeStatus::Active]);

    // A live browser session and an API token, as a working account would have.
    DB::table('sessions')->insert([
        'id' => 'sess-'.$user->id, 'user_id' => $user->id,
        'ip_address' => '127.0.0.1', 'user_agent' => 'test',
        'payload' => 'x', 'last_activity' => time(),
    ]);
    $user->createToken('mobile');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->tokens()->count())->toBe(1);

    lifeOwner()->post(route('hrm.employees.separate', $employee), [
        'separation_type' => 'resignation',
        'separation_date' => '2026-05-08',
    ])->assertRedirect();

    expect($user->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);

    Carbon::setTestNow();
});

it('revokes sessions and tokens when the daily job ends access for a future-dated leaver', function () {
    Carbon::setTestNow('2026-06-01 09:00:00');
    $user = User::factory()->create(['is_active' => true]);
    $employee = makeEmployee(['user_id' => $user->id]);
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->post(route('hrm.employees.separate', $employee), [
        'separation_type' => 'resignation',
        'separation_date' => '2026-06-30',
    ])->assertRedirect();

    // Still working: access, a session and a token all live until the last day.
    DB::table('sessions')->insert([
        'id' => 'sess2-'.$user->id, 'user_id' => $user->id,
        'ip_address' => '127.0.0.1', 'user_agent' => 'test',
        'payload' => 'x', 'last_activity' => time(),
    ]);
    $user->createToken('mobile');
    expect($user->fresh()->is_active)->toBeTrue();

    Carbon::setTestNow('2026-07-01 00:30:00');
    Artisan::call('hrm:disable-due-separations');

    expect($user->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);

    Carbon::setTestNow();
});

it('changes an employee login email from the UI and leaves the password alone', function () {
    $user = User::factory()->create(['email' => 'old@bdg.test', 'is_active' => true]);
    $employee = makeEmployee(['user_id' => $user->id]);
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->patch(route('hrm.employees.login-email', $employee), [
        'email' => 'bdg.coordinator@gmail.com',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($user->fresh()->email)->toBe('bdg.coordinator@gmail.com');
});

it('refuses a login email that another account already uses', function () {
    User::factory()->create(['email' => 'taken@bdg.test']);
    $user = User::factory()->create(['email' => 'mine@bdg.test']);
    $employee = makeEmployee(['user_id' => $user->id]);
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->patch(route('hrm.employees.login-email', $employee), ['email' => 'taken@bdg.test'])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->email)->toBe('mine@bdg.test');
});

it('keeps separated staff reachable under the former-employees scope', function () {
    $emp = makeEmployee(['first_name' => 'Gone', 'last_name' => 'Person']);
    $emp->update(['status' => EmployeeStatus::Active]);
    app(SeparateEmployee::class)
        ->handle($emp, SeparationType::Resignation, '2026-04-01', 'left');

    lifeOwner()->get(route('hrm.employees.index'))
        ->assertInertia(fn ($page) => $page->where('scope', 'current')->where('formerCount', 1)->where('employees', []));

    lifeOwner()->get(route('hrm.employees.index', ['scope' => 'former']))
        ->assertInertia(fn ($page) => $page->where('scope', 'former')->where('formerCount', 1)
            ->has('employees', 1)
            ->where('employees.0.employee_code', $emp->employee_code)
            ->where('employees.0.separation_date', '2026-04-01'));
});
