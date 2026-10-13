<?php

namespace App\Modules\Hrm\Exceptions;

use App\Modules\Hrm\Models\PayrollRun;
use RuntimeException;

/**
 * Thrown when a calculation is asked for while the period's latest run is
 * already past Calculated (Reviewed, Approved, Disbursed or Locked).
 *
 * ⚠⚠ A hard failure on purpose. Opening a fresh version beside a Reviewed or
 * Approved run left two live versions of one month, and both could be
 * approved, which posts the wage bill twice. Only Draft and Calculated re-run;
 * a voided run is replaced; anything else goes through reopen or unlock.
 */
class PayrollRunNotRerunnable extends RuntimeException
{
    public static function for(PayrollRun $run): self
    {
        return new self(sprintf(
            'This payroll is %s (version %d). It cannot be re-run: reopen it, or for a locked payroll request an unlock.',
            $run->status->label(),
            $run->version,
        ));
    }
}
