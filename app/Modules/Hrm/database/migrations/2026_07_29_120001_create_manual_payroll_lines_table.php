<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bor De Guna's own manual payroll figures, imported for the W2 parallel run
 * (ROL-02, Correction C16a).
 *
 * ⚠ **Stored rather than uploaded-and-discarded.** ROL-04 is a joint sign-off
 * by HR, Finance and Management, potentially revisited weeks later. An artefact
 * that cannot be reproduced after the meeting is not evidence of anything.
 *
 * ⚠ **This table is never an input to any calculation.** It exists solely to be
 * compared against what the system produced. Nothing in payroll may read it —
 * the moment a system figure derives from the manual sheet, the parallel run
 * stops being a comparison and becomes a copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();

            // The employee this line is about. Nullable so a row naming somebody
            // the system does not know is IMPORTED AND REPORTED rather than
            // silently dropped — an employee missing from the system is exactly
            // the kind of finding the parallel run exists to surface.
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            // As written on their sheet, kept verbatim so an unmatched row can
            // be investigated by a human who recognises the name.
            $table->string('source_employee_ref')->nullable();

            // The item as their sheet names it ("Basic", "OT", "TA & DA",
            // "Net"), plus a normalised key for matching. Both kept: the key is
            // what the report joins on, the label is what a person reads.
            $table->string('item_label');
            $table->string('item_key');

            $table->decimal('amount', 15, 2);

            // ⚠ Deliberately NOT reusing `attendance_import_batches`. Borrowing
            // an attendance table for payroll data would make two unrelated
            // imports share a lifecycle, and deleting an attendance batch would
            // orphan payroll evidence. The row number is enough provenance to
            // find a figure in the source sheet.
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_file')->nullable();

            $table->timestamps();

            // ⚠ Re-runnable import: one line per employee-reference per item per
            // period. A re-imported file updates rather than duplicating, which
            // is what makes fixing a typo in the source sheet safe.
            $table->unique(
                ['business_id', 'payroll_period_id', 'source_employee_ref', 'item_key'],
                'manual_payroll_period_ref_item_uniq',
            );
            $table->index(['business_id', 'payroll_period_id'], 'manual_payroll_business_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payroll_lines');
    }
};
