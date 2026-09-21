<?php

namespace App\Modules\Purchasing\Support;

use Illuminate\Support\Carbon;

/**
 * The date checks on a batch arriving from a supplier, written once.
 *
 * ⚠⚠ ONE copy, read from two places. The controller asks it per line so each
 * problem lands under the box it belongs to (`lines.2.expiry_date`); the action
 * asks it again inside the transaction so no other caller (an import, a future
 * API, a tinker session) can record expired stock by skipping the screen. Two
 * hand-written versions of "is this expiry acceptable" would drift the first
 * time somebody changed one of them.
 *
 * ⚠ These are label sanity checks, not business policy. They refuse what cannot
 * be true (made tomorrow, expired before it was made) and what cannot be sold
 * (already out of date). How much shelf life a delivery must still have is a
 * policy nobody has stated, so nothing here pretends to know it.
 */
final class ReceivedBatchDates
{
    /**
     * @return array<'manufactured_date'|'expiry_date', string> one sentence per
     *                                                          field that is wrong, empty when both are fine
     */
    public static function problems(?string $manufacturedDate, ?string $expiryDate, ?Carbon $today = null): array
    {
        // ⚠ The app clock is Asia/Dhaka (config/app.php), so today's DATE here is
        // the storekeeper's date, not UTC's. Compared as Y-m-d strings: a date
        // has no time of day, and a Carbon comparison at midnight is exactly the
        // off-by-one the FEFO scope warns about.
        $today = ($today ?? Carbon::now())->toDateString();
        $problems = [];

        $made = self::normalise($manufacturedDate);
        $expires = self::normalise($expiryDate);

        if ($made !== null && $made > $today) {
            $problems['manufactured_date'] = 'The manufacture date is in the future. Check the date printed on the label.';
        }

        if ($expires !== null) {
            // ⚠ Good THROUGH its expiry date, the same rule StockBatch::scopeConsumable
            // applies when selling. A jar that expires today is still sellable
            // today, so it may still be received today.
            if ($expires < $today) {
                $problems['expiry_date'] = 'This batch expired on '.self::human($expires).'. Stock that is already out of date cannot be received; check the date on the label.';
            } elseif ($made !== null && $expires < $made) {
                $problems['expiry_date'] = 'The expiry date is before the manufacture date. Check both dates on the label.';
            }
        }

        return $problems;
    }

    private static function normalise(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        return Carbon::parse($date)->toDateString();
    }

    private static function human(string $date): string
    {
        return Carbon::parse($date)->format('j M Y');
    }
}
