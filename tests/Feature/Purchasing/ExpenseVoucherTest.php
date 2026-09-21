<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Rent, electricity, fuel, government fees - the spending that never sees a
 * purchase order.
 *
 * ⚠⚠ Probably the MAJORITY OF TRANSACTIONS BY COUNT in a business this size,
 * and until this slice there was no document for any of it. R4 of the
 * architecture review said so in June; the coverage matrix put a number on it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Spend Co', 'slug' => 'spend-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Office cash box',
        'type' => MoneyAccountType::Cash,
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $this->rent = Account::where('code', '6051')->first();
    $this->fuel = Account::bySlug('fuel_and_conveyance');

    $this->balanceOn = fn (string $code) => (int) JournalLine::where(
        'account_id', Account::where('code', $code)->first()->id
    )->sum('amount_minor');
});

it('records what was spent and takes it out of the account it came from', function () {
    $voucher = app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash,
        spentOn: '2026-08-03',
        lines: [
            ['account' => $this->rent, 'amount' => '18000.00', 'description' => 'August office rent'],
            ['account' => $this->fuel, 'amount' => '2500.00', 'description' => 'Delivery fuel'],
        ],
        createdBy: $this->user,
        paidTo: 'Uttar Adabor landlord',
    );

    expect($voucher->voucher_number)->toBe('EXP-2026-27-0001')
        ->and($voucher->total)->toBe('20500.0000')
        ->and($voucher->lines)->toHaveCount(2);

    // Two expenses debited, one credit to the cash box.
    expect(($this->balanceOn)('6051'))->toBe(1800000)
        ->and(($this->balanceOn)('6011'))->toBe(250000)
        ->and(($this->balanceOn)('11101'))->toBe(-2050000);

    $journal = Journal::first();

    expect($journal->balance()->isZero())->toBeTrue()
        ->and($journal->source->is($voucher))->toBeTrue()
        ->and($journal->lines)->toHaveCount(3);
});

it('numbers vouchers per fiscal year', function () {
    $first = app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash, spentOn: '2026-08-03',
        lines: [['account' => $this->rent, 'amount' => '100']], createdBy: $this->user,
    );
    $second = app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash, spentOn: '2026-08-03',
        lines: [['account' => $this->rent, 'amount' => '200']], createdBy: $this->user,
    );
    $earlier = app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash, spentOn: '2026-05-20',
        lines: [['account' => $this->rent, 'amount' => '300']], createdBy: $this->user,
    );

    // Bor de Guna's year runs 1 July to 30 June, so May 2026 belongs to the
    // previous year and keeps its own sequence.
    expect($first->voucher_number)->toBe('EXP-2026-27-0001')
        ->and($second->voucher_number)->toBe('EXP-2026-27-0002')
        ->and($earlier->voucher_number)->toBe('EXP-2025-26-0001');
});

it('refuses to spend into anything that is not an expense account', function () {
    // ⚠⚠ Without this the document is a free hand over the whole ledger:
    // somebody could pay rent into Sales Revenue and the books would still
    // balance perfectly.
    expect(fn () => app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash, spentOn: '2026-08-03',
        lines: [['account' => Account::bySlug('sales_revenue'), 'amount' => '500']],
        createdBy: $this->user,
    ))->toThrow(ValidationException::class, 'not an expense account');

    expect(ExpenseVoucher::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

it('refuses a heading, even an expense one', function () {
    $group = Account::where('code', '6000')->first();

    expect(fn () => app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash, spentOn: '2026-08-03',
        lines: [['account' => $group, 'amount' => '500']],
        createdBy: $this->user,
    ))->toThrow(ValidationException::class, 'cannot be posted to');
});

it('refuses a voucher dated into a closed month', function () {
    AccountingPeriod::closeThrough(Carbon::parse('2026-07-31'), $this->user);

    expect(fn () => app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash, spentOn: '2026-07-20',
        lines: [['account' => $this->rent, 'amount' => '500']], createdBy: $this->user,
    ))->toThrow(PeriodClosedException::class);
});

it('refuses a voucher dated in the future', function () {
    expect(fn () => app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash, spentOn: '2026-09-01',
        lines: [['account' => $this->rent, 'amount' => '500']], createdBy: $this->user,
    ))->toThrow(ValidationException::class, 'in the future');
});

it('refuses an account with no ledger account behind it', function () {
    $tin = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Unmapped tin', 'type' => MoneyAccountType::Cash,
    ]);

    expect(fn () => app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $tin, spentOn: '2026-08-03',
        lines: [['account' => $this->rent, 'amount' => '500']], createdBy: $this->user,
    ))->toThrow(MappingNotFound::class);
});

it('ignores empty lines and refuses a voucher with nothing on it', function () {
    expect(fn () => app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash, spentOn: '2026-08-03',
        lines: [['account' => $this->rent, 'amount' => '0']], createdBy: $this->user,
    ))->toThrow(ValidationException::class, 'at least one line');
});

it('leaves the books balancing', function () {
    app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash, spentOn: '2026-08-03',
        lines: [
            ['account' => $this->rent, 'amount' => '18000.00'],
            ['account' => $this->fuel, 'amount' => '2500.55'],
        ],
        createdBy: $this->user,
    );

    expect((int) JournalLine::sum('amount_minor'))->toBe(0)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty();
});
