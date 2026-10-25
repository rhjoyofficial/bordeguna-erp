<?php

use App\Modules\Accounting\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ 26 Oct 2026: every batch carries its EXACT value, in paisa.
 *
 * The defect (found on live, 25 Oct): after a landed cost the batch's
 * `unit_cost` is value / quantity rounded to 4 places, so
 * `current_quantity x unit_cost` drifts from what the movements say the batch
 * is worth (the reconciliation warned "the stock batch cache differs from the
 * movements by 0.04"). Worse, the goods then left at quantity x that rounded
 * cost, so a batch that sold out kept a few paisa of value with no units, and
 * Stock on Hand kept that dust forever.
 *
 *  - `stock_batches.current_value`: what the batch is worth now, in paisa,
 *    derived from its movements exactly as `current_quantity` is
 *    (StockLedger::syncBatch). Backfilled here from the movements.
 *  - `stock_movements.value_minor`: what a movement moved, in paisa, as a
 *    magnitude in the movement's own direction. Written on every new quantity
 *    movement (StockMovementObserver): an issue takes its proportional share
 *    of the batch value, and the one that empties the batch takes exactly
 *    what is left. Null on older movements, which keep being valued as
 *    quantity x unit_cost: that is what their journals posted.
 *
 * `unit_cost` stays as the display and pricing figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->bigInteger('current_value')->default(0)->after('current_quantity');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->bigInteger('value_minor')->nullable()->after('value_delta');
        });

        // The backfill: each batch's value is the sum of its movements, each
        // valued and rounded once (the StockValue rule, restated here so the
        // migration never depends on a model that may change later).
        $values = [];

        DB::table('stock_movements')
            ->whereNotNull('batch_id')
            ->orderBy('id')
            ->select(['id', 'batch_id', 'direction', 'quantity', 'unit_cost', 'value_delta'])
            ->chunk(1000, function ($rows) use (&$values) {
                foreach ($rows as $row) {
                    $amount = $row->value_delta !== null
                        ? Money::ofTaka(self::decimal($row->value_delta))->absolute()->minor
                        : Money::ofProduct(self::decimal($row->quantity), self::decimal($row->unit_cost ?? '0'))->minor;

                    $values[(int) $row->batch_id] = ($values[(int) $row->batch_id] ?? 0) + ($row->direction === 'in' ? $amount : -$amount);
                }
            });

        foreach ($values as $batchId => $value) {
            DB::table('stock_batches')->where('id', $batchId)->update(['current_value' => $value]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('value_minor');
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropColumn('current_value');
        });
    }

    /** "12.34" whether the driver hands back a string, an int or a float. */
    private static function decimal(mixed $value): string
    {
        return is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) ? $value : number_format((float) $value, 4, '.', '');
    }
};
