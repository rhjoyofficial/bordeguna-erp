<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a visit happened (CRM lead slice 2d, build plan 15 Sep 2026, D14).
 *
 * ⭐ Joy's answer 2: GPS and a photo on a visit are MANUAL and OPTIONAL. The
 *    officer presses "Add my location" if he wants to; nothing refuses a visit
 *    without one, and verification never waits for one (answer 1).
 * ⭐ Plan D14: a shop's pinned location is the FIRST visit that carries GPS,
 *    read from these columns, so there is no separate pin to fall out of step.
 *
 * ⚠ Same column shapes as `attendance_punches` (10,7 and 8,2), the other place
 *   this ERP records a browser's position, and cast `decimal:7` so SQLite in the
 *   suite and MySQL in production return the same string.
 * ⚠ Visits only. LogCrmActivity drops a location sent with any other kind.
 * ⚠ Photos are attachments on the activity (collection `photos`), not columns.
 * ⚠ No new index: the first GPS visit of a subject is read through the existing
 *   `crm_act_subject_idx` (business, subject type, subject id, occurred_at).
 *
 * Measured on production 15 Sep 2026: 0 leads and 0 activities, so the columns
 * start empty and nothing needs backfilling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_activities', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('duration_seconds');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->decimal('gps_accuracy_meters', 8, 2)->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('crm_activities', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'gps_accuracy_meters']);
        });
    }
};
