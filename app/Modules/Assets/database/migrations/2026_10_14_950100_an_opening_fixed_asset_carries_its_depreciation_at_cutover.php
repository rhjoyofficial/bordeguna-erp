<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEA-003/004 (owner decision 14 Oct 2026): an OPENING fixed asset, one the
 * business owned before the ERP cutover, carries what the accountant's opening
 * trial balance says about it.
 *
 *   opening_accumulated_depreciation  written off before cutover (default 0;
 *                                     0 too when the TB gives only the net
 *                                     book value and that is recorded as cost)
 *   remaining_life_months             months of life left at cutover
 *   opening_as_of                     the cutover date these figures are at
 *
 * No journal is posted for any of it: the opening trial balance already holds
 * the values. Existing rows keep 0 and NULL, so their depreciation is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->decimal('opening_accumulated_depreciation', 15, 4)->default(0)->after('salvage_value');
            $table->unsignedInteger('remaining_life_months')->nullable()->after('useful_life_months');
            $table->date('opening_as_of')->nullable()->after('cost_on_books');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn(['opening_accumulated_depreciation', 'remaining_life_months', 'opening_as_of']);
        });
    }
};
