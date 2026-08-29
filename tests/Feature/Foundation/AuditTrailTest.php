<?php

use App\Models\User;
use App\Modules\Foundation\Models\ActivityLogEntry;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The screen over the audit trail.
 *
 * ⚠⚠ The trail itself was never the gap. Models have been logging their changes
 * since each shipped, into a table with no screen over it, so the one question
 * an audit trail exists to answer - who changed this, and what was it before -
 * could not be asked at all.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Audit Co', 'slug' => 'audit-co']);
    $this->other = Business::create(['name' => 'Other Co', 'slug' => 'other-audit-co']);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'foundation.organization.manage', 'guard_name' => 'web']);

    foreach (['Owner', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo('foundation.organization.manage');

    $this->owner = User::factory()->create(['name' => 'Joy']);
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);
});

it('shows who changed a record, and what it was before', function () {
    $this->actingAs($this->owner);

    $customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0001',
        'name' => 'Karim Traders',
        'type' => CustomerType::Retail,
        'credit_limit' => 10000,
    ]);

    $customer->update(['credit_limit' => 50000]);

    ($this->asOwner)()->get(route('audit.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $entries = collect($page->toArray()['props']['entries']['data']);

            // Newest first: the update, then the creation.
            expect($entries->first()['event'])->toBe('updated')
                ->and($entries->first()['subject'])->toBe('Customer')
                ->and($entries->first()['causer'])->toBe('Joy');

            $change = collect($entries->first()['changes'])->firstWhere('field', 'Credit Limit');

            expect($change['from'])->toContain('10000')
                ->and($change['to'])->toContain('50000');

            return true;
        });
});

it('filters by the kind of record and by a value somebody typed', function () {
    $this->actingAs($this->owner);

    Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0002',
        'name' => 'Findable Trading',
        'type' => CustomerType::Retail,
    ]);

    ($this->asOwner)()->get(route('audit.index', ['search' => 'Findable']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('entries.data', fn ($rows) => count($rows) === 1));

    // A type nothing was logged under returns nothing rather than everything.
    ($this->asOwner)()->get(route('audit.index', ['type' => 'App\\Modules\\Hrm\\Models\\Employee']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('entries.data', []));
});

it('filters by WHO made the change, not just by their name appearing somewhere', function () {
    $colleague = User::factory()->create(['name' => 'Shuborna']);
    $colleague->businesses()->attach($this->business->id);

    $this->actingAs($this->owner);
    Customer::create(['business_id' => $this->business->id, 'code' => 'CUS-J', 'name' => 'Joy Traders', 'type' => CustomerType::Retail]);

    $this->actingAs($colleague);
    Customer::create(['business_id' => $this->business->id, 'code' => 'CUS-S', 'name' => 'Other Traders', 'type' => CustomerType::Retail]);

    // ⚠⚠ THE REASON THIS IS NOT THE FREE-TEXT SEARCH. Searching "Joy" also
    // matches the customer NAMED Joy Traders, which somebody else created. The
    // first question anybody brings to an audit trail is "what did this person
    // change", and a name match cannot answer it.
    ($this->asOwner)()->get(route('audit.index', ['causer' => $colleague->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('entries.data', fn ($rows) => count($rows) === 1
            && $rows[0]['causer'] === 'Shuborna'));

    ($this->asOwner)()->get(route('audit.index', ['search' => 'Joy']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('entries.data', fn ($rows) => count($rows) >= 1));
});

it('offers only people who actually appear in the trail', function () {
    $never = User::factory()->create(['name' => 'Never Touched Anything']);
    $never->businesses()->attach($this->business->id);

    $this->actingAs($this->owner);
    Customer::create(['business_id' => $this->business->id, 'code' => 'CUS-P', 'name' => 'Someone', 'type' => CustomerType::Retail]);

    // ⚠ A filter that offers a choice returning nothing teaches people the
    // screen is broken. Same rule the record-type list already follows.
    ($this->asOwner)()->get(route('audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('people', fn ($people) => collect($people)->pluck('label')->contains('Joy')
            && ! collect($people)->pluck('label')->contains('Never Touched Anything')));
});

it('narrows to one record, so "what happened to this customer" has an answer', function () {
    $this->actingAs($this->owner);

    $first = Customer::create(['business_id' => $this->business->id, 'code' => 'CUS-A', 'name' => 'First', 'type' => CustomerType::Retail]);
    Customer::create(['business_id' => $this->business->id, 'code' => 'CUS-B', 'name' => 'Second', 'type' => CustomerType::Retail]);
    $first->update(['name' => 'First, renamed']);

    ($this->asOwner)()->get(route('audit.index', ['record' => $first->id, 'type' => Customer::class]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('entries.data', fn ($rows) => count($rows) === 2
            && collect($rows)->every(fn ($row) => $row['subject_id'] === $first->id)));
});

it('refuses a date it cannot read instead of throwing a 500 at a stale bookmark', function () {
    ($this->asOwner)()->get(route('audit.index', ['from' => 'not-a-date']))
        ->assertSessionHasErrors('from');
});

it('never shows one business the changes made in another', function () {
    // ⚠ Written straight to the table under the other tenant: the point is the
    // scope on the way OUT, and going through a model would only prove the
    // scope on the way in.
    DB::table('activity_log')->insert([
        'business_id' => $this->other->id,
        'log_name' => 'default',
        'description' => 'updated',
        'subject_type' => Customer::class,
        'subject_id' => 999,
        'event' => 'updated',
        'properties' => json_encode(['attributes' => ['name' => 'Rival Secret Ltd']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ($this->asOwner)()->get(route('audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('entries.data', []));

    expect(ActivityLogEntry::withoutGlobalScopes()->count())->toBe(1);
});

it('is shut to anybody below organisation management', function () {
    $staff = User::factory()->create();
    $staff->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $staff->assignRole('Staff');

    $this->actingAs($staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('audit.index'))
        ->assertForbidden();
});

it('offers no way at all to change or delete the evidence', function () {
    // ⚠⚠ Evidence that the person it is evidence about can tidy away is not
    // evidence. If a delete is ever wanted it is a deliberate command with
    // Joy's approval, never a button on this screen.
    expect(Route::has('audit.destroy'))->toBeFalse()
        ->and(Route::has('audit.update'))->toBeFalse()
        ->and(Route::has('audit.store'))->toBeFalse();

    $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'settings/audit'));

    expect($routes)->toHaveCount(1)
        ->and($routes->first()->methods())->toContain('GET');
});
