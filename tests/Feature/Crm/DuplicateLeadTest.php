<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two leads that turn out to be one person.
 *
 * ⚠⚠ THE CLAIM UNDER TEST IS THAT NOTHING IS MOVED AND NOTHING IS DELETED. The
 * obvious "merge" re-parents the duplicate's history onto the survivor and
 * removes the row - a retrospective edit of history wearing a helpful name, and
 * the thing this codebase refuses everywhere else.
 * CRM-PHASE-B-ARCHITECTURE.md, C-CRMB-31..37.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    $this->business = Business::create(['name' => 'Dup Co', 'slug' => 'dup-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-dup-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->asRole)('Owner');

    $this->survivor = Lead::create([
        'business_id' => $this->business->id, 'name' => 'Rahim Traders',
        'phone' => '01712345678', 'status' => LeadStatus::Qualified,
    ]);

    $this->duplicate = Lead::create([
        'business_id' => $this->business->id, 'name' => 'Rahim Trading',
        'phone' => '01712345678', 'status' => LeadStatus::New,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

// ── Detection ───────────────────────────────────────────────────────────────

it('finds an existing lead by an equivalently written phone number', function (string $typed) {
    // ⚠ PhoneIdentity normalises +880, 880 and a missing leading zero, so the
    // same person typed four ways matches. What the user typed is never changed.
    $response = $this->actingAs($this->owner)
        ->getJson('/crm/leads/duplicates?phone='.urlencode($typed));

    $response->assertOk();
    expect(collect($response->json('leads'))->pluck('id'))->toContain($this->survivor->id);
})->with(['01712345678', '+8801712345678', '8801712345678', '1712345678']);

it('never matches across a tenant boundary', function () {
    TenantScope::enter($this->other->id);
    Lead::create(['business_id' => $this->other->id, 'name' => 'Rival', 'phone' => '01712345678', 'status' => 'new']);
    TenantScope::enter($this->business->id);

    $response = $this->actingAs($this->owner)->getJson('/crm/leads/duplicates?phone=01712345678');

    // Only this business's two leads, never the rival's.
    expect(collect($response->json('leads')))->toHaveCount(2);
});

// ── ⚠⚠ Linking preserves everything ─────────────────────────────────────────

it('links a duplicate without moving or deleting anything', function () {
    CrmActivity::create([
        'business_id' => $this->business->id,
        'subject_type' => $this->duplicate->getMorphClass(), 'subject_id' => $this->duplicate->id,
        'type' => ActivityType::Call, 'occurred_at' => now(), 'summary' => 'Called the duplicate',
    ]);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->duplicate->id}/duplicate-of", ['survivor_id' => $this->survivor->id])
        ->assertRedirect();

    $duplicate = $this->duplicate->fresh();

    expect($duplicate->duplicate_of_lead_id)->toBe($this->survivor->id)
        ->and($duplicate->isDuplicate())->toBeTrue()
        ->and($duplicate->merged_by)->toBe($this->owner->id)
        // ⚠⚠ BOTH LEADS SURVIVE, and the activity still belongs to the lead it
        // actually happened to. Re-parenting it would make "which campaign
        // produced this" answer differently than it did yesterday.
        ->and(Lead::count())->toBe(2)
        ->and(CrmActivity::sole()->subject_id)->toBe($this->duplicate->id);

    // The status is untouched: "is a duplicate" is the link, not a state.
    expect($duplicate->status)->toBe(LeadStatus::New);
});

it('reads the duplicate\'s history through the survivor', function () {
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->duplicate->id}/duplicate-of", ['survivor_id' => $this->survivor->id]);

    expect($this->survivor->fresh()->duplicates)->toHaveCount(1)
        ->and($this->survivor->fresh()->duplicates->first()->id)->toBe($this->duplicate->id)
        ->and($this->duplicate->fresh()->duplicateOf->id)->toBe($this->survivor->id);
});

it('keeps duplicates out of the workable list', function () {
    expect(Lead::workable()->count())->toBe(2);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->duplicate->id}/duplicate-of", ['survivor_id' => $this->survivor->id]);

    // ⚠ A prospect nobody should call twice must not sit in the queue twice.
    expect(Lead::workable()->count())->toBe(1)
        ->and(Lead::workable()->sole()->id)->toBe($this->survivor->id)
        // ...and is still there when looked for deliberately.
        ->and(Lead::count())->toBe(2);
});

// ── What it refuses ─────────────────────────────────────────────────────────

it('refuses to make a lead a duplicate of itself', function () {
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->duplicate->id}/duplicate-of", ['survivor_id' => $this->duplicate->id])
        ->assertSessionHasErrors('survivor_id');

    expect($this->duplicate->fresh()->duplicate_of_lead_id)->toBeNull();
});

