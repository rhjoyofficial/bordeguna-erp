<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Enums\PurchaseEntryMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives every purchase order line written before 17 Oct 2026 the total it
 * always meant: quantity x unit cost through Money::ofProduct, which is the
 * exact expression every journal, GRNI figure and print used for it.
 *
 * ⚠⚠ THE APPLICATION'S ARITHMETIC, NEVER `UPDATE ... SET total = qty * cost`
 * IN SQL (ChatGPT ruling, live-migration safeguards). The database would
 * round its own way and the backfilled figure could differ from what the
 * ledger was posted with by a paisa, on the one column whose whole purpose is
 * to agree with the ledger.
 *
 * ⚠ Bounded batches through the query builder (no tenant scope, no model
 * events), so a live table of any size is walked without holding it all in
 * memory, and a row filled by an earlier run is never touched again.
 */
final class PurchaseLineBackfill
{
    /**
     * @return int how many lines were filled
     */
    public static function run(int $chunk = 500): int
    {
        $filled = 0;

        DB::table('purchase_order_lines')
            ->whereNull('line_total_paisa')
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $rows) use (&$filled) {
                foreach ($rows as $row) {
                    DB::table('purchase_order_lines')->where('id', $row->id)->update([
                        'line_total_paisa' => self::totalFor((string) $row->quantity_ordered, (string) $row->unit_cost)->minor,
                        // Every old line was typed as a unit price.
                        'entry_mode' => PurchaseEntryMode::Rate->value,
                    ]);

                    $filled++;
                }
            });

        return $filled;
    }

    /** The one expression, named, so a test can hold the migration to it. */
    public static function totalFor(string $quantity, string $unitCost): Money
    {
        return Money::ofProduct($quantity, $unitCost);
    }

    public static function remaining(): int
    {
        return (int) DB::table('purchase_order_lines')->whereNull('line_total_paisa')->count();
    }
}
