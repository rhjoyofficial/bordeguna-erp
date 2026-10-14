<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Sound;
use App\Modules\Foundation\Models\SoundSlot;
use App\Modules\Foundation\Models\WorkScheduleSetting;
use App\Modules\Foundation\Models\WorkScheduleSlot;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Work schedule alerts (Joy, 14 Oct 2026): the nine seeded slots, Owner and
 * Manager CRUD, the overlap and end-before-start refusals, Today's rhythm for
 * everybody, the person's two switches and the managers' master switch on the
 * SHARED PROP the alert engine reads, and the 'schedule' sound event.
 */
beforeEach(function () {
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Rhythm Co', 'slug' => 'rhythm-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::findOrCreate('schedule.manage', 'web');
    Permission::findOrCreate('system.popups.manage', 'web');
    Role::create(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo('schedule.manage', 'system.popups.manage');
    Role::create(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo('schedule.manage');
    Role::create(['name' => 'Cashier', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->owner = wsMember($this->business, 'Owner');
    $this->manager = wsMember($this->business, 'Manager');
    $this->cashier = wsMember($this->business, 'Cashier');
});

function wsMember(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->syncWithoutDetaching([$business->id]);
    setPermissionsTeamId($business->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function wsAs(object $t, User $user)
{
    return $t->actingAs($user->fresh())
        ->withSession(['current_business_id' => $t->business->id])
        ->withHeader('X-Business-Id', (string) $t->business->id);
}

/** @return array<string, mixed> a valid slot form */
function wsForm(array $overrides = []): array
{
    return array_merge([
        'starts_at' => '18:30',
        'ends_at' => '19:00',
        'title' => 'Evening review',
        'subtitle' => 'Plan tomorrow',
        'kind' => 'wrap',
        'weekdays' => [6, 0, 1, 2, 3, 4],
        'is_active' => 1,
        'sort_order' => 0,
    ], $overrides);
}

it('seeds Joy\'s nine slots, Saturday to Thursday, and the default settings', function () {
    $slots = WorkScheduleSlot::query()->ordered()->get();

    expect($slots)->toHaveCount(9)
        ->and(WorkScheduleSlot::hm($slots->first()->starts_at))->toBe('09:05')
        ->and($slots->first()->title)->toBe('1st Deep Focus Slot')
        ->and($slots->first()->days())->toBe([6, 0, 1, 2, 3, 4])
        ->and($slots->pluck('kind')->unique()->sort()->values()->all())->toBe(['break', 'focus', 'meal', 'prayer', 'wrap'])
        ->and(WorkScheduleSlot::hm($slots->last()->ends_at))->toBe('18:00')
        ->and($slots->firstWhere('kind', 'meal')->title)->toBe('Lunch, Prayer & Nap')
        ->and(WorkScheduleSetting::current()->alert_seconds)->toBe(8)
        ->and(WorkScheduleSetting::current()->is_enabled)->toBeTrue();
});

it('lets the Owner and the Manager manage the slots, and not the Cashier', function () {
    wsAs($this, $this->owner)->get('/settings/work-schedule')->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/work-schedule')->has('slots', 9));
    wsAs($this, $this->manager)->get('/settings/work-schedule')->assertOk();
    wsAs($this, $this->cashier)->get('/settings/work-schedule')->assertForbidden();
    wsAs($this, $this->cashier)->post('/settings/work-schedule', wsForm())->assertForbidden();

    wsAs($this, $this->owner)->get('/settings/appearance')->assertInertia(fn ($page) => $page->where('canManageSchedule', true));
    wsAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page->where('canManageSchedule', false));

    wsAs($this, $this->manager)->post('/settings/work-schedule', wsForm())->assertSessionHasNoErrors();
    $slot = WorkScheduleSlot::query()->where('title', 'Evening review')->firstOrFail();
    expect($slot->created_by)->toBe($this->manager->id)
        ->and($slot->days())->toBe([0, 1, 2, 3, 4, 6]);

    wsAs($this, $this->owner)->put("/settings/work-schedule/{$slot->id}", wsForm(['title' => 'Evening plan', 'weekdays' => [5]]))->assertSessionHasNoErrors();
    expect($slot->fresh()->title)->toBe('Evening plan')
        ->and($slot->fresh()->days())->toBe([5])
        ->and($slot->fresh()->updated_by)->toBe($this->owner->id);

    wsAs($this, $this->cashier)->patch("/settings/work-schedule/{$slot->id}/toggle")->assertForbidden();
    wsAs($this, $this->manager)->patch("/settings/work-schedule/{$slot->id}/toggle")->assertSessionHas('success');
    expect($slot->fresh()->is_active)->toBeFalse();

    wsAs($this, $this->cashier)->delete("/settings/work-schedule/{$slot->id}")->assertForbidden();
    wsAs($this, $this->owner)->delete("/settings/work-schedule/{$slot->id}")->assertSessionHas('success');
    expect(WorkScheduleSlot::query()->whereKey($slot->id)->exists())->toBeFalse();

    expect(Activity::query()->where('log_name', 'system')->pluck('description')->all())
        ->toContain('Added a work schedule slot', 'Changed a work schedule slot', 'Paused a work schedule slot', 'Removed a work schedule slot');
});

it('refuses an active slot that overlaps another on a shared day, and one that ends before it starts', function () {
    // 10:00 to 10:30 sits inside the 09:05 to 11:00 focus slot.
    wsAs($this, $this->owner)->post('/settings/work-schedule', wsForm(['starts_at' => '10:00', 'ends_at' => '10:30']))
        ->assertSessionHasErrors('starts_at');
    // The same times on Friday share no day with the seeded slots.
    wsAs($this, $this->owner)->post('/settings/work-schedule', wsForm(['starts_at' => '10:00', 'ends_at' => '10:30', 'weekdays' => [5]]))
        ->assertSessionHasNoErrors();
    // Paused, it may overlap; resuming it is refused.
    wsAs($this, $this->owner)->post('/settings/work-schedule', wsForm(['starts_at' => '10:00', 'ends_at' => '10:30', 'title' => 'Paused one', 'is_active' => 0]))
        ->assertSessionHasNoErrors();
    $paused = WorkScheduleSlot::query()->where('title', 'Paused one')->firstOrFail();
    wsAs($this, $this->owner)->patch("/settings/work-schedule/{$paused->id}/toggle")->assertSessionHasErrors('starts_at');
    expect($paused->fresh()->is_active)->toBeFalse();
    // Touching ends are not an overlap.
    wsAs($this, $this->owner)->post('/settings/work-schedule', wsForm(['starts_at' => '18:00', 'ends_at' => '18:15']))->assertSessionHasNoErrors();

    wsAs($this, $this->owner)->post('/settings/work-schedule', wsForm(['starts_at' => '20:00', 'ends_at' => '19:00']))->assertSessionHasErrors('ends_at');
    wsAs($this, $this->owner)->post('/settings/work-schedule', wsForm(['starts_at' => '9 am']))->assertSessionHasErrors('starts_at');
    wsAs($this, $this->owner)->post('/settings/work-schedule', wsForm(['weekdays' => []]))->assertSessionHasErrors('weekdays');
    wsAs($this, $this->owner)->post('/settings/work-schedule', wsForm(['kind' => 'nap']))->assertSessionHasErrors('kind');

    // Editing a slot never clashes with itself.
    $first = WorkScheduleSlot::query()->ordered()->firstOrFail();
    wsAs($this, $this->owner)->put("/settings/work-schedule/{$first->id}", wsForm(['starts_at' => '09:00', 'ends_at' => '11:00']))->assertSessionHasNoErrors();
});

it('shows Today\'s rhythm to every signed-in person', function () {
    wsAs($this, $this->cashier)->get('/schedule')->assertOk()
        ->assertInertia(fn ($page) => $page->component('schedule')->has('slots', 9)->has('server_now'));
});

// ⚠ Its own test: actingAs() sticks to the test case.
it('is not reachable by a guest', function () {
    $this->get('/schedule')->assertRedirect('/login');
    $this->get('/settings/work-schedule')->assertRedirect('/login');
    $this->patch('/settings/schedule-alerts', ['enabled' => 0])->assertRedirect('/login');
});

it('hands the alert engine the slots, the clock and the switches', function () {
    wsAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->where('schedule.enabled', true)
        ->where('schedule.own_enabled', true)
        ->where('schedule.global_enabled', true)
        ->where('schedule.sound', true)
        ->where('schedule.alert_seconds', 8)
        ->where('schedule.sound_url', '/sounds/success.mp3')
        ->where('schedule.server_now', fn ($value) => str_ends_with((string) $value, '+06:00'))
        ->has('schedule.slots', 9)
        ->where('schedule.slots.0.starts_at', '09:05')
        ->where('schedule.slots.0.weekdays', [6, 0, 1, 2, 3, 4]));

    // The person's own switches.
    wsAs($this, $this->cashier)->patch('/settings/schedule-alerts', ['sound' => 0])->assertSessionHasNoErrors();
    wsAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->where('schedule.enabled', true)
        ->where('schedule.sound', false));
    wsAs($this, $this->cashier)->patch('/settings/schedule-alerts', ['enabled' => 0])->assertSessionHasNoErrors();
    wsAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->where('schedule.enabled', false)
        ->where('schedule.own_enabled', false)
        ->where('schedule.sound', false));
    expect($this->cashier->fresh()->preferences['schedule_alerts'])->toBe(['enabled' => false, 'sound' => false]);

    // Somebody else is untouched.
    wsAs($this, $this->owner)->get('/settings/appearance')->assertInertia(fn ($page) => $page->where('schedule.enabled', true));

    // A paused slot is not in the feed.
    $prayer = WorkScheduleSlot::query()->where('kind', 'prayer')->firstOrFail();
    wsAs($this, $this->owner)->patch("/settings/work-schedule/{$prayer->id}/toggle");
    wsAs($this, $this->owner)->get('/settings/appearance')->assertInertia(fn ($page) => $page->has('schedule.slots', 8));
});

it('switches the alerts off for everybody, and keeps the seconds in range', function () {
    wsAs($this, $this->cashier)->put('/settings/work-schedule/settings', ['alert_seconds' => 10, 'is_enabled' => 0])->assertForbidden();

    wsAs($this, $this->manager)->put('/settings/work-schedule/settings', ['alert_seconds' => 2, 'is_enabled' => 1])->assertSessionHasErrors('alert_seconds');
    wsAs($this, $this->manager)->put('/settings/work-schedule/settings', ['alert_seconds' => 31, 'is_enabled' => 1])->assertSessionHasErrors('alert_seconds');
    wsAs($this, $this->manager)->put('/settings/work-schedule/settings', ['alert_seconds' => 12, 'is_enabled' => 0])->assertSessionHasNoErrors();

    wsAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->where('schedule.enabled', false)
        ->where('schedule.own_enabled', true)
        ->where('schedule.global_enabled', false)
        ->where('schedule.alert_seconds', 12));

    $logged = Activity::query()->where('description', 'Changed the work schedule settings')->latest('id')->firstOrFail();
    expect($logged->causer_id)->toBe($this->manager->id)
        ->and($logged->properties['now'])->toBe(['alert_seconds' => 12, 'is_enabled' => false]);
});

it('adds a schedule event to Settings > Sounds that the Owner can point at a library sound', function () {
    expect(SoundSlot::query()->whereKey('schedule')->exists())->toBeTrue();

    wsAs($this, $this->owner)->get('/settings/sounds')->assertInertia(fn ($page) => $page
        ->where('slots.schedule', null)
        ->where('builtIn.schedule', '/sounds/success.mp3'));
    wsAs($this, $this->owner)->get('/settings/appearance')->assertInertia(fn ($page) => $page->where('popups.sounds.schedule', null));

    $sound = Sound::create(['name' => 'Gong', 'disk' => 'local', 'path' => 'sounds/gong.mp3', 'mime_type' => 'audio/mpeg', 'size' => 1]);
    wsAs($this, $this->owner)->put('/settings/sounds/slots', ['slots' => ['schedule' => $sound->id]])->assertSessionHasNoErrors();

    wsAs($this, $this->owner)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->where('popups.sounds.schedule', $sound->url())
        ->where('schedule.sound_url', $sound->url()));
    expect($sound->fresh()->usedBy()['slots'])->toBe(['schedule']);
});
