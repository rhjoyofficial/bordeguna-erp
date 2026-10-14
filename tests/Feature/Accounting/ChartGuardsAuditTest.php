<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\ChartPermissions;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The chart guards the 14 Oct 2026 accounting audit found missing.
 *
 * ACC-001: a person's own head (EntityLedgers) was offered and accepted as a
 * heading, so a postable head could sit under "1140-317 Rahim" and hold a raw
 * receivable no invoice explained.
 * ACC-002: a head updater could switch off a head the engine posts to, which
 * stopped that posting in all four businesses.
 * ACC-009: the quick head store answered a 500 when `code` was left out.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Guard Co', 'slug' => 'guard-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (array_merge(['accounting.view', ChartPermissions::LEGACY], ChartPermissions::all()) as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->userWith = function (array $permissions): User {
        static $n = 0;
        $role = Role::firstOrCreate(['name' => 'G'.(++$n), 'business_id' => $this->business->id, 'guard_name' => 'web']);
        $role->givePermissionTo(array_merge(['accounting.view'], $permissions));
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->seed(ChartOfAccountsSeeder::class);
    app(BusinessContext::class)->set($this->business->id);

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'code' => 'RAHIM']);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Traders']);
    $this->employee = Employee::create([
        'business_id' => $this->business->id, 'employee_code' => 'BDG-000099',
        'first_name' => 'Musa', 'last_name' => 'Islam',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
});

it('never offers a customer, supplier or employee head as a heading in the quick dialog (ACC-001)', function () {
    $finance = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $people = [$this->customer->fresh()->account_id, $this->supplier->fresh()->account_id, $this->employee->fresh()->account_id];

    expect(array_filter($people))->toHaveCount(3);

    foreach (['asset', 'liability'] as $type) {
        $ids = collect($this->actingAs($finance)->getJson('/accounting/accounts/quick/options?type='.$type)->assertOk()->json('headings'))->pluck('id');

        foreach ($people as $headId) {
            expect($ids)->not->toContain($headId);
        }
    }
});

it('refuses a quick head or a chart head under a person\'s own head (ACC-001)', function () {
    $finance = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $rahimHead = $this->customer->fresh()->account_id;

    $this->actingAs($finance)
        ->postJson('/accounting/accounts/quick', ['code' => '', 'name' => 'Rahim Extra', 'type' => 'asset', 'parent_id' => $rahimHead])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id');

    $this->actingAs($finance)
        ->from('/accounting/accounts')
        ->post('/accounting/accounts', ['code' => '', 'name' => 'Rahim Extra', 'type' => 'asset', 'parent_id' => $rahimHead, 'is_postable' => true, 'is_active' => true])
        ->assertSessionHasErrors('parent_id');

    expect(Account::where('name', 'Rahim Extra')->exists())->toBeFalse();
});

it('refuses on a voucher line a head that already sits under a person\'s own head (ACC-001)', function () {
    // As one made before the guard existed.
    $stray = Account::create([
        'code' => '1140-X1', 'name' => 'Stray under Rahim', 'type' => AccountType::Asset,
        'parent_id' => $this->customer->fresh()->account_id, 'is_postable' => true, 'is_active' => true,
    ]);

    expect(VoucherAccounts::refusal($stray, Carbon::today(), VoucherType::Receipt))->toContain('own ledger');
});

it('refuses a head updater switching off a system head, and anyone switching off a mapped head (ACC-002)', function () {
    $finance = ($this->userWith)([ChartPermissions::HEAD_UPDATE]);
    $owner = ($this->userWith)([ChartPermissions::LEGACY]);
    $receivable = Account::bySlug('accounts_receivable');
    $payload = fn (Account $a) => [
        'code' => $a->code, 'name' => $a->name, 'type' => $a->type->value,
        'parent_id' => $a->parent_id, 'is_postable' => true, 'is_active' => false,
    ];

    $this->actingAs($finance)->from('/accounting/accounts')
        ->put('/accounting/accounts/'.$receivable->id, $payload($receivable))
        ->assertSessionHasErrors('is_active');
    expect($receivable->fresh()->is_active)->toBeTrue();

    // A mapped head (the engine posts there by mapping) stays on for the Owner too.
    AccountMapping::assign('sales.accounts_receivable', $receivable, Carbon::parse('2026-01-01'));

    $this->actingAs($owner)->from('/accounting/accounts')
        ->put('/accounting/accounts/'.$receivable->id, $payload($receivable))
        ->assertSessionHasErrors('is_active');
    expect($receivable->fresh()->is_active)->toBeTrue();

    // An ordinary unmapped head can still be retired by the head updater.
    $rent = Account::where('code', '6051')->firstOrFail();
    $this->actingAs($finance)
        ->put('/accounting/accounts/'.$rent->id, $payload($rent))
        ->assertSessionHasNoErrors();
    expect($rent->fresh()->is_active)->toBeFalse();
});

it('numbers a quick head whose code is left out instead of answering a 500 (ACC-009)', function () {
    $finance = ($this->userWith)([ChartPermissions::HEAD_CREATE]);
    $office = Account::where('code', '6050')->firstOrFail();

    $this->actingAs($finance)
        ->postJson('/accounting/accounts/quick', ['name' => 'No Code Head', 'type' => 'expense', 'parent_id' => $office->id])
        ->assertCreated();

    $this->actingAs($finance)
        ->postJson('/accounting/accounts/quick', ['name' => 'Under New', 'type' => 'expense', 'new_heading' => ['parent_id' => Account::where('code', '6000')->value('id'), 'name' => 'Fresh Group']])
        ->assertCreated();

    expect(Account::where('name', 'No Code Head')->value('code'))->not->toBeNull();
});
