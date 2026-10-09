<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review is one per person per period PER BUSINESS. The old key left the
 * business out, so a second business saving a review for a shared employee
 * overwrote the first business's review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->unique(['business_id', 'employee_id', 'period']);
            $table->dropUnique(['employee_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->unique(['employee_id', 'period']);
            $table->dropUnique(['business_id', 'employee_id', 'period']);
        });
    }
};
