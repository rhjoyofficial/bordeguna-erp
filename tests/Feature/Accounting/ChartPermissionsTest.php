<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\ChartPermissions;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The chart key split of 26 Sep 2026 (ledger-heads plan D1, D2).
 *
 * Joy's accountant must be able to add a ledger head, and the heading it needs,
 * from inside a voucher, without being able to re-parent the chart, touch Bank
 * & cash, or switch VAT on. So `accounting.chart.manage` became six keys, the
 * old key still implies all six, and the quick dialog can make a sub-heading.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Split Co', 'slug' => 'split-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (array_merge(['accounting.view', ChartPermissions::LEGACY], ChartPermissions::all()) as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->userWith = function (array $permissions): User {
        static $n = 0;
        $role = Role::firstOrCreate(['name' => 'R'.(++$n), 'business_id' => $this->business->id, 'guard_name' => 'web']);
        $role->givePermissionTo(array_merge(['accounting.view'], $permissions));
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->seed(ChartOfAccountsSeeder::class);
});

it('lets the old all-in-one key do everything the six new keys do', function () {
    $legacy = ($this->userWith)([ChartPermissions::LEGACY]);

    foreach (ChartPermissions::all() as $ability) {
        expect($legacy->can($ability))->toBeTrue($ability);
    }

    $office = Account::where('code', '6050')->firstOrFail();

    $this->actingAs($legacy)
        ->post('/accounting/accounts', ['code' => '6065', 'name' => 'Legacy Head', 'type' => 'expense', 'parent_id' => $office->id, 'is_postable' => true, 'is_active' => true])
        ->assertSessionHasNoErrors();
    $this->actingAs($legacy)->get('/accounting/vat-settings')->assertOk();
});

it('gives a head creator a head and a sub-heading under an existing heading, but never a root', function () {
    $creator = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $operating = Account::where('code', '6000')->firstOrFail();

    // A sub-heading under Operating Expenses.
    $this->actingAs($creator)
        ->post('/accounting/accounts', ['code' => '6900', 'name' => 'Field Costs', 'type' => 'expense', 'parent_id' => $operating->id, 'is_postable' => false, 'is_active' => true])
        ->assertSessionHasNoErrors();
    $field = Account::where('code', '6900')->firstOrFail();
    expect($field->is_postable)->toBeFalse()->and($field->parent_id)->toBe($operating->id);

    // A head under it.
    $this->actingAs($creator)
        ->post('/accounting/accounts', ['code' => '6901', 'name' => 'Route Fuel', 'type' => 'expense', 'parent_id' => $field->id, 'is_postable' => true, 'is_active' => true])
        ->assertSessionHasNoErrors();

    // A root heading is structure: refused with a message, and nothing saved.
    $this->actingAs($creator)
        ->from('/accounting/accounts')
        ->post('/accounting/accounts', ['code' => '7000', 'name' => 'Other Stuff', 'type' => 'expense', 'parent_id' => null, 'is_postable' => false, 'is_active' => true])
        ->assertSessionHasErrors('parent_id');
    expect(Account::where('code', '7000')->exists())->toBeFalse();

    // And the creator holds none of the other doors.
    $this->actingAs($creator)->put('/accounting/accounts/'.$field->id, ['code' => '6900', 'name' => 'Renamed', 'type' => 'expense', 'parent_id' => $operating->id, 'is_postable' => false, 'is_active' => true])->assertForbidden();
    $this->actingAs($creator)->delete('/accounting/accounts/'.$field->id)->assertForbidden();
    $this->actingAs($creator)->get('/accounting/vat-settings')->assertForbidden();
    $this->actingAs($creator)->post('/accounting/money-accounts', ['name' => 'X', 'type' => 'cash'])->assertForbidden();
});

it('lets a head updater rename and retire a head, but not move, re-type or regroup it', function () {
    $updater = ($this->userWith)([ChartPermissions::HEAD_UPDATE]);
    $rent = Account::where('code', '6051')->firstOrFail();
    $office = Account::where('code', '6050')->firstOrFail();
    $travel = Account::where('code', '6010')->firstOrFail();
    $base = ['code' => '6051', 'type' => 'expense', 'parent_id' => $office->id, 'is_postable' => true, 'is_active' => true];

    $this->actingAs($updater)
        ->put('/accounting/accounts/'.$rent->id, ['name' => 'Office Rent (Adabor)', 'note' => 'Monthly'] + $base)
        ->assertSessionHasNoErrors();
    expect($rent->fresh()->name)->toBe('Office Rent (Adabor)');

    $this->actingAs($updater)
        ->put('/accounting/accounts/'.$rent->id, ['name' => 'Office Rent (Adabor)', 'is_active' => false] + $base)
        ->assertSessionHasNoErrors();
    expect($rent->fresh()->is_active)->toBeFalse();

    // Re-parent: structure.
    $this->actingAs($updater)->from('/accounting/accounts')
        ->put('/accounting/accounts/'.$rent->id, ['name' => 'Office Rent (Adabor)', 'parent_id' => $travel->id] + $base)
        ->assertSessionHasErrors('parent_id');
    expect($rent->fresh()->parent_id)->toBe($office->id);

    // Head to heading: structure.
    $this->actingAs($updater)->from('/accounting/accounts')
        ->put('/accounting/accounts/'.$rent->id, ['name' => 'Office Rent (Adabor)', 'is_postable' => false] + $base)
        ->assertSessionHasErrors('parent_id');

    // Retiring a HEADING hides a branch: structure.
    $this->actingAs($updater)->from('/accounting/accounts')
        ->put('/accounting/accounts/'.$office->id, ['code' => '6050', 'name' => $office->name, 'type' => 'expense', 'parent_id' => $office->parent_id, 'is_postable' => false, 'is_active' => false])
        ->assertSessionHasErrors('parent_id');
    expect($office->fresh()->is_active)->toBeTrue();

    // The structure key opens all of it.
    $owner = ($this->userWith)([ChartPermissions::HEAD_UPDATE, ChartPermissions::STRUCTURE]);
    $this->actingAs($owner)
        ->put('/accounting/accounts/'.$rent->id, ['name' => 'Office Rent (Adabor)', 'parent_id' => $travel->id] + $base)
        ->assertSessionHasNoErrors();
    expect($rent->fresh()->parent_id)->toBe($travel->id);
});

