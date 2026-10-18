<?php

use App\Modules\Purchasing\Support\PurchaseOrderDateBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ D-G (Joy, 18 Oct 2026): "many order done previous date but add later".
 * A purchase order keeps the day it was PLACED with the supplier, which is
 * often earlier than the day it was typed in. `created_at` stays what it is:
 * the moment of entry, for the audit trail.
 *
 * ⚠ Additive and reversible: nullable here, filled for every existing order
 * with the Dhaka day of its created_at (in batches of 500), and made NOT NULL
 * by the next migration only once no row is left empty (live-migration rule:
 * compatibility before constraints, as 2026_10_17_500000/500100).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->date('order_date')->nullable()->after('status');
            $table->index(['business_id', 'order_date']);
        });

        PurchaseOrderDateBackfill::run(500);
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'order_date']);
            $table->dropColumn('order_date');
        });
    }
};
