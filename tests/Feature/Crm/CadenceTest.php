<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\SweepDormantCustomers;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 4, Slice 3: activity cadences.
 *
 * Two standing rhythms, on the task machinery that already exists (tasks,
 * queue, overdue digest - all built): a NEW LEAD gets its first-call task
 * automatically, and a DORMANT CUSTOMER (no invoice in N days) surfaces as
 * one follow-up task, never a second while the first is open. The cadence
 * creates TASKS; people still do the talking.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Cadence Co', 'slug' => 'cadence-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-03 10:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

it('gives a new lead its first-call task automatically when a cadence says so', function () {
    ($this->asOwner)()->post(route('crm.cadences.store'), [
        'name' => 'First call',
        'trigger' => 'lead_created',
        'delay_days' => 1,
        'task_title' => 'First call to the new lead',
    ])->assertSessionHasNoErrors();

    ($this->asOwner)()->post(route('crm.leads.store'), [
        'name' => 'Fresh Lead', 'phone' => '01733333333', 'status' => 'new',
    ])->assertSessionHasNoErrors();

    $lead = Lead::query()->where('name', 'Fresh Lead')->firstOrFail();
    $task = CrmTask::query()->where('subject_type', Lead::class)->where('subject_id', $lead->id)->first();

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('First call to the new lead')
        ->and($task->due_at->toDateString())->toBe('2026-09-04')
        ->and($task->created_by)->toBe($this->owner->id);
});

it('creates no task when the cadence is paused or absent', function () {
    ($this->asOwner)()->post(route('crm.leads.store'), [
        'name' => 'Quiet Lead', 'phone' => '01744444444', 'status' => 'new',
    ])->assertSessionHasNoErrors();

    expect(CrmTask::query()->count())->toBe(0);

    ($this->asOwner)()->post(route('crm.cadences.store'), [
        'name' => 'Paused rhythm', 'trigger' => 'lead_created', 'delay_days' => 2, 'task_title' => 'Call',
    ]);
    DB::table('crm_cadences')->update(['is_active' => false]);

    ($this->asOwner)()->post(route('crm.leads.store'), [
        'name' => 'Second Quiet Lead', 'phone' => '01755555555', 'status' => 'new',
    ]);

    expect(CrmTask::query()->count())->toBe(0);
});

it('surfaces a dormant customer exactly once while the task stays open', function () {
    ($this->asOwner)()->post(route('crm.cadences.store'), [
        'name' => 'Wake sleeping dealers',
        'trigger' => 'customer_dormant',
        'delay_days' => 30,
        'task_title' => 'No order in a month - call them',
    ])->assertSessionHasNoErrors();

    $dormant = Customer::create([
        'business_id' => $this->business->id, 'code' => 'CUS-0001', 'name' => 'Sleepy Dealer',
        'type' => 'dealer', 'is_active' => true,
    ]);
    $fresh = Customer::create([
        'business_id' => $this->business->id, 'code' => 'CUS-0002', 'name' => 'Active Dealer',
        'type' => 'dealer', 'is_active' => true,
    ]);

    // Sleepy last bought 40 days ago; Active bought 3 days ago.
    foreach ([[$dormant, 40], [$fresh, 3]] as [$customer, $daysAgo]) {
        DB::table('sales_invoices')->insert([
            'business_id' => $this->business->id, 'customer_id' => $customer->id,
            'invoice_number' => 'INV-'.$customer->id,
            'invoice_date' => now()->subDays($daysAgo)->toDateString(),
            'subtotal' => 1000, 'invoice_discount' => 0, 'tax_total' => 0, 'total' => 1000,
            'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    app(SweepDormantCustomers::class)->handle();
    // Running twice must not nag twice.
    app(SweepDormantCustomers::class)->handle();

    $tasks = CrmTask::query()->where('subject_type', Customer::class)->get();
    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->subject_id)->toBe($dormant->id)
        ->and($tasks->first()->title)->toContain('call them');

    // A customer who has NEVER bought is a lead problem, not dormancy.
    Customer::create([
        'business_id' => $this->business->id, 'code' => 'CUS-0003', 'name' => 'Never Bought',
        'type' => 'dealer', 'is_active' => true,
    ]);
    app(SweepDormantCustomers::class)->handle();
    expect(CrmTask::query()->where('subject_type', Customer::class)->count())->toBe(1);
});

it('manages cadences from a gated screen', function () {
    ($this->asOwner)()->get(route('crm.cadences'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('crm/cadences'));

    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);
    $this->actingAs($bystander)->withHeader('X-Business-Id', (string) $this->business->id)
        ->get(route('crm.cadences'))
        ->assertForbidden();
});
