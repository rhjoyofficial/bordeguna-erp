<?php

namespace App\Modules\Accounting\Support;

use Illuminate\Support\Carbon;

/**
 * The ERP cutover date: the first day the books are kept here, the date the
 * accountant's opening trial balance is struck for.
 *
 * ⭐ FEA-003/004 (owner decision 14 Oct 2026): an OPENING fixed asset is one
 * acquired on or before this date. Its cost and accumulated depreciation are in
 * the opening trial balance, and the ERP depreciates it from this date on.
 *
 * Set by ACCOUNTING_CUTOVER_DATE in the environment; 24 August 2026 when the
 * variable is absent: the date of the opening journal (BRAC capital, JV-2026-27-0001).
 */
final class Cutover
{
    public static function date(): Carbon
    {
        return Carbon::parse((string) config('accounting.cutover_date', '2026-08-24'))->startOfDay();
    }
}
