<?php

namespace App\Modules\Hrm\Payroll\Contracts;

use App\Modules\Hrm\Payroll\Data\PayrollComputation;

/**
 * One step of the payroll pipeline.
 *
 * Pure: same computation in, same computation out. No database, no clock, no
 * randomness — everything a stage needs is already on the context.
 */
interface PayrollStage
{
    public function handle(PayrollComputation $computation): PayrollComputation;
}
