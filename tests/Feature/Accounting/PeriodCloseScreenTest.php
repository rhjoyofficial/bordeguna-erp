<?php

use App\Models\User;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 2, Slice 1: the period close gets its SCREEN.
 *
 * ⚠⚠ The engine has existed since Accounting Phase 1.2 - closeThrough() and
 * unlock() on AccountingPeriod, enforced by assertOpenFor at every posting
 * choke point - and NOTHING CALLED THEM. The tenth dead engine of this
 * project: route + screen + nav link is the rule, and this test would have
 * gone red the day the model shipped had it existed then.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Close Co', 'slug' => 'close-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'accounting.chart.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);

    Role::firstOrCreate(['name' => 'Closer', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.chart.manage', 'accounting.view']);
    Role::firstOrCreate(['name' => 'Viewer', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view']);

    $this->closer = User::factory()->create();
    $this->closer->businesses()->attach($this->business->id);
    $this->closer->assignRole('Closer');

    $this->viewer = User::factory()->create();
    $this->viewer->businesses()->attach($this->business->id);
    $this->viewer->assignRole('Viewer');
});

function asCloser()
{
    return test()->actingAs(test()->closer)->withSession(['current_business_id' => test()->business->id]);
}

it('shows the boundary and the checklist to accounting eyes', function () {
    asCloser()
        ->get(route('accounting.period-close'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/period-close')
            ->where('boundary', null)      // the books have never been closed
            ->has('checklist')
            ->where('can_close', true));

    // A viewer may look but the page tells them they cannot act.
    test()->actingAs(test()->viewer)->withSession(['current_business_id' => test()->business->id])
        ->get(route('accounting.period-close'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_close', false));
});

it('closes the books through a date, with the note on record', function () {
    asCloser()
        ->post(route('accounting.period-close.close'), [
            'closed_through' => '2026-08-31',
            'note' => 'August finalised after the real pay run.',
        ])
        ->assertSessionHasNoErrors();

    $lock = AccountingPeriod::current();

    expect($lock->closed_through->toDateString())->toBe('2026-08-31')
        ->and($lock->is_unlocked)->toBeFalse()
        ->and($lock->note)->toBe('August finalised after the real pay run.');
});

it('refuses the close to a role without the manage permission', function () {
    test()->actingAs(test()->viewer)->withSession(['current_business_id' => test()->business->id])
        ->post(route('accounting.period-close.close'), ['closed_through' => '2026-08-31'])
        ->assertForbidden();

    expect(AccountingPeriod::current())->toBeNull();
});

it('unlocks only with a written reason, and records who', function () {
    AccountingPeriod::closeThrough(now()->parse('2026-08-31'), $this->closer);

    // No reason, no unlock.
    asCloser()
        ->post(route('accounting.period-close.unlock'), [])
        ->assertSessionHasErrors('reason');

    asCloser()
        ->post(route('accounting.period-close.unlock'), ['reason' => 'The accountant found a missing August bill.'])
        ->assertSessionHasNoErrors();

    $lock = AccountingPeriod::current();

    expect($lock->is_unlocked)->toBeTrue()
        ->and($lock->unlock_note)->toBe('The accountant found a missing August bill.')
        ->and($lock->unlocked_by)->toBe($this->closer->id);
});

it('relocks after an unlock by closing again', function () {
    AccountingPeriod::closeThrough(now()->parse('2026-08-31'), $this->closer);
    AccountingPeriod::current()->unlock($this->closer, 'Fixing one bill');

    asCloser()
        ->post(route('accounting.period-close.close'), ['closed_through' => '2026-08-31'])
        ->assertSessionHasNoErrors();

    expect(AccountingPeriod::current()->is_unlocked)->toBeFalse();
});
