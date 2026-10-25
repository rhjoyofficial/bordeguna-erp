<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Joy's answer 3 (25 Oct 2026, initial plan): every purchase extra cost
 * stays stock cost BY DEFAULT, but "some costs can differ", so each cost
 * type's default (stock cost or expense, with the expense head) is the
 * Owner's to change in Settings > Finance policy. The per-line switch with
 * its reason stays as it was.
 *
 *   purchase_cost_type_defaults   one row per business and cost type:
 *                                 treatment + the expense head used when expensed
 *
 * Seeded for every business from today's defaults (all twelve types into the
 * stock cost, Q15 of 24 Oct), so nothing changes until he edits a row. A
 * business created later gets the same defaults from the resolver when no row
 * exists (App\Modules\Purchasing\Support\CostTypeDefaults).
 *
 * PROSPECTIVE, as before: a saved line keeps the default that applied when it
 * was saved (landed_cost_lines.default_treatment); changing a default here
 * never rewrites a document.
 */
return new class extends Migration
{
    /** The stored values of App\Modules\Purchasing\Enums\LandedCostType, frozen here so the migration never follows the enum. */
    private const COST_TYPES = [
        'packaging', 'freight', 'labour', 'general_labour', 'travel', 'bank_charge',
        'other', 'insurance', 'duty', 'conveyance', 'courier', 'feeding',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('purchase_cost_type_defaults')) {
            Schema::create('purchase_cost_type_defaults', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('cost_type', 32);
                $table->string('treatment', 16)->default('capitalize');
                $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['business_id', 'cost_type']);
            });
        }

        $now = now();

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $present = DB::table('purchase_cost_type_defaults')->where('business_id', $businessId)->pluck('cost_type')->all();

            $rows = array_map(fn (string $type) => [
                'business_id' => $businessId,
                'cost_type' => $type,
                'treatment' => 'capitalize',
                'expense_account_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values(array_diff(self::COST_TYPES, $present)));

            if ($rows !== []) {
                DB::table('purchase_cost_type_defaults')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_cost_type_defaults');
    }
};
