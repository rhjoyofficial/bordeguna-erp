<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\PayslipPayment;
use Illuminate\Support\Carbon;

/**
 * Records the moment one employee's salary actually leaves an account.
 *
 *   Dr  Salaries and Wages Payable (party = employee)
 *       Cr  the money account's own ledger account
 *
 * ⚠⚠ POSTED PER PAYMENT, NOT PER BATCH, and that is a deliberate change.
 * `ConfirmDisbursementBatch`'s own docblock has always said that "whole batch"
 * and "employee-wise" are the same mechanism at two speeds rather than two
 * mechanisms that can disagree - but the JOURNAL was posted only at the batch
 * level, so paying one person by hand moved money and told the ledger nothing.
 * The coverage matrix found the discrepancy.
 *
 * Now every payment posts, whichever route created it, because the batch walks
 * this same path. A batch of nine produces nine journals, each traceable to the
 * employee it paid - which is what makes Wages Payable answerable per person.
 *
 * ⚠ The credit account comes from `money_accounts.account_id`, not a mapping
 * key: a bank account already knows what it is.
 */
class PostPayrollPayment
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(PayslipPayment $payment, ?MoneyAccount $moneyAccount, User $postedBy): ?Journal
    {
        if ($this->existingJournal($payment) !== null) {
            return null;
        }

        $on = Carbon::parse($payment->paid_on)->startOfDay();

        // The anchor: a business that has not switched accounting on pays its
        // people exactly as it did before and posts nothing.
        if (AccountMapping::forKeyOn('payroll.net_payable', $on) === null) {
            return null;
        }

        $moneyAccount ??= $payment->moneyAccount;

        // ⚠⚠ A payment that cannot say where the money came from stops. The
        // screen offers the field; leaving it blank is a setup step nobody
        // finished, and the loud failure is what makes somebody finish it.
        if ($moneyAccount === null) {
            throw MappingNotFound::for('the account this salary was paid from', $on);
        }

        if ($moneyAccount->account_id === null) {
            throw MappingNotFound::for("money account [{$moneyAccount->name}]", $on);
        }

        $amount = Money::ofTaka((string) $payment->amount);

        if ($amount->isZero()) {
            return null;
        }

        // Finance policy B14: no cash box or wallet below zero, a bank only to its overdraft.
        MoneyAvailability::assertCanPay($moneyAccount, $amount, $on, 'money_account_id');

        $payable = AccountMapping::resolve('payroll.net_payable', $on);
        $bank = Account::findOrFail($moneyAccount->account_id);

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit($payable->id, $amount, $payment->employee?->employee_code, $payment->employee),
                JournalLineDraft::credit($bank->id, $amount, $moneyAccount->label(), null, $moneyAccount),
            ],
            memo: 'Salary paid to '.($payment->employee?->employee_code ?? 'employee').' from '.$moneyAccount->label(),
            source: $payment,
        ), $postedBy);
    }

    /**
     * A reversed payment takes its journal back out.
     *
     * ⚠ Through ReverseJournal like everything else, so the correction faces
     * every guard the original faced - including the period lock. A bounced
     * transfer reversed after the month closed cannot silently reopen it.
     */
    public function reverse(PayslipPayment $payment, User $reversedBy): ?Journal
    {
        $journal = $this->existingJournal($payment);

        if ($journal === null || $journal->hasBeenReversed()) {
            return null;
        }

        return app(ReverseJournal::class)->handle($journal, $reversedBy);
    }

    public function existingJournal(PayslipPayment $payment): ?Journal
    {
        return Journal::query()
            ->where('source_type', $payment->getMorphClass())
            ->where('source_id', $payment->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
