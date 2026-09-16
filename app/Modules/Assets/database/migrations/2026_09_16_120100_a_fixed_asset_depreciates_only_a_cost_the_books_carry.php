<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asset management plan §2.12, found 16 September 2026.
 *
 * ⚠⚠ THE TRAP. A fixed asset recorded without a money account posts no
 * acquisition journal, yet the monthly depreciation still charged it. Recording
 * something the company already owned before the ERP cutover (opening balances
 * not yet entered) would have written off a cost the books never carried,
 * pushing the balance sheet below zero for that asset.
 *
 * `cost_on_books` says the cost IS in the ledger: by its acquisition journal, or
 * because whoever recorded it confirmed it is in the opening balances. Only such
 * an asset depreciates.
 *
 * ⚠ Existing assets are marked from their acquisition journals, and only from
 * them. Production holds none (measured 16 September).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            // acquisition_journal | opening_balance | null (not on the books)
            $table->string('cost_on_books', 20)->nullable()->after('supplier_id');
        });

        DB::table('fixed_assets')
            ->whereIn('id', DB::table('journals')
                ->where('source_type', 'App\Modules\Assets\Models\FixedAsset')
                ->select('source_id'))
            ->update(['cost_on_books' => 'acquisition_journal']);
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn('cost_on_books');
        });
    }
};
