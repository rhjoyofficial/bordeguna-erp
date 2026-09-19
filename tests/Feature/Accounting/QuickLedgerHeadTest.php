<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A ledger head added from inside another form's picker (19 Sep 2026).
 *
 * The accountant was recording an expense, found the head missing, and had to
 * abandon the form to add it. The picker now offers "New ledger head"; these
 * are the two JSON endpoints behind it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Ledger Co', 'slug' => 'ledger-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['accounting.view', 'accounting.chart.manage'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $keeper = Role::firstOrCreate(['name' => 'Bookkeeper', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $keeper->givePermissionTo(['accounting.view', 'accounting.chart.manage']);

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    $this->keeper = User::factory()->create();
    $this->keeper->businesses()->attach($this->business->id);
    $this->keeper->assignRole($keeper);

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($reader);

    $this->seed(ChartOfAccountsSeeder::class);
});

it('offers only the headings of the asked type, each with its next free code', function () {
    $headings = $this->actingAs($this->keeper)
        ->getJson('/accounting/accounts/quick/options?type=expense')
        ->assertOk()
        ->json('headings');

    $labels = collect($headings)->pluck('label');

    expect($labels)->toContain('6050 - Office and Administration')
        ->and($labels)->not->toContain('1100 - Current Assets')
        // 6051..6064 are taken by the master chart, so the next free is 6065.
        ->and(collect($headings)->firstWhere('label', '6050 - Office and Administration')['suggested_code'])->toBe('6065');
});

it('never suggests a code that belongs to the next heading band', function () {
    // 6010 Travel holds 6011..6019 and the next heading is 6050: the band has
    // room, so the suggestion must stay below 6050.
    $travel = collect($this->actingAs($this->keeper)->getJson('/accounting/accounts/quick/options?type=expense')->json('headings'))
        ->firstWhere('label', '6010 - Travel and Conveyance');

    expect((int) $travel['suggested_code'])->toBeGreaterThan(6019)->toBeLessThan(6050);
});

it('creates a postable, active head under the heading and hands it back', function () {
    $heading = Account::where('code', '6050')->firstOrFail();

    $this->actingAs($this->keeper)
        ->postJson('/accounting/accounts/quick', ['code' => '6065', 'name' => 'Office Security', 'type' => 'expense', 'parent_id' => $heading->id])
        ->assertCreated()
        ->assertJson(['code' => '6065', 'label' => '6065 - Office Security']);

    $account = Account::where('code', '6065')->firstOrFail();

    expect($account->is_postable)->toBeTrue()
        ->and($account->is_active)->toBeTrue()
        ->and($account->parent_id)->toBe($heading->id);
});

it('refuses a head whose type differs from its heading', function () {
    $assets = Account::where('code', '1100')->firstOrFail();

    $this->actingAs($this->keeper)
        ->postJson('/accounting/accounts/quick', ['code' => '6066', 'name' => 'Wrong Place', 'type' => 'expense', 'parent_id' => $assets->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_id');

    expect(Account::where('code', '6066')->exists())->toBeFalse();
});

it('refuses a code already in the chart, a missing heading, and a postable parent', function () {
    $heading = Account::where('code', '6050')->firstOrFail();
    $rent = Account::where('code', '6051')->firstOrFail();

    $this->actingAs($this->keeper)
        ->postJson('/accounting/accounts/quick', ['code' => '6051', 'name' => 'Duplicate', 'type' => 'expense', 'parent_id' => $heading->id])
        ->assertJsonValidationErrors('code');

    $this->actingAs($this->keeper)
        ->postJson('/accounting/accounts/quick', ['code' => '6067', 'name' => 'Orphan', 'type' => 'expense'])
        ->assertJsonValidationErrors('parent_id');

    // A head may only sit under a GROUP; under another postable head it would
    // make that head a heading nobody asked for.
    $this->actingAs($this->keeper)
        ->postJson('/accounting/accounts/quick', ['code' => '6068', 'name' => 'Under Rent', 'type' => 'expense', 'parent_id' => $rent->id])
        ->assertJsonValidationErrors('parent_id');
});

it('is closed to anyone who may not change the chart', function () {
    $heading = Account::where('code', '6050')->firstOrFail();

    $this->actingAs($this->reader)->getJson('/accounting/accounts/quick/options?type=expense')->assertForbidden();

    $this->actingAs($this->reader)
        ->postJson('/accounting/accounts/quick', ['code' => '6065', 'name' => 'Office Security', 'type' => 'expense', 'parent_id' => $heading->id])
        ->assertForbidden();

    expect(Account::where('code', '6065')->exists())->toBeFalse();
});
