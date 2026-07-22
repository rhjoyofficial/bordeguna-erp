<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\BangladeshHolidayCalendarSeeder;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\HolidayCalendar;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Cal Co', 'slug' => 'cal-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Cal', 'slug' => 'other-cal']);
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

function calOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function calPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Bangladesh',
        'year' => 2026,
        'weekly_off_days' => [5],
        'is_default' => true,
    ], $overrides);
}

it('creates a calendar scoped to the current business', function () {
    calOwner()->post(route('hrm.calendars.store'), calPayload())->assertRedirect();

    $calendar = HolidayCalendar::sole();
    expect($calendar->name)->toBe('Bangladesh');
    expect($calendar->business_id)->toBe($this->business->id);
    expect($calendar->weekly_off_days)->toBe([5]);
    expect($calendar->is_default)->toBeTrue();
});

it('keeps only one default calendar per business', function () {
    calOwner()->post(route('hrm.calendars.store'), calPayload(['name' => 'A']))->assertRedirect();
    calOwner()->post(route('hrm.calendars.store'), calPayload(['name' => 'B']))->assertRedirect();

    expect(HolidayCalendar::where('name', 'A')->first()->is_default)->toBeFalse();
    expect(HolidayCalendar::where('name', 'B')->first()->is_default)->toBeTrue();
});

it('enforces a unique name per year per business', function () {
    calOwner()->post(route('hrm.calendars.store'), calPayload())->assertRedirect();
    calOwner()->post(route('hrm.calendars.store'), calPayload())->assertSessionHasErrors('name');
    // Same name, different year is allowed.
    calOwner()->post(route('hrm.calendars.store'), calPayload(['year' => 2027]))->assertRedirect();
});

it('rejects an invalid weekday in weekly off-days', function () {
    calOwner()->post(route('hrm.calendars.store'), calPayload(['weekly_off_days' => [9]]))->assertSessionHasErrors('weekly_off_days.0');
});

it('adds and removes holidays, unique per date', function () {
    calOwner()->post(route('hrm.calendars.store'), calPayload())->assertRedirect();
    $calendar = HolidayCalendar::sole();

    calOwner()->post(route('hrm.calendars.holidays.store', $calendar), ['date' => '2026-03-26', 'name' => 'Independence Day'])->assertRedirect();
    calOwner()->post(route('hrm.calendars.holidays.store', $calendar), ['date' => '2026-03-26', 'name' => 'Dup'])->assertSessionHasErrors('date');

    $holiday = $calendar->holidays()->first();
    expect($holiday->name)->toBe('Independence Day');

    calOwner()->delete(route('hrm.calendars.holidays.destroy', [$calendar, $holiday]))->assertRedirect();
    expect(Holiday::find($holiday->id))->toBeNull();
});

it('will not remove a holiday through the wrong calendar', function () {
    calOwner()->post(route('hrm.calendars.store'), calPayload(['name' => 'One']))->assertRedirect();
    calOwner()->post(route('hrm.calendars.store'), calPayload(['name' => 'Two', 'is_default' => false]))->assertRedirect();
    $one = HolidayCalendar::where('name', 'One')->first();
    $two = HolidayCalendar::where('name', 'Two')->first();
    $holiday = $one->holidays()->create(['date' => '2026-05-01', 'name' => 'May Day']);

    calOwner()->delete(route('hrm.calendars.holidays.destroy', [$two, $holiday]))->assertNotFound();
    expect(Holiday::find($holiday->id))->not->toBeNull();
});

it('blocks a user without hrm.scheduling.manage', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.calendars.index'))->assertForbidden();
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.calendars.store'), calPayload())->assertForbidden();
});

it('does not touch a calendar from another business', function () {
    $other = HolidayCalendar::create(calPayload(['business_id' => $this->otherBusiness->id, 'name' => 'Theirs']));

    calOwner()->put(route('hrm.calendars.update', $other), calPayload(['name' => 'Hijacked']))->assertNotFound();
    expect($other->fresh()->name)->toBe('Theirs');
});

it('seeds a default Bangladesh calendar with Friday off and fixed holidays', function () {
    app(BangladeshHolidayCalendarSeeder::class)->run();

    $calendar = HolidayCalendar::where('name', 'Bangladesh')->first();
    expect($calendar)->not->toBeNull();
    expect($calendar->is_default)->toBeTrue();
    expect($calendar->weekly_off_days)->toBe([5]);
    expect($calendar->holidays()->count())->toBe(5);
    expect($calendar->holidays()->pluck('name'))->toContain('Independence Day');
});

it('renders the calendars page for a scheduler', function () {
    calOwner()->post(route('hrm.calendars.store'), calPayload())->assertRedirect();

    calOwner()->get(route('hrm.calendars.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('hrm/calendars/index')
            ->has('calendars', 1)
            ->where('calendars.0.weekly_off_label', 'Friday'));
});
