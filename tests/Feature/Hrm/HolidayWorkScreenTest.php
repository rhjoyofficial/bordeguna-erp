<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holiday work is reachable, gated, and honest about an ordinary day.
 *
 * ⚠⚠ THE FIRST TEST IS THE ONE THAT MATTERS. Five engines in this system have
 * shipped complete, correct and unreachable. A screen that answers 200 for the
 * right person and 403 for everyone else is the minimum proof that a build is
 * a feature rather than a folder of files.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Holiday Co', 'slug' => 'holiday-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.leave.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'HR', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.leave.manage');
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('HR');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

it('opens the holiday work screen for someone who may manage leave', function () {
    $this->actingAs($this->hr)
        ->get('/hrm/holiday-work')
        ->assertOk();
});

it('opens the year report', function () {
    $this->actingAs($this->hr)
        ->get('/hrm/holiday-work/report?year=2026')
        ->assertOk();
});

it('refuses both screens to everyone else', function () {
    $this->actingAs($this->staff)->get('/hrm/holiday-work')->assertForbidden();
    $this->actingAs($this->staff)->get('/hrm/holiday-work/report')->assertForbidden();
});

it('refuses a guest', function () {
    $this->get('/hrm/holiday-work')->assertRedirect('/login');
});

it('says plainly when the date is not a non-working day', function () {
    // ⚠ A fact on the screen rather than a refusal: the holiday calendar can be
    // filled in after the fact, and the honest answer then is "this is not a
    // holiday yet", not silence.
    $this->actingAs($this->hr)
        ->get('/hrm/holiday-work?date=2026-08-27')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('is_non_working_day', false));
});

it('will not record holiday work without a reason or a person', function () {
    $this->actingAs($this->hr)
        ->post('/hrm/holiday-work', ['date' => '2026-08-26', 'employee_ids' => [], 'reason' => ''])
        ->assertSessionHasErrors(['employee_ids', 'reason']);
});

it('refuses recording from anyone without the leave permission', function () {
    $this->actingAs($this->staff)
        ->post('/hrm/holiday-work', ['date' => '2026-08-26', 'employee_ids' => [1], 'reason' => 'Dispatch'])
        ->assertForbidden();
});
