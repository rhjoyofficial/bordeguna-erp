<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Staff fines go to a held liability (Finance policy B6, 29 Sep 2026): "Staff
 * fines go to a separate held liability, not income (Labour Act s.25, s.125,
 * s.127)".
 *
 * Until now `payroll.deduction.penalty` pointed at 6131 Salaries and Wages, so
 * a fine quietly lowered the wage cost: the company kept the money as if it
 * had never been wage. A fine is not the employer's to keep, so it is now
 * credited to its own liability, 2147 Staff Fines Held, beside the other
 * amounts held from pay (2140, 2145).
 *
 * What this does, per business that has the payroll mappings:
 *  - plants 2147 when missing (idempotent, by code);
 *  - when that business has NEVER had a penalty line on a payslip, re-points
 *    the existing mapping row(s) in place, because no posted figure ever
 *    depended on the old target (production, 6 Oct 2026: zero penalty lines,
 *    zero penalty adjustments, zero payroll journals);
 *  - otherwise supersedes it from today, the way AccountMapping::assign does,
 *    so a month already posted keeps meaning what it meant.
 *
 * Data only, no structure. Re-running is a no-op.
 */
return new class extends Migration
{
    private const KEY = 'payroll.deduction.penalty';

    private const NOTE = 'Finance policy B6: fines are held, not wage credit';

    public function up(): void
    {
        $held = $this->heldAccountId();

        if ($held === null) {
            return;
        }

        $today = now()->toDateString();

        $businessIds = DB::table('account_mappings')->where('key', self::KEY)->distinct()->pluck('business_id');

        foreach ($businessIds as $businessId) {
            if (! $this->everFined((int) $businessId)) {
                DB::table('account_mappings')
                    ->where('business_id', $businessId)->where('key', self::KEY)
                    ->where('account_id', '!=', $held)
                    ->update(['account_id' => $held, 'note' => self::NOTE, 'updated_at' => now()]);

                continue;
            }

            $open = DB::table('account_mappings')
                ->where('business_id', $businessId)->where('key', self::KEY)
                ->whereNull('effective_to')->orderByDesc('effective_from')->first();

            if ($open === null || (int) $open->account_id === $held) {
                continue;
            }

            if ((string) $open->effective_from >= $today) {
                DB::table('account_mappings')->where('id', $open->id)
                    ->update(['account_id' => $held, 'note' => self::NOTE, 'updated_at' => now()]);

                continue;
            }

            DB::table('account_mappings')->where('id', $open->id)
                ->update(['effective_to' => now()->subDay()->toDateString(), 'updated_at' => now()]);

            DB::table('account_mappings')->insert([
                'business_id' => $businessId,
                'key' => self::KEY,
                'account_id' => $held,
                'effective_from' => $today,
                'effective_to' => null,
                'note' => self::NOTE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $held = DB::table('accounts')->where('code', '2147')->value('id');
        $wages = DB::table('accounts')->where('system_slug', 'salaries_expense')->value('id');

        if ($held === null || $wages === null || DB::table('journal_lines')->where('account_id', $held)->exists()) {
            return;
        }

        DB::table('account_mappings')->where('key', self::KEY)->where('account_id', $held)
            ->update(['account_id' => $wages, 'updated_at' => now()]);
        DB::table('accounts')->where('id', $held)->delete();
    }

    private function heldAccountId(): ?int
    {
        $existing = DB::table('accounts')->where('code', '2147')->first();

        if ($existing !== null) {
            if ($existing->system_slug === null
                && ! DB::table('accounts')->where('system_slug', 'staff_fines_held')->exists()) {
                DB::table('accounts')->where('id', $existing->id)
                    ->update(['system_slug' => 'staff_fines_held', 'updated_at' => now()]);
            }

            return (int) $existing->id;
        }

        $parentId = DB::table('accounts')->where('code', '2100')->value('id');

        if ($parentId === null) {
            // No chart yet: the chart seeder plants 2147 with everything else.
            return null;
        }

        return (int) DB::table('accounts')->insertGetId([
            'code' => '2147',
            'name' => 'Staff Fines Held',
            'type' => 'liability',
            'parent_id' => $parentId,
            'is_postable' => true,
            'system_slug' => 'staff_fines_held',
            'is_active' => true,
            'note' => 'Fines deducted from pay (Finance policy B6). Held, never income.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function everFined(int $businessId): bool
    {
        return DB::table('payslip_lines')
            ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payroll_runs.business_id', $businessId)
            ->where('payslip_lines.code', 'penalty')
            ->exists();
    }
};
