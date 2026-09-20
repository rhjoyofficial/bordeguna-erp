<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A till's over or short was calculated at close and then left for somebody to
 * "book as its own adjustment", which nobody did (accountant meeting
 * 19 Sep 2026, F5). Closing now posts it; this is the link back to that journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->foreignId('over_short_journal_id')->nullable()->constrained('journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_shifts', fn (Blueprint $table) => $table->dropConstrainedForeignId('over_short_journal_id'));
    }
};
