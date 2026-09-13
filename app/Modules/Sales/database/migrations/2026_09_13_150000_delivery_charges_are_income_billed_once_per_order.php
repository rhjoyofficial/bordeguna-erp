<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A website order's delivery charge becomes income, billed exactly once.
 *
 * ⚠⚠ UNTIL NOW A WEBSITE ORDER COULD NOT BE INVOICED CORRECTLY AT ALL: the
 * order recorded an agreed delivery charge and nothing had an account to post it
 * to, so an invoice would have billed the goods and silently dropped the charge
 * the customer agreed to pay.
 *
 * ⭐ Joy, 13 September: a NEW income account, 4300 Delivery Charges Income, and
 * the FIRST invoice that bills goods from a website order carries the charge as
 * its own line.
 *
 * ⚠ `billed_delivery_for_order_id` marks that line as "the delivery charge of
 * order X", and its UNIQUE index is what makes "exactly once" true in the
 * database rather than in a hope: two clerks invoicing parts of one order at the
 * same moment cannot both bill its delivery.
 *
 * ⚠ The account and the mappings are DATA, and they are written here rather
 * than left to a seeder, for the reason LaLaDia learned: a deploy step that says
 * "run the seeder" is a step that gets skipped, or run on the wrong thing. On a
 * fresh database the chart does not exist yet when migrations run, so this does
 * nothing and ChartOfAccountsSeeder + SalesAccountMappingSeeder supply both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->foreignId('billed_delivery_for_order_id')->nullable()->after('delivery_line_id')
                ->constrained('sales_orders')->restrictOnDelete();
            $table->unique('billed_delivery_for_order_id', 'invoice_lines_delivery_once_unique');
        });

        $income = DB::table('accounts')->where('code', '4000')->value('id');

        if ($income === null) {
            return;
        }

        $account = DB::table('accounts')->where('code', '4300')->value('id');

        if ($account === null) {
            $account = DB::table('accounts')->insertGetId([
                'code' => '4300',
                'name' => 'Delivery Charges Income',
                'type' => 'income',
                'parent_id' => $income,
                'is_postable' => true,
                'is_active' => true,
                'system_slug' => DB::table('accounts')->where('system_slug', 'delivery_income')->exists() ? null : 'delivery_income',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ⚠ Only for a business whose sales already post (it has a revenue
        // mapping). One that has never been configured for sales should fail
        // loudly on its first invoice, not quietly half-work.
        $businesses = DB::table('account_mappings')->where('key', 'sales.revenue')->distinct()->pluck('business_id');

        foreach ($businesses as $businessId) {
            $exists = DB::table('account_mappings')
                ->where('business_id', $businessId)
                ->where('key', 'sales.delivery_income')
                ->exists();

            if (! $exists) {
                DB::table('account_mappings')->insert([
                    'business_id' => $businessId,
                    'key' => 'sales.delivery_income',
                    'account_id' => $account,
                    'effective_from' => '2000-01-01',
                    'note' => 'Delivery charges on website orders (13 Sep 2026)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropForeign(['billed_delivery_for_order_id']);
            $table->dropUnique('invoice_lines_delivery_once_unique');
            $table->dropColumn('billed_delivery_for_order_id');
        });

        DB::table('account_mappings')->where('key', 'sales.delivery_income')->delete();

        $account = DB::table('accounts')->where('code', '4300')->value('id');

        // ⚠ Never delete an account something has posted to.
        if ($account !== null && ! DB::table('journal_lines')->where('account_id', $account)->exists()) {
            DB::table('accounts')->where('id', $account)->delete();
        }
    }
};
