<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Corrections\ExpenseClaimActionPolicy;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Purchasing\Actions\ReverseExpenseVoucher;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): an expense claim paid from the
 * wrong account, or paid twice by hand (HRM plan S2: Purchasing refused to
 * reverse the voucher because it pays a claim, and the claim had no reverse
 * of its own). FINAL-PLAN 3.4: "Reverse payment, kept separate from fixing
 * the claim. On salary: cancel the generated line." The Owner.
 *
 *   paid now (booked claim)   its settlement journal (Dr Employee
 *                             Reimbursements Payable / Cr the money account)
 *                             is mirrored: the payable is owed again
 *   paid now (legacy claim)   its expense voucher is reversed through
 *                             ReverseExpenseVoucher, once the claim has let go
 *                             of it (the voucher's own guard refuses a voucher
 *                             a claim still names)
 *   with salary               the generated salary line is dropped from its
 *                             month, which must still take inputs
 *
 * The claim then reads "Approved, awaiting payment" again: paying it the right
 * way is the ordinary Settle. The approval, its journal and the lines are not
 * touched. Dates: HrmCorrectionDate (its own date while open, else the first
 * open day).
 */
class ReverseExpenseClaimPayment
{
    public function __construct(
        private readonly ExpenseClaimActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
        private readonly ReverseExpenseVoucher $reverseVoucher,
        private readonly CancelPayrollAdjustment $cancelAdjustment,
    ) {}

    public function handle(ExpenseClaim $claim, User $by, ActionReason $why): ExpenseClaim
    {
        return DB::transaction(function () use ($claim, $by, $why) {
            // ⚠ The same row lock SettleExpenseClaim takes: a reverse and a
            // payment of the same claim wait for each other.
            $claim = ExpenseClaim::query()->withoutGlobalScope(BusinessScope::class)->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();
            $availability = $this->policy->availability($claim, $by, DocumentAction::Reverse);

            if (! $availability->allowed) {
                throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
            }

            $method = $claim->settlement_method;
            $properties = ['reason' => $why->display(), 'what' => 'payment', 'method' => $method];

            if ($method === ExpenseClaim::SETTLE_PAYROLL) {
                $adjustment = $this->cancelAdjustment->dropFromOpenMonth($claim->adjustment, $by, $why);
                $properties['salary_line'] = "#{$adjustment->id} in {$adjustment->period?->label()}";
            } else {
                $date = $this->policy->reverseDate($claim, $by);

                try {
                    if ($claim->expense_voucher_id !== null) {
                        $voucher = ExpenseVoucher::query()->findOrFail($claim->expense_voucher_id);

                        // Let go of the voucher first, so its guard sees no claim on it.
                        $claim->forceFill(['expense_voucher_id' => null])->save();
                        $voucher = $this->reverseVoucher->handle($voucher, $by, "Expense claim {$claim->claim_number} payment reversed: {$why->display()}", true, $date->date);
                        $properties['voucher'] = $voucher->voucher_number;
                    } else {
                        $journal = ExpenseClaimActionPolicy::settlementJournalOf($claim)
                            ?? throw ValidationException::withMessages(['reason_text' => 'This claim\'s payment entry is missing or already reversed, so ask the administrator to check it.']);

                        $reversal = $this->reverseJournal->handle($journal->load('lines'), $by, $date->date, "Payment of {$claim->claim_number} reversed: {$why->display()}");
                        $properties['reversal_journal'] = $reversal->journal_number;
                    }
                } catch (PeriodClosedException $e) {
                    throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
                }

                $properties['reversal_date'] = $date->date->toDateString();
            }

            $claim->forceFill([
                'settlement_method' => null,
                'settled_at' => null,
                'settled_by' => null,
                'expense_voucher_id' => null,
                'payroll_adjustment_id' => null,
            ])->save();

            CorrectionLog::event('reversed', $by, $claim, $properties);

            return $claim->fresh();
        });
    }
}
