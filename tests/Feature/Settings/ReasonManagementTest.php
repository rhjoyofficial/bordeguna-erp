<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Reasons Co', 'slug' => 'reasons-co']);
    app(BusinessContext::class)->set($this->business->id);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'inventory.catalog.manage', 'guard_name' => 'web']);

    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo('inventory.catalog.manage');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

it('lets a permitted user create, rename, and deactivate a reason', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('reasons.store'), [
            'context' => 'inventory.stock_adjustment',
            'name' => 'Damaged',
        ])
        ->assertRedirect();

    $reason = Reason::sole();
    expect($reason->name)->toBe('Damaged');
    expect($reason->context)->toBe('inventory.stock_adjustment');
    expect($reason->is_active)->toBeTrue();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->put(route('reasons.update', $reason), ['name' => 'Damaged in transit'])
        ->assertRedirect();

    expect($reason->fresh()->name)->toBe('Damaged in transit');

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch(route('reasons.toggle-active', $reason))
        ->assertRedirect();

    expect($reason->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch(route('reasons.toggle-active', $reason))
        ->assertRedirect();

    expect($reason->fresh()->is_active)->toBeTrue();
});

it('rejects a duplicate reason name within the same business and context', function () {
    Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_adjustment', 'name' => 'Damaged']);

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('reasons.store'), [
            'context' => 'inventory.stock_adjustment',
            'name' => 'Damaged',
        ])
        ->assertSessionHasErrors('name');

    expect(Reason::count())->toBe(1);
});

it('allows the same reason name in a different context', function () {
    Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_adjustment', 'name' => 'Other']);

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('reasons.store'), [
            'context' => 'inventory.stock_transfer',
            'name' => 'Other',
        ])
        ->assertRedirect();

    expect(Reason::count())->toBe(2);
});

it('blocks a user without inventory.catalog.manage from viewing or managing reasons', function () {
    $reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_adjustment', 'name' => 'Damaged']);

    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('reasons.index'))
        ->assertForbidden();

    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('reasons.store'), ['context' => 'inventory.stock_adjustment', 'name' => 'New'])
        ->assertForbidden();

    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch(route('reasons.toggle-active', $reason))
        ->assertForbidden();
});

it('does not leak reasons across businesses', function () {
    Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_adjustment', 'name' => 'Damaged']);

    $otherBusiness = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);

    app(BusinessContext::class)->set($otherBusiness->id);
    expect(Reason::count())->toBe(0);
});

// Add and rename are a small popup over the lists since 14 Oct 2026 (each list
// had its own add box above it, and Rename turned the row into an input). The
// page carries what the popup draws, and the popup saves through the same routes.
it('carries what the reason popup needs on the list, and saves through the same routes', function () {
    $as = fn () => $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id]);

    $as()->post(route('reasons.store'), ['context' => 'inventory.stock_transfer', 'name' => 'Restock'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $reason = Reason::sole();

    // The rename popup sends the list along with the name; the list stays put.
    $as()->put(route('reasons.update', $reason), ['context' => 'inventory.stock_adjustment', 'name' => 'Restock shelf'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($reason->fresh())->name->toBe('Restock shelf')->context->toBe('inventory.stock_transfer');

    $as()->get(route('reasons.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/reasons')
            ->where('contexts', [
                'inventory.stock_adjustment' => 'Stock adjustment',
                'inventory.stock_transfer' => 'Stock transfer',
                // Edit, delete and correct, Phase 0 (17 Oct 2026): one list per verb.
                'correction' => 'Correct a record',
                'reversal' => 'Reverse a record',
                'cancellation' => 'Cancel a record',
                'withdrawal' => 'Withdraw a request',
                'deletion' => 'Delete a draft',
                'deactivation' => 'Switch a record off',
                'disposal' => 'Dispose of an asset',
                'restore' => 'Restore from the Recycle bin',
                'fix_details' => 'Fix details',
            ])
            ->where('reasons.0.id', $reason->id)
            ->where('reasons.0.context', 'inventory.stock_transfer')
            ->where('reasons.0.name', 'Restock shelf'));
});
