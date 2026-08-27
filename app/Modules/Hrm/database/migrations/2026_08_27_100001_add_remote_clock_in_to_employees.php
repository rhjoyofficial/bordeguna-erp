<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standing permission to clock in and out from away from the office.
 *
 * ⚠⚠ WHY A STANDING PERMISSION AND NOT A MOVEMENT. Field duty and official work
 * already authorise a PARTICULAR DAY away from the office, and they are the
 * right tool for a one-off customer visit. This is the different case Joy
 * described: a person whose job is simply not at a desk, who should be able to
 * clock in from wherever they are, every day, without somebody approving each
 * morning. Modelling that as a movement would mean a manager approving the same
 * thing forever.
 *
 * ⚠ Default false, deliberately. Everyone starts office-bound, and the
 * permission is something an authority grants, which is exactly the approval
 * phase Joy asked for.
 *
 * ⚠ `granted_by` and `granted_at` are here rather than left to the activity log
 * because the question "who allowed this person to clock in from home" is asked
 * about the CURRENT state, and answering it should not require reading a diff
 * history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('may_clock_in_remotely')->default(false)->after('biometric_enroll_id');
            $table->foreignId('remote_clock_in_granted_by')->nullable()->after('may_clock_in_remotely')->constrained('users')->nullOnDelete();
            $table->timestamp('remote_clock_in_granted_at')->nullable()->after('remote_clock_in_granted_by');
            $table->string('remote_clock_in_reason')->nullable()->after('remote_clock_in_granted_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('remote_clock_in_granted_by');
            $table->dropColumn(['may_clock_in_remotely', 'remote_clock_in_granted_at', 'remote_clock_in_reason']);
        });
    }
};
