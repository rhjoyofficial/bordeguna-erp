<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR3 (18 Oct 2026, Joy: "follow Tally"): a production run may carry costs
 * besides its materials (labour, overhead, packaging labour, other). Each is an
 * amount and the expense head it is taken from. At confirm they are added to
 * the output batch's cost and posted Dr Stock on Hand / Cr that head.
 *
 * ⚠ Structure only, no data touched. Every existing run has no cost lines and
 * NULL in the two new run columns, so it reads exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_run_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_run_id')->constrained()->cascadeOnDelete();
            // labour | overhead | packaging_labour | other (ProductionCostKind)
            $table->string('kind', 24);
            // The head credited at confirm: the cost moves from it into stock.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            // Signed integer paisa, never a float.
            $table->bigInteger('amount_paisa');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::table('production_runs', function (Blueprint $table) {
            // Stored at confirm, like consumed_value: the cost lines' total, and
            // what the output batch was finally valued at (materials kept in the
            // batch + these costs). Taka at four places like its neighbours.
            $table->decimal('additional_cost_value', 15, 4)->nullable()->after('waste_value');
            $table->decimal('output_value', 15, 4)->nullable()->after('additional_cost_value');
        });
    }

    public function down(): void
    {
        Schema::table('production_runs', function (Blueprint $table) {
            $table->dropColumn(['additional_cost_value', 'output_value']);
        });

        Schema::dropIfExists('production_run_costs');
    }
};
