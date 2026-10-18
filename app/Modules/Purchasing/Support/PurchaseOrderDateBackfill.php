<?php

namespace App\Modules\Purchasing\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives every purchase order written before 18 Oct 2026 the order date it
 * always had in practice: the Dhaka calendar day it was entered (D-G).
 *
 * ⚠⚠ THE DHAKA DAY, NEVER `DATE(created_at)` IN SQL. The timestamps are
 * written in the application's clock (config/app.php, Asia/Dhaka), so the day
 * is read through Carbon in that clock, the same way every screen reads it.
 * Leaving it to the database would make the answer depend on the server's
 * session time zone, which is exactly the 6 am Dhaka slip F39 fixed on print.
 *
 * ⚠ Bounded batches through the query builder (no tenant scope, no model
 * events), and a row filled by an earlier run is never touched again
 * (pattern: PurchaseLineBackfill, 17 Oct 2026).
 */
final class PurchaseOrderDateBackfill
{
    /**
     * @return int how many orders were filled
     */
    public static function run(int $chunk = 500): int
    {
        $filled = 0;

        DB::table('purchase_orders')
            ->whereNull('order_date')
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $rows) use (&$filled) {
                foreach ($rows as $row) {
                    DB::table('purchase_orders')->where('id', $row->id)->update([
                        'order_date' => self::dateFor($row->created_at),
                    ]);

                    $filled++;
                }
            });

        return $filled;
    }

    /** The one rule, named, so a test can hold the migration to it. */
    public static function dateFor(mixed $createdAt): string
    {
        $timezone = (string) config('app.timezone');

        // An order with no creation time is a hand-made row; today is the
        // only honest guess and it can be corrected on the order.
        return $createdAt === null
            ? Carbon::now($timezone)->toDateString()
            : Carbon::parse((string) $createdAt, $timezone)->timezone($timezone)->toDateString();
    }

    public static function remaining(): int
    {
        return (int) DB::table('purchase_orders')->whereNull('order_date')->count();
    }
}
