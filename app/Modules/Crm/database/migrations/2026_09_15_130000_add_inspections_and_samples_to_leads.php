<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody SAW at a distributor, and what a supplier's sample turned out to
 * be. CRM lead slice 2c, second half (build plan 15 Sep 2026, D13).
 *
 * ⭐ Inspection, Joy's answer 17: before appointing a distributor the ASM MAY
 *    record an inspection - date, notes, photos. Optional; nothing blocks an
 *    appointment without one. It sits beside the distributor's own claims
 *    (`distributor_lead_profiles`) and never overwrites them, so what the
 *    distributor said and what was seen stay two separate facts.
 * ⭐ Samples, Joy's answer 15: an append-only history of samples received from
 *    a supplier (date, item, result, judged by), and ONLY THE OWNER records a
 *    result. A result is written once; a re-test is a new sample row, so the
 *    history shows every verdict ever given rather than the latest one.
 *
 * ⚠ Both are evidence, so neither table has an edit or delete path. The models
 *   refuse it too, so a future importer or a tinker session meets the same rule.
 * ⚠ Photos are attachments on the inspection (collection `photos`), not columns.
 * ⚠ `recorded_by` / `judged_by` are nullOnDelete like every other CRM record, so
 *   removing a user never takes the history with them.
 *
 * Measured on production 15 Sep 2026: 0 leads, so both tables start empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->date('inspected_on');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'inspected_on'], 'distributor_inspections_lead_date_idx');
        });

        Schema::create('supplier_lead_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->string('item', 160);
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            // The Owner's verdict, written once.
            $table->string('result', 10)->nullable();
            $table->text('result_note')->nullable();
            $table->foreignId('judged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('judged_at')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'received_on'], 'supplier_lead_samples_lead_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_lead_samples');
        Schema::dropIfExists('distributor_inspections');
    }
};
