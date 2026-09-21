<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The supplier's own delivery paper, on the receipt it arrived with.
     *
     * ⚠ THEIR number, not ours, the same way a supplier bill keeps the
     * supplier's invoice number. A goods receipt is identified by `id`; the
     * challan is what the lorry driver handed over, and it is the only thing a
     * supplier can quote back when a delivery is disputed ("our challan 4471
     * says 50 jars"). Before this it could only be typed into the free-text
     * note, where nothing can find it.
     *
     * ⚠ Nullable, and deliberately NOT unique. Whether the same challan from the
     * same supplier should be refused twice is a business rule nobody has
     * stated (an owner question), and a unique index here would be that rule
     * decided by a migration.
     *
     * ⚠ No batch columns here. Batch number, manufacture and expiry dates live
     * on the stock batch the receipt creates (reached through
     * goods_receipt_lines.stock_movement_id), because that is what FEFO, the
     * Expiry screen and the nightly write-off proposals read. A second copy on
     * the receipt line would be a second truth that could disagree.
     */
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('supplier_challan_number', 100)->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('supplier_challan_number');
        });
    }
};
