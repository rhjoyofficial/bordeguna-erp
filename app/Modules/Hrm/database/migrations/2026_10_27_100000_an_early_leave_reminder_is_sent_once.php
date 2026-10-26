<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Joy's HRM queue (27 Oct 2026): an early leave request that has waited two
 * days reminds its approvers, ONCE. The time the reminder went is kept on the
 * movement, so a daily job can ask "already reminded?" without reading the
 * notifications table, and a re-run the same morning sends nothing twice.
 *
 *   movements.reminded_at   when the approvers were reminded, null = not yet
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->dateTime('reminded_at')->nullable()->after('applied_at');
        });
    }

    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
