<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\AccountCodes;
use App\Modules\Accounting\Support\ChartPermissions;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
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
