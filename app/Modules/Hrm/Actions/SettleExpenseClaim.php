<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paying an employee back, by whichever of the two routes the office chooses.
 *
 * ⚠⚠ BOTH ROUTES ARE REAL, AND THEY END IN DIFFERENT DOCUMENTS (Joy, 27 Aug
 * 2026). Paying now produces a payment voucher: money leaves cash, bank or an
 * MFS wallet today, and the voucher is the receipt. Adding it to salary produces
 * a payroll adjustment: nothing moves until the next run, and the money reaches
 * them with their wages. The claim records which happened and points at the
 * document, so "how was I paid back" always has an answer.
 *
 * ⚠ Neither route invents an accounting rule. The voucher goes through the same
 * RecordExpenseVoucher that every other expense uses - the period lock, the
 * future-date refusal, the mapping and the numbering all stay where they are.
 * The payroll route creates the same adjustment HR could have typed by hand.
 *
 * ⛔ SETTLING TWICE IS REFUSED. A claim already settled is money already paid,
 * and a second press would either write a second voucher or a second salary
 * line. Both are real money, and neither is recoverable by an undo button.
 */
class SettleExpenseClaim
{
    public function __construct(private readonly RecordExpenseVoucher $recordVoucher) {}

    /**
     * Pay the claim now, out of a money account.
     */
    public function payNow(ExpenseClaim $claim, MoneyAccount $moneyAccount, Account $expenseAccount, User $actor): ExpenseClaim
    {
        $this->assertSettleable($claim);

        // A category claim was EXPENSED AT APPROVAL - paying it now clears
        // the payable against the till, and must not expense a second time.
        if ($claim->approval_journal_id !== null) {
            return $this->payPostedClaim($claim, $moneyAccount, $actor);
        }

        return DB::transaction(function () use ($claim, $moneyAccount, $expenseAccount, $actor) {
            $voucher = $this->recordVoucher->handle(
                moneyAccount: $moneyAccount,
                spentOn: $claim->spent_on->toDateString(),
                lines: [[
                    'account' => $expenseAccount,
                    'amount' => (string) $claim->amount,
                    'description' => $claim->purpose,
                ]],
                createdBy: $actor,
                // ⚠ The employee is named as the payee, not the shop they bought
                // from. The money left the business to reach THEM; where they
                // spent it is what the receipt says.
                paidTo: $claim->employee?->fullName(),
                reference: $claim->claim_number,
                note: 'Reimbursement of expense claim '.$claim->claim_number,
            );

            $this->copyEvidenceTo($claim, $voucher);

            $claim->update([
                'expense_account_id' => $expenseAccount->id,
                'settlement_method' => ExpenseClaim::SETTLE_PAYMENT,
                'settled_at' => now(),
                'settled_by' => $actor->id,
                'expense_voucher_id' => $voucher->id,
            ]);

            return $claim->fresh();
        });
    }

