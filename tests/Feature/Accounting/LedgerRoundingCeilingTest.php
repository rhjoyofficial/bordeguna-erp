<?php

use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use Illuminate\Support\Carbon;

/**
 * Audit #8, 14 Oct 2026: the automatic rounding line has a ceiling.
 *
 * ⚠ Before this, ANY gap was booked to Rounding Differences, so a dropped
 * Tk 50,000 line would have balanced quietly. The ceiling is 5 paisa, or
 * 1 paisa per Tk 100 debited on a large journal (the 4-decimal unit cost
 * residue grows with quantity; see LedgerRounding::MAX_MINOR).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-14 10:00:00');
    $this->business = Business::create(['name' => 'Round Co', 'slug' => 'round-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);

    $this->stock = Account::bySlug('stock_on_hand')->id;
    $this->payable = Account::bySlug('accounts_payable')->id;
});

it('names a gap of up to five paisa as rounding', function () {
    $lines = LedgerRounding::balance([
        JournalLineDraft::debit($this->stock, Money::ofMinor(1_005)),
        JournalLineDraft::credit($this->payable, Money::ofMinor(1_000)),
    ], Carbon::today());

    expect($lines)->toHaveCount(3)
        ->and($lines[2]->amount->minor)->toBe(-5)
        ->and($lines[2]->accountId)->toBe(Account::bySlug('rounding_difference')->id);
});

it('refuses a dropped Tk 50,000 line instead of booking it as rounding', function () {
    LedgerRounding::balance([
        JournalLineDraft::debit($this->stock, Money::ofMinor(10_000_000)),
        JournalLineDraft::credit($this->payable, Money::ofMinor(5_000_000)),
    ], Carbon::today(), 'Rounding on bill B-1');
})->throws(JournalException::class, 'too large to be rounding (Rounding on bill B-1)');

it('refuses six paisa on a small journal in either direction', function (int $gap) {
    LedgerRounding::balance([
        JournalLineDraft::debit($this->stock, Money::ofMinor(1_000)),
        JournalLineDraft::credit($this->payable, Money::ofMinor(1_000 + $gap)),
    ], Carbon::today());
})->with([6, -6])->throws(JournalException::class);

it('scales the ceiling at one paisa per Tk 100 on a large journal', function () {
    // Tk 1 lakh debited: Tk 10 (1,000 paisa) is tolerated, 1,001 paisa is not.
    $ok = LedgerRounding::balance([
        JournalLineDraft::debit($this->stock, Money::ofMinor(10_001_000)),
        JournalLineDraft::credit($this->payable, Money::ofMinor(10_000_000)),
    ], Carbon::today());
    expect(end($ok)->amount->minor)->toBe(-1_000);

    expect(fn () => LedgerRounding::balance([
        JournalLineDraft::debit($this->stock, Money::ofMinor(9_998_999)),
        JournalLineDraft::credit($this->payable, Money::ofMinor(10_000_000)),
    ], Carbon::today()))->toThrow(JournalException::class);
});
