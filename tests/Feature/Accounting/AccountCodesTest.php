<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\AccountCodes;
use App\Modules\Accounting\Support\ChartPermissions;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Automatic account codes (Joy, 12 Oct 2026: "each time we need to add the code
 * while creating the head, it's very painful"). The chart proposes the next
 * free code from the chosen parent; the person may still change it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Codes Co', 'slug' => 'codes-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (array_merge(['accounting.view', ChartPermissions::LEGACY], ChartPermissions::all()) as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->userWith = function (array $permissions): User {
        static $n = 0;
        $role = Role::firstOrCreate(['name' => 'C'.(++$n), 'business_id' => $this->business->id, 'guard_name' => 'web']);
        $role->givePermissionTo(array_merge(['accounting.view'], $permissions));
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->seed(ChartOfAccountsSeeder::class);

    $this->next = fn (string $parentCode) => AccountCodes::nextUnder(
        Account::where('code', $parentCode)->firstOrFail(),
        Account::where('code', $parentCode)->firstOrFail()->type,
    );
});

it('steps by the hundred under a top heading and uses the gap when the top is full', function () {
    // 4100, 4200, 4300 and 4900 (Other Income, 4910-4990 under it): nothing
    // fits above 4900, so the first free hundred, 4400, is proposed.
    expect(($this->next)('4000'))->toBe('4400')
        ->and(($this->next)('5000'))->toBe('5500');
});

it('keeps to the spacing the sub-headings use', function () {
    // 6010, 6050, 6090 ... 6800 are mostly forty apart, so the next sits forty
    // after the last one, 6800.
    expect(($this->next)('6000'))->toBe('6840');
});

it('numbers heads one by one and never hands out a code ending in 0', function () {
    expect(($this->next)('6050'))->toBe('6065')
        // 6011..6019 taken; 6020 would read as a heading, so 6021.
        ->and(($this->next)('6010'))->toBe('6021')
        // 6241..6289: no room at the top before 6290, so the first gap, 6250
        // skipped for its 0.
        ->and(($this->next)('6240'))->toBe('6255');
});

it('follows five digit children under a money group', function () {
    expect(($this->next)('1120'))->toBe('11202')
        ->and(($this->next)('1110'))->toBe('11102');
});

it('starts an empty heading at its own pattern', function () {
    $empty = Account::create(['code' => '6900', 'name' => 'Empty', 'type' => AccountType::Expense, 'parent_id' => Account::where('code', '6000')->value('id'), 'is_postable' => false, 'is_active' => true]);
    $odd = Account::create(['code' => '6905', 'name' => 'Odd', 'type' => AccountType::Expense, 'parent_id' => Account::where('code', '6000')->value('id'), 'is_postable' => false, 'is_active' => true]);

    // 6900 ends in two zeros, so its children step by ten; 6905 does not end
    // in 0, so its children are one digit longer.
    expect(AccountCodes::nextUnder($empty, AccountType::Expense))->toBe('6901')
        ->and(AccountCodes::nextUnder($odd, AccountType::Expense))->toBe('69051');
});

it('skips a code somebody already typed elsewhere in the chart', function () {
    // 4400 typed by hand under Other Income: the 4400 band is no longer free.
    Account::create(['code' => '4400', 'name' => 'Stray', 'type' => AccountType::Income, 'parent_id' => Account::where('code', '4900')->value('id'), 'is_postable' => true, 'is_active' => true]);

    expect(($this->next)('4000'))->toBe('4500');
});

it('ignores suffixed codes for the pattern', function () {
    Account::create(['code' => '2110-5', 'name' => 'Suffixed', 'type' => AccountType::Liability, 'parent_id' => Account::where('code', '2100')->value('id'), 'is_postable' => true, 'is_active' => true]);

    $code = ($this->next)('2100');

    expect($code)->toMatch('/^21\d\d$/')->and(Account::where('code', $code)->exists())->toBeFalse();
});

it('proposes a free thousand, in the type\'s own digits, at the top level', function () {
    expect(AccountCodes::nextUnder(null, AccountType::Expense))->toBe('7000')
        // 1000-1999 belongs to Assets as a whole, so a new asset root gets a free hundred.
        ->and(AccountCodes::nextUnder(null, AccountType::Asset))->toBe('1300');
});

it('fills a blank code on store from the parent', function () {
    $user = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $office = Account::where('code', '6050')->firstOrFail();

    $this->actingAs($user)->post('/accounting/accounts', [
        'code' => '', 'name' => 'Office Security', 'type' => 'expense', 'parent_id' => $office->id, 'is_postable' => true, 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect(Account::where('name', 'Office Security')->value('code'))->toBe('6065');
});

it('still takes the code the person typed', function () {
    $user = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $office = Account::where('code', '6050')->firstOrFail();

    $this->actingAs($user)->post('/accounting/accounts', [
        'code' => '6070', 'name' => 'Typed', 'type' => 'expense', 'parent_id' => $office->id, 'is_postable' => true, 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect(Account::where('name', 'Typed')->value('code'))->toBe('6070');
});

it('answers the next code to whoever may create a head, and nobody else', function () {
    $office = Account::where('code', '6050')->firstOrFail();

    $this->actingAs(($this->userWith)([ChartPermissions::HEAD_CREATE]))
        ->getJson('/accounting/accounts/next-code?type=expense&parent_id='.$office->id)
        ->assertOk()->assertExactJson(['code' => '6065']);

    $this->actingAs(($this->userWith)([]))
        ->getJson('/accounting/accounts/next-code?type=expense&parent_id='.$office->id)
        ->assertForbidden();
});

it('moves a typed duplicate to the next free code and says so', function () {
    $user = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $office = Account::where('code', '6050')->firstOrFail();

    $this->actingAs($user)->post('/accounting/accounts', [
        'code' => '6051', 'name' => 'Office Security', 'type' => 'expense', 'parent_id' => $office->id, 'is_postable' => true, 'is_active' => true,
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Account 6065 - Office Security created (6051 was taken, so the next free code was used).');

    expect(Account::where('code', '6051')->value('name'))->toBe('Office Rent');
});

it('still succeeds when another person takes the code between the check and the insert', function () {
    $user = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $office = Account::where('code', '6050')->firstOrFail();

    // The race, made certain: right after the store checked that 6065 is free,
    // and before its insert, a second person's 6065 is committed.
    $raced = false;
    DB::listen(function ($query) use (&$raced, $office) {
        if (! $raced && str_contains($query->sql, 'exists') && in_array('6065', $query->bindings, true)) {
            $raced = true;
            DB::table('accounts')->insert([
                'code' => '6065', 'name' => 'Theirs', 'type' => 'expense', 'parent_id' => $office->id,
                'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $this->actingAs($user)->post('/accounting/accounts', [
        'code' => '6065', 'name' => 'Mine', 'type' => 'expense', 'parent_id' => $office->id, 'is_postable' => true, 'is_active' => true,
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Account 6066 - Mine created (6065 was taken, so the next free code was used).');

    expect($raced)->toBeTrue()
        ->and(Account::where('code', '6065')->value('name'))->toBe('Theirs')
        ->and(Account::where('name', 'Mine')->value('code'))->toBe('6066');
});

it('moves taken codes in the picker dialog too, heading and head alike', function () {
    $user = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $operating = Account::where('code', '6000')->firstOrFail();

    $response = $this->actingAs($user)->postJson('/accounting/accounts/quick', [
        'code' => '6051', 'name' => 'Field Fuel', 'type' => 'expense',
        'new_heading' => ['parent_id' => $operating->id, 'code' => '6050', 'name' => 'Field Costs'],
    ])->assertCreated();

    $heading = Account::where('name', 'Field Costs')->firstOrFail();

    expect($heading->code)->toBe('6840')
        ->and($response->json('code'))->toBe('6841')
        ->and($response->json('message'))->toContain('6050 and 6051 were taken');
});

it('still refuses renaming an account to a code that is taken', function () {
    $user = ($this->userWith)([ChartPermissions::HEAD_CREATE, ChartPermissions::HEAD_UPDATE]);
    $rent = Account::where('code', '6051')->firstOrFail();

    $this->actingAs($user)->put('/accounting/accounts/'.$rent->id, [
        'code' => '6052', 'name' => $rent->name, 'type' => 'expense', 'parent_id' => $rent->parent_id, 'is_postable' => true, 'is_active' => true,
    ])->assertSessionHasErrors('code');

    expect($rent->fresh()->code)->toBe('6051');
});