it('needs the delete key to delete, and the VAT and money keys for their own screens', function () {
    $deleter = ($this->userWith)([ChartPermissions::HEAD_DELETE]);
    $office = Account::where('code', '6050')->firstOrFail();
    $spare = Account::create(['code' => '6066', 'name' => 'Spare', 'type' => 'expense', 'parent_id' => $office->id, 'is_postable' => true, 'is_active' => true]);

    $this->actingAs($deleter)->delete('/accounting/accounts/'.$spare->id)->assertSessionHas('success');
    expect(Account::find($spare->id))->toBeNull();
    $this->actingAs($deleter)->get('/accounting/vat-settings')->assertForbidden();

    $vat = ($this->userWith)([ChartPermissions::VAT]);
    $this->actingAs($vat)->get('/accounting/vat-settings')->assertOk();
    $this->actingAs($vat)->post('/accounting/accounts', ['code' => '6067', 'name' => 'No', 'type' => 'expense', 'parent_id' => $office->id, 'is_postable' => true, 'is_active' => true])->assertForbidden();

    $money = ($this->userWith)([ChartPermissions::MONEY_ACCOUNTS]);
    $this->actingAs($money)->post('/accounting/money-accounts', ['name' => 'Drawer', 'type' => 'cash', 'is_active' => true])->assertSessionHasNoErrors();
    $this->actingAs($money)->get('/accounting/vat-settings')->assertForbidden();
});

it('makes a sub-heading and the head under it from the quick dialog in one request', function () {
    $creator = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $operating = Account::where('code', '6000')->firstOrFail();

    $options = $this->actingAs($creator)->getJson('/accounting/accounts/quick/options?type=expense')->assertOk()->json('headings');
    $suggested = collect($options)->firstWhere('id', $operating->id)['suggested_heading_code'];
    // Every band of ten under 6000 up to 6812 is in use; the first free band is 6820.
    expect($suggested)->toBe('6820');

    $this->actingAs($creator)
        ->postJson('/accounting/accounts/quick', [
            'code' => '6821', 'name' => 'Route Fuel', 'type' => 'expense',
            'new_heading' => ['parent_id' => $operating->id, 'code' => '6820', 'name' => 'Field Costs'],
        ])
        ->assertCreated()
        ->assertJson(['code' => '6821', 'label' => '6821 - Route Fuel']);

    $heading = Account::where('code', '6820')->firstOrFail();
    $head = Account::where('code', '6821')->firstOrFail();
    expect($heading->is_postable)->toBeFalse()
        ->and($heading->parent_id)->toBe($operating->id)
        ->and($heading->type->value)->toBe('expense')
        ->and($head->is_postable)->toBeTrue()
        ->and($head->parent_id)->toBe($heading->id);
});

it('refuses a quick sub-heading at the root, under a money group, under a postable head, or of another type, and saves nothing', function () {
    $creator = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $bank = Account::where('code', '1120')->firstOrFail();
    $rent = Account::where('code', '6051')->firstOrFail();
    $assets = Account::where('code', '1100')->firstOrFail();

    $attempt = fn (array $heading) => $this->actingAs($creator)->postJson('/accounting/accounts/quick', [
        'code' => '6831', 'name' => 'Head', 'type' => 'expense', 'new_heading' => ['code' => '6830', 'name' => 'Heading'] + $heading,
    ]);

    $attempt([])->assertStatus(422)->assertJsonValidationErrors('new_heading.parent_id');
    $attempt(['parent_id' => $bank->id])->assertStatus(422)->assertJsonValidationErrors('new_heading.parent_id');
    $attempt(['parent_id' => $rent->id])->assertStatus(422)->assertJsonValidationErrors('new_heading.parent_id');
    $attempt(['parent_id' => $assets->id])->assertStatus(422)->assertJsonValidationErrors('parent_id');

    expect(Account::whereIn('code', ['6830', '6831'])->exists())->toBeFalse();
});

it('seeds Owner with all six keys and Finance with head create and update only', function () {
    foreach (['Owner', 'Finance'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    $this->seed(AccountingPermissionsSeeder::class);

    $owner = Role::where('name', 'Owner')->where('business_id', $this->business->id)->firstOrFail();
    $finance = Role::where('name', 'Finance')->where('business_id', $this->business->id)->firstOrFail();

    foreach (ChartPermissions::all() as $ability) {
        expect($owner->hasPermissionTo($ability))->toBeTrue($ability);
    }

    expect($finance->hasPermissionTo(ChartPermissions::HEAD_CREATE))->toBeTrue()
        ->and($finance->hasPermissionTo(ChartPermissions::HEAD_UPDATE))->toBeTrue()
        ->and($finance->hasPermissionTo(ChartPermissions::HEAD_DELETE))->toBeFalse()
        ->and($finance->hasPermissionTo(ChartPermissions::STRUCTURE))->toBeFalse()
        ->and($finance->hasPermissionTo(ChartPermissions::MONEY_ACCOUNTS))->toBeFalse()
        ->and($finance->hasPermissionTo(ChartPermissions::VAT))->toBeFalse()
        ->and($finance->hasPermissionTo(ChartPermissions::LEGACY))->toBeFalse();
});
