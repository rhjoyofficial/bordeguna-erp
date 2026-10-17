<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Part B (17 Oct 2026, ChatGPT REPLY-1 Q2): a recipe line keeps what
 * was TYPED (1,000 g) beside the stock-unit figure the run reads (1.0000 kg),
 * and a production-run line freezes the factor that joined the two.
 *
 * bill_of_material_lines: `entered_quantity` + `entered_unit_id`.
 * production_run_lines: `entered_quantity` + `entered_unit_id` +
 * `conversion_factor` (stock units per one entered unit, 8 places).
 *
 * ⚠⚠ `quantity_per_output` AND `quantity_consumed` ARE NOT TOUCHED. They stay
 * the normalized stock-unit value they always were. The backfill COPIES that
 * value into the entered column and names the component's stock unit, with a
 * factor of exactly 1: every existing recipe and run reads word for word as
 * it did before (REPLY-1: "recipe UOM fields default existing rows to the
 * component's stock unit"). No arithmetic, bounded batches of 500.
 *
 * ⚠ Nullable in this release, by the REPLY-1 safeguard ("compare backfilled
 * totals ... before making fields mandatory"): the model falls back to the
 * stock unit for a row that is still NULL, so the NOT NULL can follow in a
 * later migration once the live backfill is reconciled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_of_material_lines', function (Blueprint $table) {
            $table->decimal('entered_quantity', 15, 4)->nullable()->after('quantity_per_output');
            $table->foreignId('entered_unit_id')->nullable()->after('entered_quantity')
                ->constrained(table: 'units_of_measure', indexName: 'bom_line_entered_unit_fk')
                ->restrictOnDelete();
        });

        Schema::table('production_run_lines', function (Blueprint $table) {
            $table->decimal('entered_quantity', 15, 4)->nullable()->after('quantity_consumed');
            $table->foreignId('entered_unit_id')->nullable()->after('entered_quantity')
                ->constrained(table: 'units_of_measure', indexName: 'run_line_entered_unit_fk')
                ->restrictOnDelete();
            $table->decimal('conversion_factor', 20, 8)->nullable()->after('entered_unit_id');
        });

        $this->backfill();
    }

    /**
     * Public so a test can run exactly this against rows in the old shape and
     * prove the stock-unit figures are untouched.
     */
    public function backfill(): void
    {
        DB::table('bill_of_material_lines')
            ->whereNull('entered_unit_id')
            ->orderBy('id')
            ->chunkById(500, function ($lines) {
                $units = $this->stockUnits($lines->pluck('component_product_id')->all());

                foreach ($lines as $line) {
                    $unitId = $units[$line->component_product_id] ?? null;

                    if ($unitId === null) {
                        continue;
                    }

                    DB::table('bill_of_material_lines')->where('id', $line->id)->update([
                        'entered_quantity' => $line->quantity_per_output,
                        'entered_unit_id' => $unitId,
                    ]);
                }
            });

        DB::table('production_run_lines')
            ->whereNull('entered_unit_id')
            ->orderBy('id')
            ->chunkById(500, function ($lines) {
                $units = $this->stockUnits($lines->pluck('product_id')->all());

                foreach ($lines as $line) {
                    $unitId = $units[$line->product_id] ?? null;

                    if ($unitId === null) {
                        continue;
                    }

                    DB::table('production_run_lines')->where('id', $line->id)->update([
                        'entered_quantity' => $line->quantity_consumed,
                        'entered_unit_id' => $unitId,
                        'conversion_factor' => '1.00000000',
                    ]);
                }
            });
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, int> product id => stock unit id
     */
    private function stockUnits(array $productIds): array
    {
        return DB::table('products')
            ->whereIn('id', array_unique($productIds))
            ->pluck('unit_of_measure_id', 'id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function down(): void
    {
        Schema::table('production_run_lines', function (Blueprint $table) {
            $table->dropForeign('run_line_entered_unit_fk');
            $table->dropColumn(['entered_quantity', 'entered_unit_id', 'conversion_factor']);
        });

        Schema::table('bill_of_material_lines', function (Blueprint $table) {
            $table->dropForeign('bom_line_entered_unit_fk');
            $table->dropColumn(['entered_quantity', 'entered_unit_id']);
        });
    }
};
