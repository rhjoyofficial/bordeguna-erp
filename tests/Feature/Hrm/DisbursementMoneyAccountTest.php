<?php

use App\Models\User;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ConfirmDisbursementBatch;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1.1, second half: which account the salaries actually came out of.
 *
 * ⚠⚠ This is the live gap R1 described. Payroll has run for real and the
 * disbursement batch already records method, reference and a confirmation - but
 * nothing recorded WHERE THE MONEY LEFT FROM, so no cash book could ever be
 * produced for the month, even afterwards.
 *
 * ⚠ The account belongs at CONFIRMATION, not at creation. Batches are created by
 * grouping payslips by payment method before any money moves; the money moves
 * when somebody confirms, which is a separate act with its own timestamp and
 * actor. A first draft of the design put the column on creation and reading the
 * flow corrected it - see ACCOUNTING-ARCHITECTURE-REVIEW.md §11.2.
 *
 * So the column is nullable in the schema and REQUIRED at confirmation: the
 * constraint is about *when*, and a NOT NULL cannot express "not yet".
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Payout Co', 'slug' => 'payout-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->account = MoneyAccount::create([
        'name' => 'BRAC Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_number' => '1501202536477001',
    ]);
});

/** A batch with no payslips is enough to exercise confirmation itself. */
function bareBatch($test): DisbursementBatch
{
    $period = PayrollPeriod::firstOrCreate(
        ['business_id' => $test->business->id, 'year' => 2026, 'month' => 8],
        ['start_date' => '2026-07-24', 'end_date' => '2026-08-23', 'divisor_days' => 30],
    );

    $run = PayrollRun::create([
        'business_id' => $test->business->id,
        'payroll_period_id' => $period->id,
        'version' => 1,
        'status' => 'disbursed',
        'employee_count' => 0,
        'total_net' => 0,
    ]);

    return DisbursementBatch::create([
        'business_id' => $test->business->id,
        'payroll_run_id' => $run->id,
        'reference' => 'DIS-202608-V1-BANKTRANSFER',
        'method' => 'bank_transfer',
        'created_by' => $test->user->id,
    ]);
}

it('refuses to confirm a payment without saying which account paid it', function () {
    // ⚠ The whole point. Confirming means the money has gone; a confirmation
    // that cannot say where from is the record that was missing all along.
    $batch = bareBatch($this);

    expect(fn () => app(ConfirmDisbursementBatch::class)->handle($batch, $this->user))
        ->toThrow(ValidationException::class);

    expect($batch->fresh()->confirmed_at)->toBeNull();
});

it('records the account the money left from', function () {
    $batch = bareBatch($this);

    app(ConfirmDisbursementBatch::class)->handle($batch, $this->user, moneyAccount: $this->account);

    $batch->refresh();

    expect($batch->money_account_id)->toBe($this->account->id)
        ->and($batch->confirmed_at)->not->toBeNull()
        ->and($batch->moneyAccount->name)->toBe('BRAC Bank - Current');
});

it('refuses an account belonging to another business', function () {
    // ⚠ Tenant isolation on a money movement. Paying Bor de Guna's salaries from
    // LaLaDia's bank account is the kind of thing that is obvious in a sentence
    // and invisible in a dropdown.
    $batch = bareBatch($this);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-payout-co']);
    $foreign = MoneyAccount::withoutGlobalScopes()->create([
        'business_id' => $other->id,
        'name' => 'Their cash',
        'type' => MoneyAccountType::Cash,
    ]);

    expect(fn () => app(ConfirmDisbursementBatch::class)->handle($batch, $this->user, moneyAccount: $foreign))
        ->toThrow(ValidationException::class);

    expect($batch->fresh()->confirmed_at)->toBeNull();
});

it('refuses an inactive account', function () {
    $batch = bareBatch($this);
    $this->account->update(['is_active' => false]);

    expect(fn () => app(ConfirmDisbursementBatch::class)->handle($batch, $this->user, moneyAccount: $this->account))
        ->toThrow(ValidationException::class);
});

it('refuses to confirm a payment dated inside a closed accounting period', function () {
    // ⚠⚠ This is what makes the period lock GENERAL rather than renamed. It was
    // built for stock and lived in Inventory; a closed month has to mean the
    // same thing to a salary payment as it does to a goods receipt, or the two
    // disagree and the books are wrong in a way nobody sees.
    //
    // ⚠ One period control for the whole system, not one per module - two locks
    // that can disagree is a worse outcome than the single-module one.
    Carbon::setTestNow('2026-08-02 09:00:00');

    $batch = bareBatch($this);

    AccountingPeriod::closeThrough(Carbon::parse('2026-07-31'), $this->user, 'July close');

    expect(fn () => app(ConfirmDisbursementBatch::class)->handle(
        $batch,
        $this->user,
        paidOn: Carbon::parse('2026-07-20'),
        moneyAccount: $this->account,
    ))->toThrow(PeriodClosedException::class);

    expect($batch->fresh()->confirmed_at)->toBeNull();

    Carbon::setTestNow();
});

it('allows a payment dated after the closed period', function () {
    Carbon::setTestNow('2026-08-02 09:00:00');

    $batch = bareBatch($this);

    AccountingPeriod::closeThrough(Carbon::parse('2026-07-31'), $this->user, 'July close');

    app(ConfirmDisbursementBatch::class)->handle(
        $batch,
        $this->user,
        paidOn: Carbon::parse('2026-08-01'),
        moneyAccount: $this->account,
    );

    expect($batch->fresh()->confirmed_at)->not->toBeNull();

    Carbon::setTestNow();
});
