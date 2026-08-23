<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who brought this person in (Joy's employee-details reorganisation,
     * 23 Aug 2026, from the SmartHR reference: "can add image, Refer by").
     *
     * ℹ Free text, not a foreign key to employees, deliberately. Referrers are
     * often outside the company - a relative, a mill contact, a shop owner -
     * and a dropdown of staff would refuse the most common case. If referral
     * bonuses ever become a thing, THAT feature adds the structured link.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('referred_by')->nullable()->after('emergency_contact_relationship');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('referred_by');
        });
    }
};
