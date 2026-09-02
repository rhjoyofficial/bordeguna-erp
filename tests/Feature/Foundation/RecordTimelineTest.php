<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use App\Support\RecordTimeline;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 1, Slice 4 (master plan §32): every important record tells its own
 * story on its own page - created, changed, submitted, approved - assembled
 * from the activity log the system already keeps and the approval decisions
 * already recorded. The timeline WRITES nothing and invents nothing: if an
 * event is not on record, it is not on the timeline.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Story Co', 'slug' => 'story-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'sales.reports.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'sales.customers.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'StoryOwner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.reports.view', 'sales.customers.manage']);

    $this->owner = User::factory()->create(['name' => 'Story Teller']);
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('StoryOwner');
});

it('tells a record\'s story from the activity log, newest first', function () {
    $this->actingAs($this->owner);

    $customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0001', 'name' => 'Rahim Store', 'type' => 'retail',
    ]);
    $this->travel(1)->minutes();
    $customer->update(['phone' => '01712345678', 'payment_terms' => '15 days']);

    $entries = RecordTimeline::for($customer);

    expect(count($entries))->toBe(2)
        // Newest first: the update leads, the creation closes the story.
        ->and($entries[0]['what'])->toContain('Changed')
        ->and($entries[0]['what'])->toContain('phone')
        ->and($entries[0]['who'])->toBe('Story Teller')
        ->and($entries[1]['what'])->toContain('Created');
});

it('weaves approval submissions and decisions into the story', function () {
    $this->actingAs($this->owner);

    $customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0002', 'name' => 'Karim Store', 'type' => 'retail',
    ]);

    $workflow = DB::table('approval_workflows')->insertGetId([
        'business_id' => $this->business->id,
        'key' => 'story-test', 'name' => 'Story test',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $request = DB::table('approval_requests')->insertGetId([
        'business_id' => $this->business->id,
        'workflow_id' => $workflow,
        'current_stage' => 1,
        'approvable_type' => Customer::class,
        'approvable_id' => $customer->id,
        'status' => 'approved',
        'requested_by' => $this->owner->id,
        'created_at' => now()->addMinutes(2), 'updated_at' => now()->addMinutes(3),
    ]);

    DB::table('approval_decisions')->insert([
        'approval_request_id' => $request,
        'stage_sequence' => 1,
        'stage_name' => 'Manager',
        'decided_by' => $this->owner->id,
        'decision' => 'approved',
        'note' => 'Looks right',
        'decided_at' => now()->addMinutes(3),
        'created_at' => now()->addMinutes(3), 'updated_at' => now()->addMinutes(3),
    ]);

    $entries = RecordTimeline::for($customer);
    $texts = array_column($entries, 'what');

    expect(implode(' | ', $texts))->toContain('Submitted for approval')
        ->and(implode(' | ', $texts))->toContain('Approved')
        // The decision entry names its stage and carries the note.
        ->and(collect($entries)->first(fn ($e) => str_contains($e['what'], 'Approved'))['note'])->toBe('Looks right');
});

it('hands the customer 360 its timeline', function () {
    $this->actingAs($this->owner);

    $customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0003', 'name' => 'Timeline Store', 'type' => 'retail',
    ]);

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('sales.customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('timeline')
            ->where('timeline.0.what', fn ($what) => str_contains($what, 'Created')));
});
