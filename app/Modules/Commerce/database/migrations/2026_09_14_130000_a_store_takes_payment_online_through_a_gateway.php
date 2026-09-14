<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SSLCommerz slice P2a: where a shop's online payments are taken and where the
 * money sits until the gateway pays it out (plan §14, Joy 13 September).
 *
 * ⭐ The gateway is a MONEY ACCOUNT of type `gateway` reporting under a new
 * 1146 Receivable from Payment Gateways, exactly as a courier reports under 1145.
 * Money the gateway has taken from a customer is owed to the business by the
 * gateway until its settlement lands in the bank; it is not cash.
 *
 * ⭐ Its fee is known per payment - the Validation API returns the gross
 * `amount` and the `store_amount` the merchant receives - and posts to the
 * existing 6475 Payment Gateway Charge, which gains a system slug and a mapping.
 *
 * ⚠⚠ `store_payment_gateways.merchant_secret` IS ENCRYPTED AT REST and never
 * leaves the server: no screen receives it, no activity log records it. A table
 * of its own rather than columns on `stores`, so the store - serialised into a
 * dozen screens - never carries a secret at all.
 *
 * ⚠ Shared merchant, per-business books (Joy's courier answer, 13 September):
 * LaLaDia and Bionic may use ONE SSLCommerz merchant, and each store names its own
 * business's gateway money account. The order prefix already tells their
 * payments apart.
 *
 * ⚠ Accounts and mappings are data written here, the 4300 rule; on a fresh
 * database the chart does not exist yet and the seeders supply them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // sslcommerz, for now the only one.
            $table->string('provider', 30);

            // ⚠ Off until somebody switches it on, whatever else is filled in.
            $table->boolean('is_enabled')->default(false);

            // SSLCommerz calls this the store id; it is the merchant's login name,
            // not a secret, and is shown so somebody can tell sandbox from live.
            $table->string('merchant_id', 100)->nullable();

            // The store password. Encrypted by the model's cast.
            $table->text('merchant_secret')->nullable();

            // ⛔ Sandbox by default. Live charges real cards.
            $table->boolean('is_live')->default(false);

            // The gateway money account payments land in (type `gateway`, 1146).
            $table->foreignId('money_account_id')->nullable()->constrained()->restrictOnDelete();

            $table->timestamps();

            $table->unique(['store_id', 'provider']);
        });

        $currentAssets = DB::table('accounts')->where('code', '1100')->value('id');

        if ($currentAssets === null) {
            return;
        }

        if (! DB::table('accounts')->where('code', '1146')->exists()) {
            DB::table('accounts')->insert([
                'code' => '1146',
                'name' => 'Receivable from Payment Gateways',
                'type' => 'asset',
                'parent_id' => $currentAssets,
                'is_postable' => true,
                'is_active' => true,
                'system_slug' => DB::table('accounts')->where('system_slug', 'gateway_receivable')->exists() ? null : 'gateway_receivable',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! DB::table('accounts')->where('system_slug', 'payment_gateway_charge')->exists()) {
            DB::table('accounts')->where('code', '6475')->whereNull('system_slug')
                ->update(['system_slug' => 'payment_gateway_charge', 'updated_at' => now()]);
        }

        $fees = DB::table('accounts')->where('code', '6475')->value('id');

        if ($fees === null) {
            return;
        }

        $businesses = DB::table('account_mappings')->where('key', 'sales.revenue')->distinct()->pluck('business_id');

        foreach ($businesses as $businessId) {
            if (DB::table('account_mappings')->where('business_id', $businessId)->where('key', 'sales.gateway_fees')->exists()) {
                continue;
            }

            DB::table('account_mappings')->insert([
                'business_id' => $businessId,
                'key' => 'sales.gateway_fees',
                'account_id' => $fees,
                'effective_from' => '2000-01-01',
                'note' => 'Online payment gateway fees (14 Sep 2026)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_gateways');

        DB::table('account_mappings')->where('key', 'sales.gateway_fees')->delete();

        DB::table('accounts')->where('code', '6475')->where('system_slug', 'payment_gateway_charge')
            ->update(['system_slug' => null, 'updated_at' => now()]);

        $account = DB::table('accounts')->where('code', '1146')->value('id');

        if ($account !== null
            && ! DB::table('journal_lines')->where('account_id', $account)->exists()
            && ! DB::table('money_accounts')->where('account_id', $account)->exists()) {
            DB::table('accounts')->where('id', $account)->delete();
        }
    }
};
