<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Loan Register finds its interest and tax heads by SLUG, never by code
 * (loans plan v2, phase 2, 14 Oct 2026):
 *
 *   4920 Interest Income      interest_income      interest earned on a loan given
 *   1190 Advance Income Tax   advance_income_tax   TDS a borrower withheld from
 *                                                  interest it paid us
 *
 * Both heads exist on production with no slug. 6478 (interest_expense), 2180
 * (tds_payable) and 6406 (bad_debts) already carry theirs.
 *
 * ⚠ Gives an existing head its slug only when it has none and nothing else
 * holds the slug; a head that already carries ANOTHER slug, or a slug held by
 * another code, stops the migration with a sentence rather than guessing.
 * Creates a head only on a chart that has its parent and lacks the code.
 * Idempotent: a second run changes nothing.
 *
 * ⚠ MySQL: codes are bound as STRINGS.
 */
return new class extends Migration
{
    /** code => [name, slug, type, parent code, note] */
    private const HEADS = [
        '4920' => ['Interest Income', 'interest_income', 'income', '4900', 'Interest earned on money lent (Loan Register) and on deposits.'],
        '1190' => ['Advance Income Tax', 'advance_income_tax', 'asset', '1100', 'Tax deducted at source by customers and borrowers, set against the year\'s tax bill.'],
    ];

    public function up(): void
    {
        foreach (self::HEADS as $code => [$name, $slug, $type, $parentCode, $note]) {
            $code = (string) $code;
            $existing = DB::table('accounts')->where('code', $code)->first();
            $holder = DB::table('accounts')->where('system_slug', $slug)->first();

            if ($holder !== null && ($existing === null || (int) $holder->id !== (int) $existing->id)) {
                throw new RuntimeException("The slug {$slug} is already held by account {$holder->code} {$holder->name}, not {$code}. Move it by hand before this migration runs.");
            }

            if ($existing !== null) {
                if ($existing->system_slug === null) {
                    DB::table('accounts')->where('id', $existing->id)->update(['system_slug' => $slug, 'updated_at' => now()]);
                } elseif ($existing->system_slug !== $slug) {
                    throw new RuntimeException("Account {$code} {$existing->name} already carries the slug {$existing->system_slug}; the Loan Register needs {$slug} there. Decide which by hand.");
                }

                continue;
            }

            $parentId = DB::table('accounts')->where('code', (string) $parentCode)->value('id');

            if ($parentId === null) {
                // A chart the seeder has not planted; the seeder plants both.
                continue;
            }

            DB::table('accounts')->insert([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'parent_id' => $parentId,
                'is_postable' => true,
                'system_slug' => $slug,
                'is_active' => true,
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Takes the slugs back. The heads stay: they existed before and carry
     * postings of their own.
     */
    public function down(): void
    {
        foreach (self::HEADS as $code => [, $slug]) {
            DB::table('accounts')->where('code', (string) $code)->where('system_slug', $slug)->update(['system_slug' => null, 'updated_at' => now()]);
        }
    }
};
