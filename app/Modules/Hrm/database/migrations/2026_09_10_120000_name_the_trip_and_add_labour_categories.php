<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "I went Dhaka to Barisal for office work" (Joy, 10 September 2026).
 *
 * ⛔⛔ THE COMPANY COULD NOT SEE THE TRIP. He travels to Barisal, buys something
 * for the office, pays a mobile bill, hands cash to labourers - all out of his
 * own pocket, all on one claim with one approval, which the module already does.
 * What it could NOT do is say that those four spends were the Barisal trip. This
 * morning's redesign made that worse by removing the title field outright,
 * because for a single courier a title is the spend's own sentence typed twice.
 *
 * ⭐ SO THE NAME IS EARNED RATHER THAN ALWAYS DEMANDED. `purpose` becomes
 * NULLABLE: one spend needs no name and the claim describes itself from the line
 * it holds. The moment a SECOND spend joins, the claim stops being one purchase
 * and becomes a trip, and the screen asks what to call it before it can be sent.
 * That is exactly when the company needs to know, and never before.
 *
 * ⚠ Existing claims keep the text their employees typed. Nothing is blanked.
 *
 * ⚠⚠ AND HIS LABOURERS HAD NOWHERE TO GO. The accountant's chart holds 6095
 * Warehouse Labour and 6096 Loading and Unloading Labour, but not one of the
 * twenty-one employee-facing categories pointed at either, so "gave some money
 * to labour" could only be filed as the catch-all his own document forbids as a
 * default. Two categories, on accounts that already existed.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    private const CATEGORIES = [
        ['Daily Labour', 'দিনমজুরের খরচ', '6095'],
        ['Loading and Unloading', 'লোড/আনলোড খরচ', '6096'],
    ];

    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->text('purpose')->nullable()->change();
        });

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            if (! DB::table('expense_categories')->where('business_id', $businessId)->exists()) {
                continue;
            }

            $sort = (int) DB::table('expense_categories')->where('business_id', $businessId)->max('sort');

            foreach (self::CATEGORIES as [$name, $nameBn, $code]) {
                $accountId = DB::table('accounts')->where('code', $code)->value('id');

                if ($accountId === null) {
                    continue;
                }

                // Same rule as the hosting categories: never offer staff two
                // live categories that land on one account.
                $taken = DB::table('expense_categories')
                    ->where('business_id', $businessId)
                    ->where('account_id', $accountId)
                    ->where('is_active', true)
                    ->where('name', '!=', $name)
                    ->exists();

                if ($taken) {
                    continue;
                }

                DB::table('expense_categories')->updateOrInsert(
                    ['business_id' => $businessId, 'name' => $name],
                    [
                        'name_bn' => $nameBn,
                        'account_id' => $accountId,
                        'is_exception' => false,
                        // ⚠ A day labourer signs a voucher, not a printed bill.
                        // Demanding a receipt would push the spend off the books,
                        // which is the same argument Conveyance won on.
                        'receipt_required' => false,
                        'is_active' => true,
                        'sort' => ++$sort,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('expense_categories')->whereIn('name', array_column(self::CATEGORIES, 0))->delete();

        // ⚠ Anything still null has to say something before the column can
        // refuse nulls again.
        DB::table('expense_claims')->whereNull('purpose')->update(['purpose' => 'Expense claim']);

        Schema::table('expense_claims', function (Blueprint $table) {
            $table->text('purpose')->nullable(false)->change();
        });
    }
};
