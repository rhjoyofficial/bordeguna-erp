<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 3, CRM (24 Oct 2026; FINAL-PLAN 3.2, "Contact import:
 * Reverse import with preview, only rows not changed since").
 *
 * Until now an applied phone book file left no trace of itself, so a file
 * imported twice, or the wrong file, could only be undone by hand. Each apply
 * now keeps a batch, and one row per contact it created or changed with the
 * values before and after. Reverse import reads them back:
 *
 *   created, untouched since    -> the Recycle bin (Owner restores)
 *   updated, untouched since    -> the values before the file
 *   anything changed since      -> kept, and listed in the preview
 *
 * ⚠ Batches and their rows are history: never deleted, never edited except the
 * batch's own reversed_* columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_import_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('file_name')->nullable();
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedBigInteger('imported_by');
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('reverse_reason')->nullable();
            $table->unsignedInteger('reversed_count')->nullable();
            $table->unsignedInteger('kept_count')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_import_batch_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_import_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('contact_id')->index();
            $table->string('kind', 10);
            $table->json('before')->nullable();
            $table->json('after');
            $table->string('outcome', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_batch_rows');
        Schema::dropIfExists('contact_import_batches');
    }
};
