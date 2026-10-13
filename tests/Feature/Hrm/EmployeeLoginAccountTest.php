<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Notifications\SetPasswordNotification;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Login Co', 'slug' => 'login-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.employees.manage', 'foundation.users.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    foreach (['Owner', 'HR only', 'Self-service'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.employees.manage', 'foundation.users.manage']);

    // Deliberately half-privileged: may manage employees, may NOT create logins.
    Role::where('business_id', $this->business->id)->where('name', 'HR only')->first()
        ->givePermissionTo('hrm.employees.manage');

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000099',
        'first_name' => 'Munna',
        'last_name' => 'Islam',
        'employment_type' => EmploymentType::Permanent,
        'status' => 'active',
        'date_of_birth' => '2007-07-29',
        'joining_date' => '2026-01-01',
    ]);

    // An employee is only visible in this business through an assignment.
    $this->employee->assignments()->create([
        'is_primary' => true,
        'effective_from' => '2026-01-01',
    ]);
});

function asOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

it('creates a login, joins it to the employee and emails a password link', function () {
    Notification::fake();

    asOwner()->post(route('hrm.employees.login-account', $this->employee), ['email' => 'munna@example.com'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $user = User::where('email', 'munna@example.com')->sole();

    expect($this->employee->fresh()->user_id)->toBe($user->id)
        ->and($user->businesses()->where('businesses.id', $this->business->id)->exists())->toBeTrue()
        // The address HR was missing lands on the record, not only on the login.
        ->and($this->employee->fresh()->personal_email)->toBe('munna@example.com');

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    expect($user->fresh()->hasRole('Self-service'))->toBeTrue();

    Notification::assertSentTo($user, SetPasswordNotification::class);
    expect(DB::table('password_reset_tokens')->where('email', 'munna@example.com')->exists())->toBeTrue();
});

it('makes the new account reachable only through the emailed link', function () {
    Notification::fake();

    asOwner()->post(route('hrm.employees.login-account', $this->employee), ['email' => 'munna@example.com']);

    // Stop being the admin: the login form is a guest route.
    $this->post('/logout');

    // Whatever the account was created with, nobody was told it, and the
    // obvious guesses do not open it.
    foreach (['password', 'munna@example.com', 'BDG-000099'] as $guess) {
        $this->post('/login', ['email' => 'munna@example.com', 'password' => $guess])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }
});

it('lets the employee reach their own profile once the login is joined', function () {
    Notification::fake();

    asOwner()->post(route('hrm.employees.login-account', $this->employee), ['email' => 'munna@example.com']);

    $user = User::where('email', 'munna@example.com')->sole();

    // The whole point of the join: without it the self-service screen bounces
    // to the dashboard with "your account is not linked to an employee record".
    expect(Employee::forUser($user)?->id)->toBe($this->employee->id);
});

it('refuses an address that already logs in as another employee', function () {
    Notification::fake();

    $other = Employee::create([
        'employee_code' => 'BDG-000098',
        'first_name' => 'Iftekharul',
        'last_name' => 'Alam',
        'employment_type' => EmploymentType::Permanent,
        'status' => 'active',
        'joining_date' => '2026-01-01',
    ]);
    $taken = User::factory()->create(['email' => 'taken@example.com']);
    $other->update(['user_id' => $taken->id]);

    asOwner()->post(route('hrm.employees.login-account', $this->employee), ['email' => 'taken@example.com'])
        ->assertSessionHasErrors('email');

    expect($this->employee->fresh()->user_id)->toBeNull();
    Notification::assertNothingSent();
});

it('refuses a second login for an employee who already has one', function () {
    Notification::fake();
    $this->employee->update(['user_id' => User::factory()->create()->id]);

    asOwner()->post(route('hrm.employees.login-account', $this->employee), ['email' => 'second@example.com'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(User::where('email', 'second@example.com')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('refuses a login for someone who has left', function () {
    Notification::fake();
    $this->employee->update(['status' => 'separated']);

    asOwner()->post(route('hrm.employees.login-account', $this->employee), ['email' => 'gone@example.com'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(User::where('email', 'gone@example.com')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('will not let an HR manager without user management create a login', function () {
    Notification::fake();

    $hr = User::factory()->create();
    $hr->businesses()->attach($this->business->id);
    $hr->assignRole('HR only');

    $this->actingAs($hr)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.employees.login-account', $this->employee), ['email' => 'hr@example.com'])
        ->assertForbidden();

    expect(User::where('email', 'hr@example.com')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('tells the employee page whether a password link is still outstanding', function () {
    Notification::fake();

    asOwner()->post(route('hrm.employees.login-account', $this->employee), ['email' => 'munna@example.com']);

    asOwner()->get(route('hrm.employees.show', $this->employee))
        ->assertInertia(fn ($page) => $page->where('employee.user.password_link_sent_at', fn ($sent) => $sent !== null));

    // Using the link deletes the token, and the screen must stop claiming one
    // is outstanding the moment that happens.
    DB::table('password_reset_tokens')->where('email', 'munna@example.com')->delete();

    asOwner()->get(route('hrm.employees.show', $this->employee))
        ->assertInertia(fn ($page) => $page->where('employee.user.password_link_sent_at', null));
});

it('will not create a login for somebody this business does not employ', function () {
    // ⚠ Audit 13 Oct 2026: the one employee action without the tenancy guard.
    $stranger = Employee::create([
        'employee_code' => 'BDG-000777', 'first_name' => 'Not', 'last_name' => 'Ours',
        'employment_type' => EmploymentType::Permanent, 'status' => 'active',
    ]);

    asOwner()->post(route('hrm.employees.login-account', $stranger), ['email' => 'stranger@example.com'])
        ->assertNotFound();

    expect($stranger->fresh()->user_id)->toBeNull();
});
