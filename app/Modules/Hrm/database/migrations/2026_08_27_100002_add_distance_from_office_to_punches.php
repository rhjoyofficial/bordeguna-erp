<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far a self-service punch was from its office, in metres.
 *
 * ⚠⚠ THE ANSWER TO "WHERE DID THEY CLOCK IN FROM" HAD NOWHERE TO LIVE. The
 * punch already carried latitude and longitude, and the office already carried
 * its own coordinates, so the distance was computable - but only by somebody
 * willing to do trigonometry over two tables. Nothing on any screen could say
 * "he clocked in 4 km away", which is the whole of what Joy asked for.
 *
 * ⚠ Nullable and stays that way: a biometric punch has no location, and an
 * office with no coordinates has nothing to measure from. Null means "not
 * knowable", never "at the office".
 *
 * ⚠ Stored rather than computed on read, because it is a CAPTURE-TIME FACT
 * (ATT-03): the office may be moved or re-fenced later, and that must not
 * silently rewrite how far away somebody stood last March.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->decimal('distance_from_office_meters', 10, 2)->nullable()->after('gps_accuracy_meters');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropColumn('distance_from_office_meters');
        });
    }
};
