<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset register hardening (review 13 October 2026, ruling 10): a send-out
 * to an outside party may say when the thing is expected back, so a laptop
 * at a repair shop for a month shows as overdue instead of being forgotten.
 *
 * ⚠ On the MOVEMENT, not the item. The date belongs to the document that sent
 * the thing out, stays in the history after the thing is back, and the item
 * page reads it off the latest open send-out. Nothing to clear on receive-back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_movements', function (Blueprint $table) {
            $table->date('expected_back_on')->nullable()->after('moved_on');
        });
    }

    public function down(): void
    {
        Schema::table('asset_movements', function (Blueprint $table) {
            $table->dropColumn('expected_back_on');
        });
    }
};
