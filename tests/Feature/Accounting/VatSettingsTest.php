<?php

use App\Models\User;
use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The VAT switch (C-VAT-7): Owner only, from a date, audited, and it refuses to
 * be turned on in a state where the first invoice would be refused or wrong.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Switch Co', 'slug' => 'switch-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['accounting.view', 'accounting.chart.manage'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $owner = Role::create(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $owner->givePermissionTo(['accounting.view', 'accounting.chart.manage']);
    $finance = Role::create(['name' => 'Finance', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $finance->givePermissionTo(['accounting.view']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->accountant = User::factory()->create();
    $this->accountant->businesses()->attach($this->business->id);
    $this->accountant->assignRole('Finance');

    $this->standard = TaxClass::create(['business_id' => $this->business->id, 'code' => 'STANDARD', 'name' => 'Standard']);
    TaxClass::create(['business_id' => $this->business->id, 'code' => 'ZERO', 'name' => 'Zero-rated']);

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    $this->on = ['regime' => 'vat_standard', 'vat_effective_from' => '2026-10-01', 'vat_bin' => '003457663-0808', 'prices_include_tax' => true];
});

it('shows the screen to the Owner, with VAT off', function () {
    ($this->as)($this->owner)->get('/accounting/vat-settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounting/vat-settings/index')
            ->where('settings.regime', 'not_registered')
            ->where('settings.vat_enabled', false)
            ->has('regimes', 3));
});

it('keeps the switch away from anyone who cannot manage the chart', function () {
    ($this->as)($this->accountant)->get('/accounting/vat-settings')->assertForbidden();
    ($this->as)($this->accountant)->put('/accounting/vat-settings', $this->on)->assertForbidden();

    expect($this->business->fresh()->vat_enabled)->toBeFalse();
});

it('will not switch VAT on before a standard rate exists', function () {
    ($this->as)($this->owner)->put('/accounting/vat-settings', $this->on)->assertSessionHasErrors('regime');

    expect($this->business->fresh()->vat_enabled)->toBeFalse();
});

it('will not switch on without a BIN', function () {
    TaxRate::create(['business_id' => $this->business->id, 'tax_class_id' => $this->standard->id, 'rate' => '15', 'effective_from' => '2026-01-01']);

    ($this->as)($this->owner)->put('/accounting/vat-settings', ['vat_bin' => ''] + $this->on)->assertSessionHasErrors('vat_bin');
});

it('switches VAT on from a date, and writes who did it to the audit log', function () {
    ($this->as)($this->owner)->post('/accounting/vat-settings/rates', [
        'tax_class_id' => $this->standard->id, 'rate' => '15', 'effective_from' => '2026-01-01',
    ])->assertSessionHasNoErrors();

    ($this->as)($this->owner)->put('/accounting/vat-settings', $this->on)->assertSessionHasNoErrors();

    $business = $this->business->fresh();

    expect($business->vat_enabled)->toBeTrue()
        ->and($business->vat_regime)->toBe('vat_standard')
        ->and($business->vat_effective_from->toDateString())->toBe('2026-10-01')
        ->and($business->vat_bin)->toBe('003457663-0808')
        ->and($business->vatAppliesOn(now()->parse('2026-09-30')))->toBeFalse()
        ->and($business->vatAppliesOn(now()->parse('2026-10-01')))->toBeTrue();

    $entry = Activity::where('log_name', 'vat')->latest('id')->first();
    expect($entry)->not->toBeNull()->and($entry->causer_id)->toBe($this->owner->id);
});

it('keeps the document engine off under turnover tax, which adds nothing to an invoice', function () {
    ($this->as)($this->owner)->get('/accounting/vat-settings');
    $turnover = TaxClass::where('code', 'TURNOVER')->sole();
    TaxRate::create(['business_id' => $this->business->id, 'tax_class_id' => $turnover->id, 'rate' => '4', 'effective_from' => '2026-01-01']);

    ($this->as)($this->owner)->put('/accounting/vat-settings', ['regime' => 'turnover_tax'] + $this->on)->assertSessionHasNoErrors();

    expect($this->business->fresh()->vat_enabled)->toBeFalse()
        ->and($this->business->fresh()->vat_regime)->toBe('turnover_tax');
});

it('switches off again, and clears the start date', function () {
    TaxRate::create(['business_id' => $this->business->id, 'tax_class_id' => $this->standard->id, 'rate' => '15', 'effective_from' => '2026-01-01']);
    ($this->as)($this->owner)->put('/accounting/vat-settings', $this->on);

    ($this->as)($this->owner)->put('/accounting/vat-settings', ['regime' => 'not_registered', 'prices_include_tax' => true])->assertSessionHasNoErrors();

    expect($this->business->fresh()->vat_enabled)->toBeFalse()
        ->and($this->business->fresh()->vat_effective_from)->toBeNull();
});

it('refuses a rate on a class that is zero by definition', function () {
    $zero = TaxClass::where('code', 'ZERO')->sole();

    ($this->as)($this->owner)->post('/accounting/vat-settings/rates', [
        'tax_class_id' => $zero->id, 'rate' => '5', 'effective_from' => '2026-01-01',
    ])->assertSessionHasErrors('tax_class_id');
});

it('never accepts another business\'s tax class', function () {
    $other = Business::create(['name' => 'Other', 'slug' => 'other']);
    $foreign = TaxClass::withoutGlobalScopes()->create(['business_id' => $other->id, 'code' => 'STANDARD', 'name' => 'Standard']);

    ($this->as)($this->owner)->post('/accounting/vat-settings/rates', [
        'tax_class_id' => $foreign->id, 'rate' => '15', 'effective_from' => '2026-01-01',
    ])->assertSessionHasErrors('tax_class_id');
});
