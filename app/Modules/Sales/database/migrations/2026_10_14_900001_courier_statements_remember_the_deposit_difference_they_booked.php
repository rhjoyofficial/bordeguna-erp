<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SAL-002 (audit 14 Oct 2026): one courier payout shared by two brands, with
 * less (or more) money arriving than the file says, booked the WHOLE deposit
 * difference in each brand. The difference is now booked once, and each posted
 * statement remembers how much of it IT booked, so the next brand posting the
 * same payout books only what is left.
 *
 * Null on a statement posted before this column: PostCourierStatement then
 * reads it as the whole difference, which is what the old code booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_statements', function (Blueprint $table) {
            $table->decimal('deposit_difference_booked', 15, 4)->nullable()->after('deposit_difference_reason');
        });
    }

    public function down(): void
    {
        Schema::table('courier_statements', function (Blueprint $table) {
            $table->dropColumn('deposit_difference_booked');
        });
    }
};
