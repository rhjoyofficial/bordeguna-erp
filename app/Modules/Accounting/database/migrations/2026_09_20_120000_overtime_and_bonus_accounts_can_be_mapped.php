<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 6132 Bonus and 6133 Overtime have existed since the master expense chart,
 * with no slug, so no account mapping could ever point at them and payroll sent
 * both to Salaries and Wages (accountant meeting 19 Sep 2026, D1).
 *
 * ⚠ The chart seeder is firstOrCreate on the code and never rewrites a row that
 * already exists, so a slug added there reaches only a fresh database. This
 * gives the two live rows the same slugs. Set only where still empty.
 */
return new class extends Migration
{
    private const SLUGS = ['6132' => 'bonus_expense', '6133' => 'overtime_expense'];

    public function up(): void
    {
        foreach (self::SLUGS as $code => $slug) {
            DB::table('accounts')->where('code', $code)->whereNull('system_slug')->update(['system_slug' => $slug]);
        }
    }

    public function down(): void
    {
        DB::table('accounts')->whereIn('system_slug', array_values(self::SLUGS))->update(['system_slug' => null]);
    }
};
