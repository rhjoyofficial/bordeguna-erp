<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1170 takes postings again (found 3 Oct 2026).
 *
 * On 21 Sep 1170 was renamed "Short Terms Loans & Advance", turned into a
 * heading, and given two hand-made heads (1171, 1172) for two employees. The
 * payroll mappings (`payroll.employee_advances` and the loan recovery keys)
 * still point at 1170, so every loan disbursement and every payroll that
 * recovers a loan was refused from then on.
 *
 * Since 27 Sep every employee already gets a system head under 1170 (EntityLedgers),
 * and the money posts to 1170 with the employee as the party - the same way
 * customer money posts to 1140. So:
 *  - 1170 becomes postable again. Its new name is kept.
 *  - 1171 and 1172 are deactivated when nothing was ever posted to them; the
 *    system heads 1170-BDG-000016 and 1170-BDG-000012 already stand for those
 *    two employees.
 *
 * Data only, no structure. down() puts the flags back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->where('code', '1170')->update(['is_postable' => true, 'updated_at' => now()]);

        foreach (['1171', '1172'] as $code) {
            $id = DB::table('accounts')->where('code', $code)->value('id');

            if ($id === null || DB::table('journal_lines')->where('account_id', $id)->exists()) {
                continue;
            }

            DB::table('accounts')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('accounts')->where('code', '1170')->update(['is_postable' => false, 'updated_at' => now()]);
        DB::table('accounts')->whereIn('code', ['1171', '1172'])->update(['is_active' => true, 'updated_at' => now()]);
    }
};
