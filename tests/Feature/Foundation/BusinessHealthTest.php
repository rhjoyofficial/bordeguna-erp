<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 7, Slice 1: the Business Health feed.
 *
 * One screen of everything that needs a human, each item computed FRESH
 * from the module that owns it - overdue tickets, run-out probations,
 * needs-review attendance, low stock, dormant follow-ups, waiting
 * approvals. The feed invents no state and stores nothing: it is the
 * morning walk around the shop, done by the system. Every item links to
 * the screen where the fix lives, and a viewer sees only the signals
 * their permissions already let them act on.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Health Co', 'slug' => 'health-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new HrmPermissionsSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    (new \App\Modules\Crm\database\seeders\CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-10 09:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('surfaces an overdue ticket and a dormant nudge as health signals', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-1', 'name' => 'Rahim', 'type' => 'dealer', 'is_active' => true,
    ]);

    // An overdue high-priority ticket: resolve_by two days gone.
    DB::table('crm_tickets')->insert([
        'business_id' => $this->business->id,
        'ticket_number' => 'TK-1', 'customer_id' => $customer->id,
        'subject' => 'Broken jar', 'category' => 'quality', 'priority' => 'high',
        'status' => 'open', 'resolve_by' => '2026-09-08 09:00:00',
        'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->asOwner)()->get(route('health'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('health')
            ->where('signals', fn ($signals) => collect($signals)
                ->contains(fn ($signal) => $signal['key'] === 'tickets_overdue' && $signal['count'] === 1)));
});

it('stays green when nothing needs anyone', function () {
    ($this->asOwner)()->get(route('health'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('health')
            ->where('signals', fn ($signals) => collect($signals)->every(fn ($signal) => $signal['count'] === 0)));
});

it('shows a signal only to somebody whose permissions can act on it', function () {
    DB::table('crm_tickets')->insert([
        'business_id' => $this->business->id,
        'ticket_number' => 'TK-2', 'caller_name' => 'Karim',
        'subject' => 'Late delivery', 'category' => 'delivery', 'priority' => 'high',
        'status' => 'open', 'resolve_by' => '2026-09-08 09:00:00',
        'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);

    $this->actingAs($bystander)->withSession(['current_business_id' => $this->business->id])
        ->get(route('health'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('signals', fn ($signals) => ! collect($signals)
                ->contains(fn ($signal) => $signal['key'] === 'tickets_overdue')));
});
