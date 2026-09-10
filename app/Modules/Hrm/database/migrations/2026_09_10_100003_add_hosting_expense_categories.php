<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two categories the Hostinger bill had nowhere to go (Joy, 9 Sep 2026).
 *
 * ⚠⚠ HIS COMPLAINT WAS EXACT: "the Hostinger bill has no category." It was
 * never a missing account - the accountant's chart already holds 6056 Software
 * Subscription and 6057 ERP and Hosting - it was a missing employee-facing ROW
 * pointing at them. Section 19 of his document listed nineteen categories and
 * hosting was not one, so a real recurring spend could only be filed as
 * "Other Approved Business Expense", which is the one category his own document
 * says must never be the default.
 *
 * ⚠ CONVEYANCE STOPS DEMANDING A RECEIPT. A rickshaw gives you nothing to
 * photograph. Requiring proof for it does not produce proof; it pushes the
 * spend off the books, which is worse than an unreceipted line an approver can
 * see and question. This is the one category flagged, and the accountant can
 * flag or unflag any of them from the categories screen.
 *
 * Idempotent by name, and it plants nothing when the account is missing: a
 * category pointing at nothing would break the claim form for everybody.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    private const CATEGORIES = [
        ['Software Subscription', 'সফটওয়্যার সাবস্ক্রিপশন', '6056'],
        ['ERP and Hosting', 'ইআরপি/হোস্টিং', '6057'],
    ];

    public function up(): void
    {
        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            // Only where the accountant's categories were actually planted -
            // a business with none has not been set up, and seeding two rows
            // into an empty table would leave it with two categories.
            if (! DB::table('expense_categories')->where('business_id', $businessId)->exists()) {
                continue;
            }

            $sort = (int) DB::table('expense_categories')->where('business_id', $businessId)->max('sort');

            foreach (self::CATEGORIES as [$name, $nameBn, $code]) {
                $accountId = DB::table('accounts')->where('code', $code)->value('id');

                if ($accountId === null) {
                    continue;
                }

                DB::table('expense_categories')->updateOrInsert(
                    ['business_id' => $businessId, 'name' => $name],
                    [
                        'name_bn' => $nameBn,
                        'account_id' => $accountId,
                        'is_exception' => false,
                        'is_active' => true,
                        'sort' => ++$sort,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }

            DB::table('expense_categories')
                ->where('business_id', $businessId)
                ->where('name', 'Conveyance')
                ->update(['receipt_required' => false, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('expense_categories')
            ->whereIn('name', array_column(self::CATEGORIES, 0))
            ->delete();

        DB::table('expense_categories')
            ->where('name', 'Conveyance')
            ->update(['receipt_required' => true, 'updated_at' => now()]);
    }
};