it('refuses a chain of duplicates', function () {
    $third = Lead::create(['business_id' => $this->business->id, 'name' => 'Third', 'status' => 'new']);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->duplicate->id}/duplicate-of", ['survivor_id' => $this->survivor->id]);

    // ⚠⚠ C-CRMB-33. Otherwise "whose history is this" needs a recursive walk
    // that can cycle, and every report has to know how to follow it.
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$third->id}/duplicate-of", ['survivor_id' => $this->duplicate->id])
        ->assertSessionHasErrors('survivor_id');

    expect($third->fresh()->duplicate_of_lead_id)->toBeNull();
});

it('refuses to re-link a lead that is already a duplicate', function () {
    $third = Lead::create(['business_id' => $this->business->id, 'name' => 'Third', 'status' => 'new']);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->duplicate->id}/duplicate-of", ['survivor_id' => $this->survivor->id]);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->duplicate->id}/duplicate-of", ['survivor_id' => $third->id])
        ->assertSessionHasErrors('survivor_id');

    expect($this->duplicate->fresh()->duplicate_of_lead_id)->toBe($this->survivor->id);
});

it('refuses to mark a CONVERTED lead as a duplicate', function () {
    // ⚠⚠ C-CRMB-34. It has a customer behind it and every conversion figure
    // derives from it. Two converted leads is a CUSTOMER-level question.
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->duplicate->id}/convert", ['code' => 'RAHIM']);

    expect($this->duplicate->fresh()->status)->toBe(LeadStatus::Converted);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->duplicate->id}/duplicate-of", ['survivor_id' => $this->survivor->id])
        ->assertSessionHasErrors('survivor_id');

    expect($this->duplicate->fresh()->duplicate_of_lead_id)->toBeNull()
        ->and(Customer::where('code', 'RAHIM')->count())->toBe(1);
});

it('refuses a survivor from another business', function () {
    TenantScope::enter($this->other->id);
    $foreign = Lead::create(['business_id' => $this->other->id, 'name' => 'Rival', 'status' => 'new']);
    TenantScope::enter($this->business->id);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->duplicate->id}/duplicate-of", ['survivor_id' => $foreign->id])
        ->assertSessionHasErrors('survivor_id');

    expect($this->duplicate->fresh()->duplicate_of_lead_id)->toBeNull();
});

it('cannot link another business\'s lead', function () {
    TenantScope::enter($this->other->id);
    $foreign = Lead::create(['business_id' => $this->other->id, 'name' => 'Rival', 'status' => 'new']);
    TenantScope::enter($this->business->id);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$foreign->id}/duplicate-of", ['survivor_id' => $this->survivor->id])
        ->assertNotFound();
});

// ── Guards ──────────────────────────────────────────────────────────────────

it('cannot be marked a duplicate by ordinary editing', function () {
    // ⚠⚠ Unfillable, like the conversion columns. A lead marked a duplicate by
    // a stray mass assignment would vanish from the working list with nobody
    // having decided it should.
    $this->duplicate->fill([
        'duplicate_of_lead_id' => $this->survivor->id,
        'merged_at' => now(),
    ])->save();

    expect($this->duplicate->fresh()->duplicate_of_lead_id)->toBeNull()
        ->and($this->duplicate->fresh()->merged_at)->toBeNull();
});

it('keeps the duplicate columns out of the lead\'s fillable list', function () {
    $fillable = (new Lead)->getFillable();

    expect($fillable)->not->toContain('duplicate_of_lead_id')
        ->and($fillable)->not->toContain('merged_at')
        ->and($fillable)->not->toContain('merged_by');
});

it('adds no LeadStatus case', function () {
    // ⚠⚠ C-CRMB-32. An eighth case would shatter every exhaustive match over
    // the enum to express what a nullable foreign key already says. The
    // shared-enum seam has bitten this project before.
    expect(LeadStatus::cases())->toHaveCount(7);
});

it('refuses somebody without permission to manage leads', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $this->actingAs($outsider)
        ->post("/crm/leads/{$this->duplicate->id}/duplicate-of", ['survivor_id' => $this->survivor->id])
        ->assertForbidden();
});
