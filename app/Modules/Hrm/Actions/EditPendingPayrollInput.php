<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\LoanSchedule;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): Edit a payroll adjustment or
 * a staff loan while it waits for its FIRST approval, in a popup. Nothing is
 * paid or owed yet, so it is changed in place (no reason asked, like any
 * draft), with the same checks as raising it, and the change is written to
 * its History. The approval request stays as it is: the approver sees the new
 * figures.
 *
 * Once any stage has approved it, Edit is refused: the person who approved
 * approved those figures. Withdraw or Reject it and raise it again instead.
 */
class EditPendingPayrollInput
{
    public function __construct(private readonly RequestLoan $loans) {}

    /** One sentence why it cannot be edited now, or null. */
    public static function refusal(Model $input): ?string
    {
        $approval = $input->approvalRequest;

        if ($approval === null || ! $approval->isPending()) {
            return 'Only a request still waiting for approval can be edited.';
        }

        if ((int) $approval->current_stage > 1 || $approval->decisions()->exists()) {
            return 'Somebody has already approved a stage of it, so it cannot be edited. Withdraw it and raise it again.';
        }

        return null;
    }

    /**
     * @param  array{type: string, amount: float|string, payroll_period_id: int, reason: string}  $data
     */
    public function adjustment(PayrollAdjustment $adjustment, array $data, User $by): PayrollAdjustment
    {
        return DB::transaction(function () use ($adjustment, $data, $by) {
            $adjustment = PayrollAdjustment::query()->withoutGlobalScopes()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($adjustment, 'amount');

            $type = PayrollAdjustmentType::from($data['type']);
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'An adjustment must be more than zero. Its type decides whether it adds or deducts.']);
            }

            if ($type->isSystemGenerated()) {
                throw ValidationException::withMessages(['type' => 'An arrear is produced by an approved salary revision (SAL-05), not entered by hand.']);
            }

            $period = PayrollPeriod::query()->findOrFail($data['payroll_period_id']);
            PayrollInputWindow::assertAccepts($period);

            $before = ['type' => $adjustment->type->value, 'amount' => (string) $adjustment->amount, 'month' => $adjustment->period?->label(), 'reason' => $adjustment->reason];
            $adjustment->update(['type' => $type, 'amount' => $amount, 'payroll_period_id' => $period->id, 'reason' => $data['reason']]);

            CorrectionLog::event('edited', $by, $adjustment, ['before' => $before, 'after' => ['type' => $type->value, 'amount' => (string) $amount, 'month' => $period->label(), 'reason' => $data['reason']]]);

            return $adjustment->fresh();
        });
    }

    /**
     * @param  array{principal: float|string, installment_amount: float|string, start_year: int, start_month: int, reason: string}  $data
     */
    public function loan(Loan $loan, array $data, User $by): Loan
    {
        return DB::transaction(function () use ($loan, $data, $by) {
            $loan = Loan::query()->withoutGlobalScopes()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($loan, 'principal');

            $principal = (float) $data['principal'];
            $installment = (float) $data['installment_amount'];
            $this->loans->guardTerms($principal, $installment);
            $this->loans->guardStartMonth((int) $data['start_year'], (int) $data['start_month']);

            $before = ['principal' => (string) $loan->principal, 'installment' => (string) $loan->installment_amount, 'start' => "{$loan->start_year}-{$loan->start_month}", 'reason' => $loan->reason];
            $loan->update([
                'principal' => round($principal, 2),
                'installment_amount' => round($installment, 2),
                'installment_count' => LoanSchedule::count($principal, $installment),
                'start_year' => (int) $data['start_year'],
                'start_month' => (int) $data['start_month'],
                'reason' => $data['reason'],
            ]);

            CorrectionLog::event('edited', $by, $loan, ['before' => $before, 'after' => ['principal' => (string) round($principal, 2), 'installment' => (string) round($installment, 2), 'start' => "{$data['start_year']}-{$data['start_month']}", 'reason' => $data['reason']]]);

            return $loan->fresh();
        });
    }

    private function assertEditable(Model $input, string $field): void
    {
        if (($why = self::refusal($input)) !== null) {
            throw ValidationException::withMessages([$field => $why]);
        }
    }
}
