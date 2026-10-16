<?php

use App\Modules\Accounting\Actions\Loans\AccrueLoanInterest;
use App\Modules\Accounting\Actions\Loans\RecordLoanAdvance;
use App\Modules\Accounting\Actions\Loans\RecordLoanRepayment;
use App\Modules\Accounting\Actions\Loans\ReverseLoanEntry;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\LoanWorld;

/**
 * Bug hunt 16 Oct 2026 (01 M5, L4, L5): the loan register.
 */
beforeEach(function () {
    $this->w = LoanWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * The transaction depth at which the action first reads the loan's entries
 * (the position its limits are checked against).
 */
function depthOfFirstPositionRead(callable $act): ?int
{
    $depth = null;
    DB::listen(function ($query) use (&$depth) {
        if ($depth === null && str_contains($query->sql, 'loan_entries') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $depth = DB::transactionLevel();
        }
    });
    $act();

    return $depth;
}

// ⚠ lockForUpdate is a no-op on SQLite, so the race itself cannot be run here.
// What can be proved is the order: the position is read inside the
// transaction that holds the loan's lock, not before it.
it('checks a repayment against the position read under the loan lock', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');
    $outside = DB::transactionLevel();

    $depth = depthOfFirstPositionRead(fn () => app(RecordLoanRepayment::class)->handle($loan->fresh(), [
        'date' => '2026-02-01', 'money_account_id' => $this->w->bank->id, 'principal' => '1000', 'interest' => '0',
    ], $this->w->finance));

    expect($depth)->toBeGreaterThan($outside);
});

it('checks a disbursement against the approved principal under the loan lock', function () {
    $loan = $this->w->approved();
    $outside = DB::transactionLevel();

    $depth = depthOfFirstPositionRead(fn () => app(RecordLoanAdvance::class)->handle($loan->fresh(), [
        'date' => '2026-01-01', 'money_account_id' => $this->w->bank->id, 'amount' => '1000',
    ], $this->w->finance));

    expect($depth)->toBeGreaterThan($outside);
});

it('still refuses more principal than is owed', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');

    expect(LoanWorld::refusal(fn () => $this->w->repay($loan, ['date' => '2026-02-01', 'principal' => '130000', 'interest' => '0'])))
        ->toContain('more principal than is owed');
});

it('refuses to reverse a disbursement while a later repayment still stands on it', function () {
    $loan = $this->w->approved();
    $disbursement = $this->w->advance($loan, '100000', '2026-01-01');
    $this->w->repay($loan, ['date' => '2026-02-01', 'principal' => '20000', 'interest' => '0']);

    expect(LoanWorld::refusal(fn () => app(ReverseLoanEntry::class)->handle($disbursement, $this->w->finance, 'Entered twice')))
        ->toContain('Reverse that repayment first')
        ->and($disbursement->fresh()->isLive())->toBeTrue()
        ->and(LoanPosition::of($loan->fresh())->principal)->toBe(8_000_000);
});

it('books the adjustment when interest comes back to a total it had before', function () {
    $loan = $this->w->approved(['start_date' => '2026-01-15', 'maturity_date' => '2027-01-15']);
    $this->w->advance($loan, '120000', '2026-01-15');
    $accrue = fn () => app(AccrueLoanInterest::class)->handle($loan->fresh(), '2026-02', $this->w->finance);

    $accrue();
    $repayment = $this->w->repay($loan, ['date' => '2026-02-10', 'principal' => '60000', 'interest' => '0']);
    $accrue();
    app(ReverseLoanEntry::class)->handle($repayment, $this->w->finance, 'Bank returned it');
    $accrue();
    $this->w->repay($loan, ['date' => '2026-02-10', 'principal' => '60000', 'interest' => '0']);

    $again = $accrue();

    // 9 days on 120,000 and 19 on 60,000 at 12%: 729.86 is owed for February.
    expect($again)->not->toBeNull()
        ->and(AccrueLoanInterest::accruedFor($loan->fresh(), '2026-02'))->toBe(72_986);
});
