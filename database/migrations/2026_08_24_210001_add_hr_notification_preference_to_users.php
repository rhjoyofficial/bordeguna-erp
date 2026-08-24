<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether HR's operational noise reaches this person (Joy, 24 Aug 2026).
     *
     * ⚠ Deliberately NOT a permission. Moin holds every permission in the
     * business and must keep them - he is the boss and may look at anything.
     * What he must not get is the daily HR traffic: lateness reviews, leave
     * and correction approvals. Permissions answer "may you", this answers
     * "should you be told", and collapsing the two would have meant taking
     * his access away to quiet his inbox.
     *
     * Defaults true so nobody stops being told anything by this migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('receives_hr_notifications')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('receives_hr_notifications');
        });
    }
};
