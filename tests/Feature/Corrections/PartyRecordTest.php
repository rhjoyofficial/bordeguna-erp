<?php

use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\Recycle\RecycleBin;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesWorld;

/**
 * ⭐ Corrections follow-up (B13, 24 Oct 2026; FINAL-PLAN 3.2 "Customer: Delete
 * to the bin if never used. Reactivate.", 3.3 "Supplier", the same): on the
 * screen, with a reason for Delete, refused in one sentence when used.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->w = $this->s->w;

    $permissions = ['sales.customers.manage', 'sales.reports.view', 'purchasing.suppliers.manage', 'purchasing.reports.view'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    Role::where('name', 'Owner')->where('business_id', $this->w->business->id)->firstOrFail()->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->fresh = Customer::query()->create(['business_id' => $this->w->business->id, 'name' => 'Never Used Store', 'type' => 'dealer', 'phone' => '01999000111']);
    $this->supplier = Supplier::query()->create(['business_id' => $this->w->business->id, 'code' => 'SUP-77', 'name' => 'Typed Twice Ltd', 'is_active' => true]);
});

afterEach(fn () => Carbon::setTestNow());

it('reactivates a switched-off customer and supplier on screen, logged', function () {
    $this->fresh->update(['is_active' => false]);
    $this->supplier->update(['is_active' => false]);

    $this->actingAs($this->w->owner)->post(route('sales.customers.reactivate', $this->fresh))->assertSessionHasNoErrors();
    $this->actingAs($this->w->owner)->post(route('purchasing.suppliers.reactivate', $this->supplier))->assertSessionHasNoErrors();

    expect($this->fresh->fresh()->is_active)->toBeTrue()
        ->and($this->supplier->fresh()->is_active)->toBeTrue()
        ->and(Activity::query()->where('log_name', 'corrections')->where('event', 'reactivated')->count())->toBe(2);

    // Already active: refused in words.
    $this->actingAs($this->w->owner)->post(route('sales.customers.reactivate', $this->fresh))->assertSessionHasErrors('customer');
});

it('deletes a never-used customer to the Recycle bin with a reason; the Owner restores it', function () {
    $this->actingAs($this->w->owner)->post(route('sales.customers.bin', $this->fresh), ['reason_text' => 'no'])->assertSessionHasErrors('reason_text');

    $this->actingAs($this->w->owner)->post(route('sales.customers.bin', $this->fresh), ['reason_text' => 'Typed by mistake'])->assertSessionHasNoErrors();

    $trashed = Customer::onlyTrashed()->findOrFail($this->fresh->id);
    expect($trashed->delete_reason)->toBe('Typed by mistake')
        ->and((int) $trashed->deleted_by)->toBe($this->w->owner->id)
        ->and(Customer::query()->whereKey($this->fresh->id)->exists())->toBeFalse();

    app(RecycleBin::class)->restore('customer', $this->fresh->id, $this->w->owner, new ActionReason(null, 'Needed after all'));
    expect(Customer::query()->whereKey($this->fresh->id)->exists())->toBeTrue();
});

it('refuses to delete a customer that was used, a system customer, and a used supplier, each with a sentence', function () {
    $used = $this->s->customer;
    $this->s->order('2', '850');

    $this->actingAs($this->w->owner)->post(route('sales.customers.bin', $used), ['reason_text' => 'Typed by mistake'])
        ->assertSessionHasErrors('reason_text');
    expect(session('errors')->first('reason_text'))->toContain('Karim Traders is used (')->toContain('sales orders')->toContain('Deactivate it instead.');

    $walkIn = Customer::query()->create(['business_id' => $this->w->business->id, 'name' => 'Walk-in', 'code' => 'WALK-IN', 'type' => 'retail', 'is_system' => true]);
    expect($walkIn->whyNotRemovable())->toContain('made by the system');

    $this->actingAs($this->w->owner)->post(route('purchasing.suppliers.bin', $this->supplier), ['reason_text' => 'Typed by mistake'])->assertSessionHasNoErrors();
    expect(Supplier::onlyTrashed()->whereKey($this->supplier->id)->exists())->toBeTrue();
});

it('the customer page carries Reactivate and Delete, greyed with the reason when used', function () {
    $this->s->order('2', '850');

    $this->actingAs($this->w->owner)->get(route('sales.customers.show', $this->s->customer))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('record.may_manage', true)
            ->where('record.delete_blocked', fn ($why) => str_contains((string) $why, 'Karim Traders is used (')));

    $this->actingAs($this->w->owner)->get(route('sales.customers.show', $this->fresh))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('record.delete_blocked', null));
});
