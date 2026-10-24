<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Corrections\PayrollAdjustmentActionPolicy;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): an approved or system-made payroll
 * adjustment cancelled (FINAL-PLAN 3.4: "Open month: dropped from pay. Paid
 * month: an opposite line next month.").
 *
 *   open month    the row is marked cancelled and PayrollAdjustment::payable
 *                 skips it from the next calculation
 *   closed month  the row is marked cancelled AND an opposite line, generated
 *                 (source key `adjustment_cancel:{id}`, no approval of its
 *                 own: the cancel is the decision), goes on the first month
 *                 that still takes inputs; the paid payslip is never touched
 *
 * ⚠⚠ A generated row frees its source key when it is dropped (renamed to
 * `{key}|cancelled:{id}`), so a source that is put right can make its line
 * again; `once()` would otherwise refuse it for ever. A row that was PAID keeps
 * its key: its money really moved, and the opposite line is the answer.
 *
 * One transaction, the row re-read under a lock: two cancels cannot both write
 * an opposite line (and `once()` on the opposite's key is the database's word).
 */
class CancelPayrollAdjustment
{
    public function __construct(private readonly PayrollAdjustmentActionPolicy $policy) {}

    public function handle(PayrollAdjustment $adjustment, User $by, ActionReason $why): PayrollAdjustment
    {
        return DB::transaction(function () use ($adjustment, $by, $why) {
            $adjustment = $this->lock($adjustment);
            $availability = $this->policy->availability($adjustment, $by, DocumentAction::Cancel);

            if (! $availability->allowed) {
                throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
            }

            return $this->cancel($adjustment, $by, $why);
        });
    }

    /**
     * Drops a line from a month that still takes inputs, with no policy check
     * of its own: ReverseExpenseClaimPayment calls it for the salary line a
     * claim made, after the CLAIM's policy said yes, inside its transaction.
     */
    public function dropFromOpenMonth(PayrollAdjustment $adjustment, User $by, ActionReason $why): PayrollAdjustment
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A salary line is dropped only inside its caller\'s transaction.');
        }

        $adjustment = $this->lock($adjustment);

        if ($adjustment->isCancelled() || ! PayrollInputWindow::accepts($adjustment->period)) {
            throw ValidationException::withMessages(['reason_text' => "The {$adjustment->period?->label()} payroll no longer takes this line back."]);
        }

        return $this->cancel($adjustment, $by, $why);
    }

    private function cancel(PayrollAdjustment $adjustment, User $by, ActionReason $why): PayrollAdjustment
    {
        $plan = $this->policy->plan($adjustment);
        $offset = null;

        if ($plan['offset'] !== null) {
            $period = PayrollPeriod::forMonth((int) $plan['offset']->year, (int) $plan['offset']->month);
            PayrollInputWindow::assertAccepts($period);

            $offset = PayrollAdjustment::once("adjustment_cancel:{$adjustment->id}", [
                'business_id' => $adjustment->business_id,
                'employee_id' => $adjustment->employee_id,
                'payroll_period_id' => $period->id,
                'type' => $plan['opposite'],
                'amount' => (string) $adjustment->amount,
                'reason' => "Cancels adjustment #{$adjustment->id} ({$adjustment->type->label()}, {$adjustment->period?->label()}): {$why->display()}",
                'origin_type' => $adjustment->getMorphClass(),
                'origin_id' => $adjustment->id,
                'requested_by' => $by->id,
            ]) ?? throw ValidationException::withMessages(['reason_text' => "Adjustment #{$adjustment->id} already has an opposite line."]);
        }

        $adjustment->forceFill([
            'cancelled_at' => now(),
            'cancelled_by' => $by->id,
            'cancel_reason' => $why->display(),
            'cancel_offset_id' => $offset?->id,
            'source_key' => $offset === null && $adjustment->source_key !== null
                ? "{$adjustment->source_key}|cancelled:{$adjustment->id}"
                : $adjustment->source_key,
        ])->save();

        CorrectionLog::event('cancelled', $by, $adjustment, array_filter([
            'reason' => $why->display(),
            'month' => $adjustment->period?->label(),
            'opposite' => $offset === null ? null : "#{$offset->id} {$offset->type->label()} in {$offset->period?->label()}",
        ], fn ($v) => $v !== null));

        return $adjustment->fresh();
    }

    private function lock(PayrollAdjustment $adjustment): PayrollAdjustment
    {
        return PayrollAdjustment::query()->withoutGlobalScope(BusinessScope::class)
            ->with(['period', 'approvalRequest'])
            ->whereKey($adjustment->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
