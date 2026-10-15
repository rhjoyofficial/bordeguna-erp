<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two payroll deductions that are simply less wage get mapping rows of their
 * own (found 15 Oct 2026 on the September payroll).
 *
 * Without a row they fell through to `payroll.deduction.default`, which is
 * 2140 Employee Deductions Payable, a LIABILITY meant for money held for a
 * third party (tax, PF). Munna's part-month cut (code `proration`, 10,000) was
 * credited there while the full 12,000 stayed in 6131 Salaries and Wages: the
 * wage cost overstated by 10,000 and a 10,000 debt to nobody, balancing
 * perfectly. The same would have happened to a `recovery` (a downward salary
 * revision taking back salary already paid). Both now credit the wage cost
 * back, exactly like `absent`, `lwp` and `late_early`.
 *
 * What this does, per business that already has payroll mappings:
 *  - for each key it has NO row for, inserts one pointing at the account with
 *    the slug `salaries_expense`, from 2000-01-01, as PayrollAccountMappingSeeder
 *    does, so a month already in the system (September) can be re-posted right;
 *  - leaves a key that has ANY row alone, whatever it points at: somebody
 *    chose it, and this migration does not overrule a choice.
 *
 * ⚠ It changes no posted journal. Journal lines are immutable; a month already
 * posted wrong is corrected by reversing and re-posting that month, which is a
 * separate, deliberate step.
 *
 * ⚠ The chart is shared and its codes have been renumbered before, so the
 * account is found by its system slug, never by its code.
 *
 * Data only, no structure. Re-running is a no-op.
 */
return new class extends Migration
{
    /** key => system slug of the account it points at. */
    private const MAPPINGS = [
        'payroll.deduction.proration' => 'salaries_expense',
        'payroll.deduction.recovery' => 'salaries_expense',
    ];

    private const FROM = '2000-01-01';

    private const NOTE = 'Less wage, not a deduction held for anyone (15 Oct 2026)';

    public function up(): void
    {
        $businessIds = DB::table('account_mappings')
            ->where('key', 'like', 'payroll.%')
            ->distinct()
            ->orderBy('business_id')
            ->pluck('business_id');

        foreach (self::MAPPINGS as $key => $slug) {
            $accountId = DB::table('accounts')->where('system_slug', $slug)->value('id');

            if ($accountId === null) {
                // No chart to point at: the seeder maps it once the chart exists.
                continue;
            }

            foreach ($businessIds as $businessId) {
                $exists = DB::table('account_mappings')
                    ->where('business_id', $businessId)
                    ->where('key', $key)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('account_mappings')->insert([
                    'business_id' => $businessId,
                    'key' => $key,
                    'account_id' => $accountId,
                    'effective_from' => self::FROM,
                    'effective_to' => null,
                    'note' => self::NOTE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Only the rows this migration wrote, recognised by their note. A row
        // somebody set by hand since is theirs and stays.
        DB::table('account_mappings')
            ->whereIn('key', array_keys(self::MAPPINGS))
            ->where('note', self::NOTE)
            ->delete();
    }
};
