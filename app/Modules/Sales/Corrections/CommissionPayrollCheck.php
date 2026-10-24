<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Hrm\Support\PayrollInputWindow;
use App\Modules\Sales\Models\CommissionSettlement;
use Illuminate\Database\Eloquent\Model;

/**
 * A commission settlement put on payroll (25 Oct 2026) is taken back by
 * cancelling its payroll addition, which is honest only while that payroll
 * month still takes inputs. Once the run is reviewed, approved or paid the
 * money is on a payslip: refused, and the payroll correction is HR's.
 */
class CommissionPayrollCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Payroll;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        /** @var CommissionSettlement $document */
        if (! $action->isCorrection() || $document->method !== 'payroll') {
            return null;
        }

        $adjustment = $document->payrollAdjustment()->with('period')->first();

        if ($adjustment === null || $adjustment->cancelled_at !== null) {
            return null;
        }

        if (PayrollInputWindow::accepts($adjustment->period)) {
            return null;
        }

        return new Blocker('payroll_closed', "{$document->number} is already on the {$adjustment->period?->label()} payroll, which no longer takes changes.");
    }
}
