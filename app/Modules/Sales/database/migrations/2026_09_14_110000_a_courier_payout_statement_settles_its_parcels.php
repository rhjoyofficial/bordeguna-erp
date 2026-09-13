<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A courier's payout statement, and the parcels it paid out (courier slice C2).
 *
 * ⭐ Joy, 13 September: the courier pays cash on delivery out on its own terms,
 * net of its fees, and the COD fee is its own account, 6378 COD Handling Charge.
 * One Pathao account serves both brands, so ONE statement can pay parcels of two
 * businesses: each business imports it and books only its own share.
 *
 * ⚠⚠ `courier_parcel_claims` IS DELIBERATELY NOT BUSINESS-SCOPED. It is what
 * makes "a parcel is paid out once" true across the two brands: two businesses
 * importing the same statement cannot both settle the same parcel, or both
 * expense the same hand-booked parcel's fee.
 *
 * ⚠ No recipient name or phone is stored. The courier's file carries both; the
 * statement needs neither.
 *
 * ⚠ Accounts and mappings are data written here, the 4300 rule; on a fresh
 * database the chart does not exist yet and the seeders supply them.
 */
return new class extends Migration
{
    private const SLUGS = [
        '6371' => 'courier_charge',
        '6374' => 'customer_return_delivery',
        '6377' => 'courier_adjustment',
    ];

    private const MAPPINGS = [
        'courier.delivery_fees' => '6371',
        'courier.return_fees' => '6374',
        'courier.additional_charges' => '6377',
        'courier.cod_fees' => '6378',
    ];

    public function up(): void
    {
        Schema::create('courier_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // The courier, as the money account its cash on delivery sits in.
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();

            // The courier's own invoice id and date, typed: the file has neither.
            $table->string('statement_reference', 100);
            $table->date('statement_date');

            // draft | posted
            $table->string('status', 20)->default('draft');

            $table->string('source_filename')->nullable();
            $table->string('source_sha256', 64)->nullable();

            // The money that arrived, when any did. The deposit is the WHOLE
            // payout as the bank saw it; this business books only its share.
            $table->foreignId('received_money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->decimal('deposit_amount', 15, 4)->nullable();
            $table->date('received_on')->nullable();

            $table->decimal('share_amount', 15, 4)->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['money_account_id', 'statement_reference'], 'courier_statements_courier_reference_unique');
            $table->index(['business_id', 'status']);
        });

        Schema::create('courier_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_statement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');

            $table->string('consignment_number', 100);
            $table->string('merchant_reference', 100)->nullable();
            $table->string('booked_at', 30)->nullable();

            $table->decimal('collectable_amount', 15, 4);
            $table->decimal('collected_amount', 15, 4);
            $table->decimal('delivery_fee', 15, 4);
            $table->decimal('discount', 15, 4);
            $table->decimal('cod_fee', 15, 4);
            $table->decimal('additional_charge', 15, 4);
            $table->decimal('final_fee', 15, 4);
            $table->decimal('payout', 15, 4);

            // The parcel in THIS business, when there is one.
            $table->foreignId('consignment_id')->nullable()->constrained()->restrictOnDelete();

            // For a parcel the ERP does not know: null until a person decides,
            // then `mine` (a hand-booked parcel of this business) or `not_mine`.
            $table->string('resolution', 20)->nullable();

            $table->timestamps();

            $table->unique(['courier_statement_id', 'consignment_number'], 'courier_statement_lines_number_unique');
        });

        Schema::create('courier_parcel_claims', function (Blueprint $table) {
            $table->id();
            // provider|consignment number: a courier's consignment ids are unique
            // across the courier, which is what lets two brands share one account.
            $table->string('claim_key', 150)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_statement_line_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        $parent = DB::table('accounts')->where('code', '6370')->value('id');

        if ($parent === null) {
            return;
        }

        foreach (self::SLUGS as $code => $slug) {
            if (! DB::table('accounts')->where('system_slug', $slug)->exists()) {
                DB::table('accounts')->where('code', $code)->whereNull('system_slug')
                    ->update(['system_slug' => $slug, 'updated_at' => now()]);
            }
        }

        if (! DB::table('accounts')->where('code', '6378')->exists()) {
            DB::table('accounts')->insert([
                'code' => '6378',
                'name' => 'COD Handling Charge',
                'type' => 'expense',
                'parent_id' => $parent,
                'is_postable' => true,
                'is_active' => true,
                'system_slug' => DB::table('accounts')->where('system_slug', 'cod_handling_charge')->exists() ? null : 'cod_handling_charge',
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
                    'note' => 'Courier payout statements (13 Sep 2026)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_parcel_claims');
        Schema::dropIfExists('courier_statement_lines');
        Schema::dropIfExists('courier_statements');

        DB::table('account_mappings')->whereIn('key', array_keys(self::MAPPINGS))->delete();

        $account = DB::table('accounts')->where('code', '6378')->value('id');

        if ($account !== null && ! DB::table('journal_lines')->where('account_id', $account)->exists()) {
            DB::table('accounts')->where('id', $account)->delete();
        }

        foreach (self::SLUGS as $code => $slug) {
            DB::table('accounts')->where('code', $code)->where('system_slug', $slug)
                ->update(['system_slug' => null, 'updated_at' => now()]);
        }
    }
};
