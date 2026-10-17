<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff purchase at cost, Phase 2 (ChatGPT ruling 17 Oct 2026, APPROVE WITH
 * CHANGES; decisions D3, D4, D7, D8 under Claude's ownership).
 *
 * The request keeps:
 *  - `warehouse_id`: the warehouse approved for the handover (the FIFO batch is
 *    reserved there);
 *  - `customer_id`: the employee's own customer row the sale posts to;
 *  - `price_basis`: `fifo_reserved` once the price is frozen at approval;
 *  - `reapproval_reason`: why a request went back to `needs_reapproval`
 *    (the reserved batch left or its cost changed before the handover).
 *
 * Each line keeps, frozen at approval and never recomputed:
 *  - `cost_basis_batch_id` + `cost_snapshot` (the batch slices, their costs,
 *    the basis, when) behind the staff price; visible only with
 *    `hrm.staff_purchase.cost_basis.view` (D3);
 *  - `cap_reference_price` + `cap_reference_label` (`mrp`, or `reference`
 *    for a market-priced product priced at request time, never called MRP);
 *  - `priced_at`: `staff` (one unit per SKU a month at the batch cost) or
 *    `reference` (the second unit of the month, at the reference price);
 *  - `price_frozen_at`.
 * The old `mrp` column stays as the cap reference (backfilled below) so the
 * September 2026 rows still read.
 *
 * Three small tables: the audit trail of every step (`staff_purchase_events`),
 * the zero-charge defect replacements (`staff_purchase_replacements`) and the
 * per-employee-month row the limit checks lock (`staff_purchase_month_counters`),
 * so two requests racing the same allowance are serialised on one row.
 *
 * Additive, nullable or defaulted; MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_purchase_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('employee_id');
            $table->unsignedBigInteger('customer_id')->nullable()->after('warehouse_id');
            $table->string('price_basis', 32)->nullable()->after('status');
            $table->text('reapproval_reason')->nullable()->after('decision_note');
        });

        Schema::table('staff_purchase_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_basis_batch_id')->nullable()->after('mrp');
            $table->json('cost_snapshot')->nullable()->after('cost_basis_batch_id');
            $table->decimal('cap_reference_price', 12, 2)->nullable()->after('cost_snapshot');
            $table->string('cap_reference_label', 16)->nullable()->after('cap_reference_price');
            $table->string('priced_at', 16)->default('staff')->after('cap_reference_label');
            $table->timestamp('price_frozen_at')->nullable()->after('priced_at');
        });

        // The cap reference of every existing line is the MRP it was checked at.
        DB::table('staff_purchase_lines')
            ->whereNull('cap_reference_price')
            ->update(['cap_reference_price' => DB::raw('mrp'), 'cap_reference_label' => 'mrp']);

        Schema::create('staff_purchase_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('request_id')->index();
            $table->string('action', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('staff_purchase_replacements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('request_id')->index();
            $table->unsignedBigInteger('line_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->string('reason', 1000);
            $table->string('evidence_note', 1000);
            $table->string('defective_disposition', 32)->default('write_off');
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_purchase_month_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->timestamps();

            $table->unique(['employee_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_purchase_month_counters');
        Schema::dropIfExists('staff_purchase_replacements');
        Schema::dropIfExists('staff_purchase_events');

        Schema::table('staff_purchase_lines', function (Blueprint $table) {
            $table->dropColumn(['cost_basis_batch_id', 'cost_snapshot', 'cap_reference_price', 'cap_reference_label', 'priced_at', 'price_frozen_at']);
        });

        Schema::table('staff_purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['warehouse_id', 'customer_id', 'price_basis', 'reapproval_reason']);
        });
    }
};
