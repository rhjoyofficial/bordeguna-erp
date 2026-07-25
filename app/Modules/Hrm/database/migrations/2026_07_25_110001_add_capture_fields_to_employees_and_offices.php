<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two lookups attendance capture needs.
     *
     * `biometric_enroll_id` is the ZKTeco K40's Employee/Enroll ID (ATT-02) —
     * the only thing a device export carries to identify a person, so without
     * it an imported row cannot be matched to an employee. Kept on the central
     * employee record because the device knows the person, not the business.
     *
     * The office geofence (ATT-05 / Correction C2) is what gives "flag for
     * review" a meaning. Enforcement is an explicit per-office toggle, and a
     * punch outside it is always accepted and flagged — never rejected.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('biometric_enroll_id')->nullable()->unique()->after('employee_code');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->decimal('geofence_latitude', 10, 7)->nullable();
            $table->decimal('geofence_longitude', 10, 7)->nullable();
            $table->unsignedInteger('geofence_radius_meters')->nullable();
            $table->boolean('geofence_enforced')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['biometric_enroll_id']);
            $table->dropColumn('biometric_enroll_id');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn([
                'geofence_latitude', 'geofence_longitude',
                'geofence_radius_meters', 'geofence_enforced',
            ]);
        });
    }
};
