<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ B18 (Joy's initial plan, R91 to R96, 25 Oct 2026; Q2 = B: staff type every
 * order, no shop sync): a website, phone, WhatsApp, Facebook or walk-in order
 * is handled the same way, so the ORDER carries how it reached us, whether the
 * customer confirmed it on a call, how it travels and how it is paid.
 *
 * ⚠ Every column is nullable or defaulted: an order taken before this slice
 * reads as it always did (no channel = typed in, no confirmation asked, no
 * delivery method = decided at the challan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Website orders: which shop (laladia.com, bionic.garden, or a name).
            $table->string('channel_site', 60)->nullable()->after('channel');

            // ⚠ NOT `shop_reference`, which is unique across the whole table and
            // belongs to the ERP storefront and the import sheet. A number typed
            // from another shop may repeat (two shops both have an order 1024),
            // so this one only WARNS on a repeat, and the person decides.
            $table->string('external_reference', 60)->nullable()->after('shop_reference');
            $table->index(['business_id', 'external_reference'], 'sales_orders_external_reference_index');

            // The confirmation call, kept apart from the approval (R92).
            $table->string('confirmation_status', 20)->nullable()->after('status');
            $table->unsignedSmallInteger('confirmation_attempts')->default(0)->after('confirmation_status');
            $table->timestamp('confirmation_last_try_at')->nullable()->after('confirmation_attempts');
            $table->foreignId('confirmed_by')->nullable()->after('confirmation_last_try_at')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            $table->string('confirmation_note', 255)->nullable()->after('confirmed_at');
            $table->index(['business_id', 'confirmation_status'], 'sales_orders_confirmation_index');

            // How the goods travel (R93): courier, rider, own_staff, hand.
            $table->string('delivery_method', 20)->nullable()->after('delivery_charge');
            // The zone the courier charge was taken from, frozen with the charge.
            $table->string('delivery_zone', 100)->nullable()->after('delivery_method');
            // Who changed a courier's zone charge (Manager or Owner only).
            $table->foreignId('delivery_charge_set_by')->nullable()->after('delivery_zone')->constrained('users')->nullOnDelete();
            // Own human delivery: who carries it, and the cash account the money
            // they collect goes into. ⚠ SEAM: the rider's own Employee cash
            // account (B3) does not exist on this branch; until it does, a cash
            // account is chosen here.
            $table->foreignId('rider_employee_id')->nullable()->after('delivery_charge_set_by')->constrained('employees')->nullOnDelete();
            $table->foreignId('cod_money_account_id')->nullable()->after('rider_employee_id')->constrained('money_accounts')->nullOnDelete();
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            // A free gift: priced at zero, its cost goes to the gift expense head.
            $table->boolean('is_gift')->default(false)->after('line_discount');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('is_gift');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['confirmed_by']);
            $table->dropForeign(['delivery_charge_set_by']);
            $table->dropForeign(['rider_employee_id']);
            $table->dropForeign(['cod_money_account_id']);
            $table->dropIndex('sales_orders_external_reference_index');
            $table->dropIndex('sales_orders_confirmation_index');
            $table->dropColumn([
                'channel_site', 'external_reference',
                'confirmation_status', 'confirmation_attempts', 'confirmation_last_try_at', 'confirmed_by', 'confirmed_at', 'confirmation_note',
                'delivery_method', 'delivery_zone', 'delivery_charge_set_by', 'rider_employee_id', 'cod_money_account_id',
            ]);
        });
    }
};
