<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One record per attendance file processed, holding the outcome report
     * ATT-08 asks for: duplicates are *reported*, not silently ignored and not
     * allowed to block the rest of the file. Re-importing the same export is
     * therefore safe and visibly a no-op rather than a mystery.
     *
     * The report is kept as rows of {row, reason, value} so HR can see exactly
     * which lines were skipped and why, instead of a bare count.
     */
    public function up(): void
    {
        Schema::create('attendance_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('source'); // PunchSource
            $table->string('device_identifier')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('report')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_import_batches');
    }
};
