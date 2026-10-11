<?php

use App\Modules\Sales\Support\CourierStatements\CourierStatementFormats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courier statements v2 (courier hub slices 7 + 8, 11 Oct 2026).
 *
 * ⭐ The statement keeps its original file (private disk) and its SHA-256, which
 * is unique per courier money account: the same file twice is refused. Rows the
 * ERP cannot read are kept as row errors instead of failing the whole file.
 *
 * ⭐ Every line keeps every charge the courier wrote: gross fee, discount, net
 * fee, COD fee, additional charge, compensation, adjustment, hub weight and the
 * courier's own delivery status (ChatGPT ruling 4: gross, discount and net are
 * separate source fields).
 *
 * ⭐ Two accounts Joy approved on 11 Oct 2026: 2165 Payable to Couriers
 * (ChatGPT ruling 6) and 4960 Courier Compensation Income (ruling 5). Inserted
 * here for businesses that already trade, like 2026_09_14_110000; on a fresh
 * database the seeders plant them.
 *
 * ⚠ Claim keys are rewritten with the normalised provider code, so "Pathao
 * Courier" and "pathao" can no longer hold two claims on one parcel. A key that
 * would collide with an existing one is left as it is.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        ['2165', 'Payable to Couriers', 'liability', '2100', 'courier_payable'],
        ['4960', 'Courier Compensation Income', 'income', '4900', 'courier_compensation_income'],
    ];

    private const MAPPINGS = [
        'courier.payable' => '2165',
        'courier.compensation' => '4960',
    ];

    public function up(): void
    {
        Schema::table('courier_statements', function (Blueprint $table) {
            $table->string('provider_code', 40)->nullable()->after('money_account_id');
            $table->string('format', 60)->nullable()->after('provider_code');
            // ⚠ Private `local` disk. The file carries customer names and phones.
            $table->string('source_path')->nullable()->after('source_sha256');
            $table->json('row_errors')->nullable()->after('source_path');
            // What was said about the money that arrived.
            $table->string('bank_reference', 100)->nullable()->after('received_on');
            $table->string('deposit_difference_reason', 500)->nullable()->after('bank_reference');
            $table->decimal('payable_settled_amount', 15, 4)->nullable()->after('deposit_difference_reason');
        });

        // ⚠ A file imported twice before this check existed keeps its first
        // statement's hash; the later ones lose theirs so the unique index can
        // be built. Nothing about their lines changes.
        $duplicates = DB::table('courier_statements')
            ->whereNotNull('source_sha256')
            ->select('money_account_id', 'source_sha256', DB::raw('MIN(id) as keep_id'))
            ->groupBy('money_account_id', 'source_sha256')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('courier_statements')
                ->where('money_account_id', $duplicate->money_account_id)
                ->where('source_sha256', $duplicate->source_sha256)
                ->where('id', '!=', $duplicate->keep_id)
                ->update(['source_sha256' => null]);
        }

        Schema::table('courier_statements', function (Blueprint $table) {
            $table->unique(['money_account_id', 'source_sha256'], 'courier_statements_courier_file_unique');
        });

        Schema::table('courier_statement_lines', function (Blueprint $table) {
            $table->decimal('net_fee', 15, 4)->nullable()->after('discount');
            $table->decimal('promo_discount', 15, 4)->default(0)->after('net_fee');
            $table->decimal('compensation', 15, 4)->default(0)->after('final_fee');
            $table->decimal('adjustment', 15, 4)->default(0)->after('compensation');
            $table->unsignedInteger('hub_weight_grams')->nullable()->after('payout');
            $table->string('delivery_status', 40)->nullable()->after('hub_weight_grams');
            $table->string('delivered_on', 30)->nullable()->after('delivery_status');
            // consignment | merchant_reference, frozen at posting.
            $table->string('match_method', 30)->nullable()->after('consignment_id');
        });

        DB::table('courier_statement_lines')->whereNull('net_fee')
            ->update(['net_fee' => DB::raw('delivery_fee - discount')]);

        DB::table('courier_statements')->whereNull('provider_code')->orderBy('id')->each(function ($statement) {
            $provider = DB::table('money_accounts')->where('id', $statement->money_account_id)->value('provider');

            DB::table('courier_statements')->where('id', $statement->id)
                ->update(['provider_code' => CourierStatementFormats::normalise((string) $provider)]);
        });

        DB::table('courier_parcel_claims')->orderBy('id')->each(function ($claim) {
            [$provider, $number] = array_pad(explode('|', $claim->claim_key, 2), 2, '');
            $key = CourierStatementFormats::normalise($provider).'|'.$number;

            if ($key !== $claim->claim_key && ! DB::table('courier_parcel_claims')->where('claim_key', $key)->exists()) {
                DB::table('courier_parcel_claims')->where('id', $claim->id)->update(['claim_key' => $key]);
            }
        });

        foreach (self::ACCOUNTS as [$code, $name, $type, $parentCode, $slug]) {
            $parent = DB::table('accounts')->where('code', $parentCode)->value('id');

            if ($parent === null || DB::table('accounts')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('accounts')->insert([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'parent_id' => $parent,
                'is_postable' => true,
                'is_active' => true,
                'system_slug' => DB::table('accounts')->where('system_slug', $slug)->exists() ? null : $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $businesses = DB::table('account_mappings')->where('key', 'sales.revenue')->distinct()->pluck('business_id');

        foreach ($businesses as $businessId) {
            foreach (self::MAPPINGS as $key => $code) {
                $account = DB::table('accounts')->where('code', $code)->value('id');

                if ($account === null || DB::table('account_mappings')->where('business_id', $businessId)->where('key', $key)->exists()) {
                    continue;
                }

                DB::table('account_mappings')->insert([
                    'business_id' => $businessId,
                    'key' => $key,
                    'account_id' => $account,
                    'effective_from' => '2000-01-01',
                    'note' => 'Courier statements v2 (11 Oct 2026)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('account_mappings')->whereIn('key', array_keys(self::MAPPINGS))->delete();

        foreach (self::ACCOUNTS as [$code]) {
            $account = DB::table('accounts')->where('code', $code)->value('id');

            if ($account !== null && ! DB::table('journal_lines')->where('account_id', $account)->exists()) {
                DB::table('accounts')->where('id', $account)->delete();
            }
        }

        Schema::table('courier_statement_lines', function (Blueprint $table) {
            $table->dropColumn(['net_fee', 'promo_discount', 'compensation', 'adjustment', 'hub_weight_grams', 'delivery_status', 'delivered_on', 'match_method']);
        });

        Schema::table('courier_statements', function (Blueprint $table) {
            $table->dropUnique('courier_statements_courier_file_unique');
        });

        Schema::table('courier_statements', function (Blueprint $table) {
            $table->dropColumn(['provider_code', 'format', 'source_path', 'row_errors', 'bank_reference', 'deposit_difference_reason', 'payable_settled_amount']);
        });
    }
};
