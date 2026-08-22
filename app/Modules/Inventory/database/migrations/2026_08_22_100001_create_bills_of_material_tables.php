<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice D: the Bill of Materials. The recipe behind a packing run.
 *
 * Design note and the approved Corrections C-BOM-1..9:
 * SELF-SERVICE-MASTERDATA-AND-BOM-ARCHITECTURE.md §4.
 *
 * ⚠⚠ C-BOM-1: A BOM IS A TEMPLATE, NOT A DOCUMENT. Nothing here posts a
 * journal, moves stock or creates a batch. The production run remains the sixth
 * and last sanctioned stock document; a BOM that could post would be a seventh
 * way to move stock that nobody audits. That is why there is no status column,
 * no confirmed_by and no reversal on either table.
 *
 * ⚠⚠ C-BOM-2: THE RUN COPIES THE BOM, IT DOES NOT POINT AT IT. The two columns
 * added to `production_runs` below are PROVENANCE, and `standard_quantity` on
 * each line is the suggestion as it stood at that moment. Editing a BOM
 * afterwards must never change what a past run says it consumed - the same rule
 * the batch already follows for the unit-conversion factor, and for the same
 * reason.
 *
 * ⚠⚠ C-BOM-4: NO CONVERSION ANYWHERE. `quantity_per_output` is in the
 * COMPONENT'S OWN stock unit, per one unit of output. 0.4 kg of bulk pickle per
 * 1 pcs of jar is a stated recipe quantity, not arithmetic. Mustard oil is the
 * live trap: 1 kg = 1.1 L and 1 L = 0.9 kg are not reciprocals, and a table
 * that quietly converted would silently pick one of them.
 *
 * ⚠ EXPLICIT SHORT INDEX NAMES throughout. `bill_of_material_lines` plus
 * `bill_of_material_id` plus `component_product_id` plus `_unique` is 70
 * characters, and MySQL's identifier limit is 64. That limit has already bitten
 * this project once, which is why this migration is validated against scratch
 * MySQL rather than SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills_of_material', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // C-BOM-1. One BOM produces one product, which is the same shape as
            // the run it feeds (C-MFG-1: one run, one output).
            $table->foreignId('output_product_id')->constrained('products')->restrictOnDelete();

            // C-BOM-6. Each version is its own row. Editing a BOM that has been
            // used creates the next version rather than mutating this one,
            // because this one is what past runs were built from.
            $table->unsignedInteger('version')->default(1);
            $table->string('name')->nullable();

            // ⚠ The recipe is stated per THIS many units of output, not always
            // per one. A recipe measured over a 20 kg drum keeps its own
            // arithmetic instead of being rounded into a per-piece figure.
            $table->decimal('output_quantity', 15, 4)->default(1);

            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'output_product_id', 'version'], 'bom_business_output_version_unique');
            $table->index(['business_id', 'is_active'], 'bom_business_active_index');
        });

        Schema::create('bill_of_material_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_of_material_id')
                ->constrained(table: 'bills_of_material', indexName: 'bom_line_bom_id_fk')
                ->cascadeOnDelete();
            $table->foreignId('component_product_id')
                ->constrained(table: 'products', indexName: 'bom_line_component_fk')
                ->restrictOnDelete();

            // C-BOM-4. In the COMPONENT'S own stock unit, per `output_quantity`
            // units of the output product.
            $table->decimal('quantity_per_output', 15, 4);

            // ⚠⚠ C-BOM-5. This inflates the SUGGESTION and nothing else. It
            // never reaches the ledger and never reaches a stock movement: the
            // run's measured consumption and its own waste figure remain the
            // only truth about what left the warehouse.
            $table->decimal('wastage_percent', 8, 4)->default(0);

            $table->string('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // C-BOM-3's editability lives on the RUN, not here: a component
            // appears at most once in a recipe, and twice is a typo.
            $table->unique(['bill_of_material_id', 'component_product_id'], 'bom_line_unique');
        });

        Schema::table('production_runs', function (Blueprint $table) {
            // C-BOM-2. Provenance only. Nothing reads these to recompute a run;
            // they answer "which recipe was this built from" a year later.
            //
            // ⚠ restrictOnDelete, not nullOnDelete. A BOM that a run was built
            // from can be deactivated but never deleted, because deleting it
            // would quietly erase the answer to that question.
            $table->foreignId('bill_of_material_id')->nullable()->after('output_quantity')
                ->constrained(table: 'bills_of_material', indexName: 'run_bom_id_fk')
                ->restrictOnDelete();
            // Denormalised on purpose: the version is readable on the run
            // without a join, and stays readable in an export.
            $table->unsignedInteger('bill_of_material_version')->nullable()->after('bill_of_material_id');
        });

        Schema::table('production_run_lines', function (Blueprint $table) {
            // C-BOM-3. What the BOM suggested for this line, at this output
            // quantity, at the moment the run was drafted - so the yield report
            // can show where the operator departed from the standard.
            //
            // ⚠ NULL means "the BOM never suggested this line", which is a
            // different fact from "suggested zero". A line the operator added
            // by hand is the interesting one, and a zero would hide it among
            // the lines that simply were not needed.
            $table->decimal('standard_quantity', 15, 4)->nullable()->after('quantity_consumed');
        });
    }

    public function down(): void
    {
        Schema::table('production_run_lines', function (Blueprint $table) {
            $table->dropColumn('standard_quantity');
        });

        Schema::table('production_runs', function (Blueprint $table) {
            $table->dropForeign('run_bom_id_fk');
            $table->dropColumn(['bill_of_material_id', 'bill_of_material_version']);
        });

        Schema::dropIfExists('bill_of_material_lines');
        Schema::dropIfExists('bills_of_material');
    }
};
