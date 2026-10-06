<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 6478 Interest Expense (work queue item 14, 6 Oct 2026).
 *
 * The chart had 2210 Bank Loans and 4920 Interest Income but nowhere to put
 * the interest the business PAYS on a loan, so a loan instalment could only be
 * split by misfiling the interest as a bank charge or as Other Financial
 * Charges. Interest is a finance cost, so it sits with the other finance costs
 * under 6470 Financial and Payment Charges, as the next free code after 6477.
 *
 * A postable expense head is offered by every expense and voucher picker on
 * its own (payment/journal vouchers, expense vouchers, the transfer fee), so
 * nothing else needs to point at it. Slugged so a later posting can find it.
 *
 * Idempotent: inserts only when the code is missing, and gives an existing
 * 6478 the slug only when it has none. down() removes it only when nothing was
 * ever posted to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('accounts')->where('code', '6478')->first();

        if ($existing !== null) {
            if ($existing->system_slug === null
                && ! DB::table('accounts')->where('system_slug', 'interest_expense')->exists()) {
                DB::table('accounts')->where('id', $existing->id)->update(['system_slug' => 'interest_expense', 'updated_at' => now()]);
            }

            return;
        }

        $parentId = DB::table('accounts')->where('code', '6470')->value('id');

        if ($parentId === null) {
            // A chart without the master expense groups has not been seeded;
            // the chart seeder plants 6478 with everything else.
            return;
        }

        DB::table('accounts')->insert([
            'code' => '6478',
            'name' => 'Interest Expense',
            'type' => 'expense',
            'parent_id' => $parentId,
            'is_postable' => true,
            'system_slug' => 'interest_expense',
            'is_active' => true,
            'note' => 'Interest paid on bank loans (2210) and other borrowing.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('accounts')->where('code', '6478')->value('id');

        if ($id === null || DB::table('journal_lines')->where('account_id', $id)->exists()) {
            return;
        }

        DB::table('accounts')->where('id', $id)->delete();
    }
};
