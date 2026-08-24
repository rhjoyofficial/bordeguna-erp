<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How long THIS employee's probation runs, in months (Joy, 24 Aug 2026:
     * "default probation is 6 month but it can vary of employees sometimes").
     *
     * Null means the company default of 6 - the column exists for the person
     * whose offer letter says otherwise, so the exception is a field on their
     * record rather than a note nobody reads. Probation ends at
     * joining_date + this many months; becoming permanent is its own action
     * (it grants the permanent leave entitlements), not an edit of this field.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedTinyInteger('probation_months')->nullable()->after('employment_type');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('probation_months');
        });
    }
};
