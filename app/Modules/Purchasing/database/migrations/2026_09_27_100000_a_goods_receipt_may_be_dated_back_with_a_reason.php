<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ A GOODS RECEIPT MAY CARRY AN EARLIER "RECEIVED ON" DATE (Joy, 26 Sep 2026,
 * Q11): "Yes, within open period, must add a reason." Goods that arrived on
 * Thursday and were typed on Monday belong to Thursday's stock and Thursday's
 * GRNI journal; the period lock still applies and the future is refused
 * (EffectiveDate). The reason is kept on the receipt beside the date, so the
 * accountant can see why a September delivery was entered in October.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('backdate_reason', 500)->nullable()->after('supplier_challan_number');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('backdate_reason');
        });
    }
};
