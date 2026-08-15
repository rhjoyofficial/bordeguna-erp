<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per applied catalogue SKU migration: which product wore which SKU
 * before, what was retired, what was created. The mapping JSON is the reversal
 * recipe - nothing in the migration deletes, so old SKU + reactivate undoes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogue_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('filename');
            // sha256 of the file as applied, so "which file was this?" has a
            // mechanical answer when the spreadsheet has moved on.
            $table->string('file_hash', 64);
            $table->unsignedInteger('renames_count');
            $table->unsignedInteger('retires_count');
            $table->unsignedInteger('creates_count');
            $table->unsignedInteger('keeps_count');
            $table->unsignedInteger('skips_count');
            $table->json('mapping');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_migration_runs');
    }
};
