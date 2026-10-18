<?php

namespace App\Modules\Purchasing\Enums;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ⭐ Structured supplier payment terms (ruling P6, 18 Oct 2026).
 *
 * `suppliers.payment_terms` was free text ("Net 30", "COD") that nothing could
 * compute a date from, so every bill's due date was typed by hand or left
 * blank. These four kinds are what a bill's due date is worked out from; the
 * free text stays beside them as a note.
 *
 * ⚠ Only NetDays and EndOfMonth use a number of days. Immediate and Advance
 * are due on the bill date: an advance supplier is paid before the goods, so a
 * bill that still owes is already due.
 */
enum PaymentTermType: string
{
    case Immediate = 'immediate';
    case NetDays = 'net_days';
    case EndOfMonth = 'end_of_month';
    case Advance = 'advance';

    public function usesDays(): bool
    {
        return $this === self::NetDays || $this === self::EndOfMonth;
    }

    /**
     * The day a bill dated `$billDate` falls due on these terms.
     *
     * ⚠⚠ END OF MONTH IS THE BILL'S OWN MONTH'S LAST DAY, then the days. Built
     * from endOfMonth(), never with addMonth(), which rolls 31 Jan into
     * March. A bill of 31 Jan on "end of month + 0" is due 31 Jan; on "+ 15" it
     * is due 15 Feb; a bill of 1 Feb 2028 on "+ 0" is due 29 Feb 2028.
     */
    public function dueDate(CarbonInterface $billDate, ?int $days): Carbon
    {
        $date = Carbon::parse($billDate->toDateString())->startOfDay();
        $days = max(0, (int) $days);

        return match ($this) {
            self::Immediate, self::Advance => $date,
            self::NetDays => $date->addDays($days),
            self::EndOfMonth => $date->endOfMonth()->startOfDay()->addDays($days),
        };
    }

    public function label(?int $days = null): string
    {
        return match ($this) {
            self::Immediate => 'Due immediately (cash on delivery)',
            self::NetDays => 'Net '.(int) $days.' days',
            self::EndOfMonth => 'End of month + '.(int) $days.' days',
            self::Advance => 'Advance required',
        };
    }
}
