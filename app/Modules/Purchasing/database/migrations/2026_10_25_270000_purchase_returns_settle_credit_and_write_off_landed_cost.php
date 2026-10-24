<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B9 (R66, R68, R69; Joy's Q21, 25 Oct 2026): a purchase
 * return started from the goods receipt or the supplier bill.
 *
 *  1. supplier_advances.purchase_return_id: when the bill was already paid,
 *     what the supplier owes back for the returned goods is kept as a
 *     supplier advance (kind `return_credit`, Dr 1160 / Cr GRNI), offered on
 *     the next purchase through "Allocate advance", or refunded into a money
 *     account. The link is what lets the return refuse its own reversal while
 *     that credit is alive, and counts it as credited.
 *  2. purchasing.return_landed_cost_loss: where the returned goods' share of
 *     landed cost goes (carrying, labour and the rest cannot be recovered from
 *     the supplier). Seeded to 5200 Inventory Write-offs and Shrinkage; the
 *     accountant may remap it. Only for a business whose inventory posting is
 *     on (inventory.stock_on_hand mapped), as every inventory mapping.
 *
 * ⚠ Additive only: a nullable column and a mapping row. Nothing posted moves.
 * MySQL 8 and SQLite.
 */
return new class extends Migration
{
    private const NOTE = 'Seeded default (purchase return landed cost loss, B9)';

    public function up(): void
    {
        if (! Schema::hasColumn('supplier_advances', 'purchase_return_id')) {
            Schema::table('supplier_advances', function (Blueprint $table) {
                $table->foreignId('purchase_return_id')->nullable()->after('supplier_payment_id')
                    ->constrained('purchase_returns')->restrictOnDelete();
            });
        }

        $writeOff = DB::table('accounts')->where('system_slug', 'inventory_write_off')->value('id');

        if ($writeOff === null) {
            return;
        }

        $businessIds = DB::table('account_mappings')->where('key', 'inventory.stock_on_hand')->distinct()->pluck('business_id');

        foreach ($businessIds as $businessId) {
            if (DB::table('account_mappings')->where('business_id', $businessId)->where('key', 'purchasing.return_landed_cost_loss')->exists()) {
                continue;
            }

            DB::table('account_mappings')->insert([
                'business_id' => $businessId,
                'key' => 'purchasing.return_landed_cost_loss',
                'account_id' => $writeOff,
                'effective_from' => '2000-01-01',
                'effective_to' => null,
                'note' => self::NOTE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('account_mappings')->where('key', 'purchasing.return_landed_cost_loss')->where('note', self::NOTE)->delete();

        if (Schema::hasColumn('supplier_advances', 'purchase_return_id')) {
            Schema::table('supplier_advances', function (Blueprint $table) {
                $table->dropConstrainedForeignId('purchase_return_id');
            });
        }
    }
};
