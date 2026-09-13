<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A challan handed to a courier, and what the courier reported back.
 *
 * ⭐ Joy, 13 September: a courier order is INVOICED WHEN THE COURIER CONFIRMS
 * DELIVERY, never at dispatch, so a refused parcel never needs a credit note.
 * The consignment is what holds the invoice back until then.
 *
 * ⚠ The courier itself is a money account of type `courier` reporting under the
 * new 1145 Receivable from Couriers: cash on delivery a courier has collected is
 * the customer's payment, held by the courier until its payout. So recording a
 * delivered parcel is an ordinary customer receipt into that account, and every
 * reader of what a customer still owes stays correct without learning anything.
 *
 * ⚠ The account is DATA written here rather than left to a seeder, the rule of
 * the 4300 migration: on a fresh database the chart does not exist yet when
 * migrations run, so this does nothing and ChartOfAccountsSeeder supplies it.
 *
 * ⚠ The unique index on `delivery_id` does not lead any foreign key's index, and
 * the courier/number index leads on `money_account_id` beside its own foreign
 * key, which MySQL may then use for that key - so the rollback drops the whole
 * table in one statement rather than index by index (error 1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // One parcel per challan. A parcel sent again is a new challan: the
            // goods came back on a return first.
            $table->foreignId('delivery_id')->unique()->constrained()->restrictOnDelete();

            // The courier, as the money account its cash on delivery sits in.
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();

            // The courier's own number, which is what its payout statement quotes.
            $table->string('consignment_number', 100);

            // What the courier was told to collect. Zero for a parcel paid before
            // it left.
            $table->decimal('cod_amount', 15, 4);

            // booked | delivered | partially_delivered | returned
            $table->string('status', 30)->default('booked');

            $table->date('outcome_on')->nullable();
            $table->decimal('collected_amount', 15, 4)->nullable();
            $table->text('outcome_note')->nullable();

            // What the outcome produced. All nullable: a returned parcel has no
            // invoice, a prepaid one no receipt, a full delivery no return.
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sales_return_id')->nullable()->constrained()->restrictOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('outcome_recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['money_account_id', 'consignment_number'], 'consignments_courier_number_unique');
            $table->index(['business_id', 'status']);
        });

        $currentAssets = DB::table('accounts')->where('code', '1100')->value('id');

        if ($currentAssets === null || DB::table('accounts')->where('code', '1145')->exists()) {
            return;
        }

        DB::table('accounts')->insert([
            'code' => '1145',
            'name' => 'Receivable from Couriers',
            'type' => 'asset',
            'parent_id' => $currentAssets,
            'is_postable' => true,
            'is_active' => true,
            'system_slug' => DB::table('accounts')->where('system_slug', 'courier_receivable')->exists() ? null : 'courier_receivable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('consignments');

        $account = DB::table('accounts')->where('code', '1145')->value('id');

        // ⚠ Never delete an account something has posted to, or a money account
        // still reports under.
        if ($account !== null
            && ! DB::table('journal_lines')->where('account_id', $account)->exists()
            && ! DB::table('money_accounts')->where('account_id', $account)->exists()) {
            DB::table('accounts')->where('id', $account)->delete();
        }
    }
};
