<?php

use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 6478 Interest Expense (6 Oct 2026): the interest paid on 2210 Bank Loans had
 * no head. It is a finance cost under 6470 Financial and Payment Charges.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Interest Co', 'slug' => 'interest-co']);
    TenantScope::enter($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->migration = require base_path('app/Modules/Accounting/database/migrations/2026_10_06_100000_interest_expense_has_a_head.php');
});

it('plants 6478 Interest Expense as a postable finance cost under 6470', function () {
    $account = Account::bySlug('interest_expense');

    expect($account)->not->toBeNull()
        ->and($account->code)->toBe('6478')
        ->and($account->type)->toBe(AccountType::Expense)
        ->and($account->is_postable)->toBeTrue()
        ->and($account->parent?->code)->toBe('6470');
});

it('adds the head to a live chart that lacks it, once, however often it runs', function () {
    DB::table('accounts')->where('code', '6478')->delete();

    $this->migration->up();
    $this->migration->up();

    expect(DB::table('accounts')->where('code', '6478')->count())->toBe(1)
        ->and(Account::bySlug('interest_expense')?->parent?->code)->toBe('6470');
});

it('is offered on a payment voucher like any other expense head', function () {
    $row = VoucherAccounts::catalogue(Carbon::today(), VoucherType::Payment)
        ->first(fn (array $row) => $row['account']->code === '6478');

    expect($row)->not->toBeNull()
        ->and($row['usable'])->toBeTrue();
});