    /**
     * Add the claim to the employee's next salary.
     */
    public function withSalary(ExpenseClaim $claim, Account $expenseAccount, PayrollPeriod $period, User $actor): ExpenseClaim
    {
        $this->assertSettleable($claim);

        // ⭐ BOTH ROUTES NOW WORK FOR EVERY CLAIM (Joy, 7 Sep 2026). This used
        // to refuse any claim that had been expensed at approval, because
        // payroll posts a `reimbursement` earning against an EXPENSE account and
        // the same cost would have landed in the books twice.
        //
        // The fix is not to skip the posting, it is to post it to the right
        // place: a claim-sourced settlement uses its own adjustment type, whose
        // earning code maps to Employee Reimbursements Payable. Payroll then
        // DEBITS the payable the approval credited, and the money reaches the
        // employee as wages. Nothing is expensed twice and nothing is skipped.
        //
        // ⚠ A claim that carries a category but was approved BEFORE this
        // existed has no approval journal yet, so there is no payable to clear.
        // Post it now, exactly as approval would have, and then settle it the
        // same way as any other. Without this the payroll line would debit a
        // payable nothing had ever credited.
        if ($claim->approval_journal_id === null && $claim->expense_category_id !== null) {
            app(PostExpenseClaimApproval::class)->handle($claim, $actor);
            $claim->refresh();
        }

        // ⚠⚠ THE PERIOD IS NAMED, NEVER GUESSED. An adjustment belongs to one
        // payroll month, and picking "the current one" silently would put a
        // reimbursement into a run that may already be calculated, approved or
        // disbursed - money appearing in a month somebody has already signed off.
        if ($period->latestRun()?->status?->isPaidOut() === true) {
            throw ValidationException::withMessages([
                'payroll_period_id' => "The {$period->label()} payroll has already been paid. Choose a later month, or pay this claim now instead.",
            ]);
        }

        return DB::transaction(function () use ($claim, $expenseAccount, $period, $actor) {
            // ⚠⚠ THE `source_key` IS WHAT MAKES PAYROLL PAY THIS. `scopePayable`
            // pays a manual adjustment only once its own approval is approved,
            // and a GENERATED one only when it carries a source key and no
            // approval request. This used to be a plain `create()`: no approval
            // was ever raised for it and none could be raised afterwards, so the
            // reimbursement sat on the claim screen looking settled and never
            // reached a payslip. The money disappeared silently, which is the
            // worst way for money to disappear.
            //
            // Generated is also the honest description. The human decision
            // happened when the claim was approved; asking a second time would
            // be theatre - the same reasoning `ApplySalaryRevision` follows for
            // back pay.
            $adjustment = PayrollAdjustment::once("expense_claim:{$claim->id}", [
                'business_id' => $claim->business_id,
                'employee_id' => $claim->employee_id,
                'payroll_period_id' => $period->id,
                // ⛔ THE LINE THAT DECIDES WHETHER THE COST IS COUNTED TWICE.
                // Already expensed at approval -> settlement, which maps to the
                // payable. Never expensed (a legacy claim with no category) ->
                // reimbursement, which maps to an expense account and is the
                // only posting that cost will ever get.
                'type' => $claim->approval_journal_id !== null
                    ? PayrollAdjustmentType::ExpenseClaimSettlement
                    : PayrollAdjustmentType::Reimbursement,
                'amount' => (string) $claim->amount,
                'reason' => 'Expense claim '.$claim->claim_number.': '.$claim->purpose,
                // ⚠⚠ The polymorphic origin is what stops the same claim being
                // added to salary twice through two different screens: the
                // adjustment knows exactly which claim produced it.
                'origin_type' => $claim->getMorphClass(),
                'origin_id' => $claim->id,
                'requested_by' => $actor->id,
            ]);

            // `once` returns null when the key is already taken, which here means
            // the claim was already put on a salary. `assertSettleable` catches
            // that first; this keeps the failure loud if it ever gets past.
            if ($adjustment === null) {
                throw ValidationException::withMessages([
                    'payroll_period_id' => "Claim {$claim->claim_number} is already on a salary.",
                ]);
            }

            $claim->update([
                'expense_account_id' => $expenseAccount->id,
                'settlement_method' => ExpenseClaim::SETTLE_PAYROLL,
                'settled_at' => now(),
                'settled_by' => $actor->id,
                'payroll_adjustment_id' => $adjustment->id,
            ]);

            return $claim->fresh();
        });
    }

    /**
     * ⚠ The receipt follows the money. A voucher created from a claim carries
     * copies of the claim's attachment rows, so the payment document has its
     * own proof and stays complete even if somebody later tidies the claim.
     */
    private function copyEvidenceTo(ExpenseClaim $claim, ExpenseVoucher $voucher): void
    {
        foreach ($claim->attachments as $file) {
            $copy = $file->replicate(['attachable_type', 'attachable_id']);
            $copy->attachable_type = $voucher->getMorphClass();
            $copy->attachable_id = $voucher->id;
            $copy->collection = ExpenseVoucher::EVIDENCE_COLLECTION;
            $copy->save();
        }
    }

    /**
     * Dr Employee Reimbursements Payable / Cr the money account - the mirror
     * of what approval posted, and nothing else.
     */
    private function payPostedClaim(ExpenseClaim $claim, MoneyAccount $moneyAccount, User $actor): ExpenseClaim
    {
        return DB::transaction(function () use ($claim, $moneyAccount, $actor) {
            $payable = Account::bySlug('employee_reimbursements_payable');
            $cash = Account::findOrFail($moneyAccount->account_id);
            $amount = Money::ofTaka((string) $claim->amount);

            $journal = app(PostJournal::class)->handle(
                new JournalDraft(
                    date: now()->startOfDay(),
                    lines: [
                        JournalLineDraft::debit($payable->id, $amount, 'Reimbursed '.$claim->claim_number, $claim->employee),
                        JournalLineDraft::credit($cash->id, $amount, $moneyAccount->label(), null, $moneyAccount),
                    ],
                    memo: 'Reimbursement of expense claim '.$claim->claim_number,
                    source: $claim,
                ),
                $actor,
            );

            $claim->update([
                'settlement_method' => ExpenseClaim::SETTLE_PAYMENT,
                'settled_at' => now(),
                'settled_by' => $actor->id,
            ]);

            return $claim->fresh();
        });
    }

    private function assertSettleable(ExpenseClaim $claim): void
    {
        if ($claim->isSettled()) {
            throw ValidationException::withMessages([
                'claim' => "Claim {$claim->claim_number} has already been settled on ".$claim->settled_at->toDayDateTimeString().'.',
            ]);
        }

        if (! $claim->isApproved()) {
            throw ValidationException::withMessages([
                'claim' => "Claim {$claim->claim_number} has not been approved yet, so it cannot be paid.",
            ]);
        }
    }
}
