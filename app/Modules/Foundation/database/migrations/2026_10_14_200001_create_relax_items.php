<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relax zone (Joy, 14 Oct 2026): a page every signed-in employee can open for
 * a short break, with audio, uploaded video or a YouTube video on it. Owners
 * and Managers keep the list at Settings > Relax zone.
 *
 * ⚠ GROUP-WIDE, like sounds and popups: no business_id. One list for everybody.
 *
 * ⚠ Uploaded files live on the private `local` disk under relax/ with a random
 * name and are streamed by RelaxController::media to signed-in people only.
 * The row holds the path and the facts, never the bytes. A YouTube item stores
 * only the 11-character id; the player address is built from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relax_items', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            // audio | video | youtube
            $table->string('kind', 10);
            $table->string('media_path')->nullable();
            // The type read from the bytes at upload, normalised, served as is (never re-sniffed).
            $table->string('media_mime', 40)->nullable();
            $table->unsignedInteger('media_size')->nullable();
            $table->string('youtube_id', 11)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relax_items');
    }
};
