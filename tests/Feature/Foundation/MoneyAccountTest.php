<?php

use App\Models\User;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1.1 of Accounting: where money actually is.
 *
 * ⚠⚠ There was no concept of a bank or cash account anywhere in the ERP - not in
 * Foundation, Purchasing or Payroll. Every payment event in the business walk
 * ended in a credit to an account that did not exist, which blocked Accounts
 * Payable, Accounts Receivable, the cash book and bank reconciliation at once.
 *
 * ⚠ A money account is an IDENTITY - "where money moved" - and deliberately
 * carries NO BALANCE. Its balance is a ledger balance and arrives with the
 * General Ledger, derived exactly as stock quantities are derived from
 * movements. Storing one here would create the second source of truth this
 * project spent four Inventory slices removing.
 *
 * See ACCOUNTING-ARCHITECTURE-REVIEW.md §11.1.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Money Co', 'slug' => 'money-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
});

it('holds a bank, a cash and a mobile money account side by side', function () {
    $bank = MoneyAccount::create([
        'name' => 'BRAC Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_number' => '1501202536477001',
        'bank_name' => 'BRAC Bank PLC',
        'branch' => 'Mirpur Section-1',
    ]);

    $cash = MoneyAccount::create(['name' => 'Shop cash box', 'type' => MoneyAccountType::Cash]);

    $mfs = MoneyAccount::create([
        'name' => 'bKash merchant',
        'type' => MoneyAccountType::Mfs,
        'provider' => 'bKash',
        'account_number' => '01581881265',
    ]);

    expect(MoneyAccount::count())->toBe(3)
        ->and($bank->type)->toBe(MoneyAccountType::Bank)
        ->and($cash->type)->toBe(MoneyAccountType::Cash)
        ->and($mfs->provider)->toBe('bKash');

    // ⚠ Cash needs nothing on file, which is exactly why it is the only type
    // that can never be wrong about how somebody was paid.
    expect($cash->account_number)->toBeNull();
});

it('carries no balance, because the ledger will own that', function () {
    // ⚠⚠ The one assertion in this file that is about what is ABSENT. If a
    // balance column ever appears here, there are two sources of truth for the
    // same number and the older one always wins the argument.
    $account = MoneyAccount::create(['name' => 'Cash', 'type' => MoneyAccountType::Cash]);

    expect(array_keys($account->getAttributes()))
        ->not->toContain('balance')
        ->not->toContain('current_balance')
        ->not->toContain('opening_balance');
});

it('keeps each business to its own accounts', function () {
    MoneyAccount::create(['name' => 'Ours', 'type' => MoneyAccountType::Cash]);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-money-co']);
    app(BusinessContext::class)->set($other->id);

    expect(MoneyAccount::count())->toBe(0);
});

it('can be deactivated without being deleted, because history refers to it', function () {
    $account = MoneyAccount::create(['name' => 'Old account', 'type' => MoneyAccountType::Bank]);

    $account->update(['is_active' => false]);

    expect(MoneyAccount::count())->toBe(1)
        ->and(MoneyAccount::active()->count())->toBe(0);
});
