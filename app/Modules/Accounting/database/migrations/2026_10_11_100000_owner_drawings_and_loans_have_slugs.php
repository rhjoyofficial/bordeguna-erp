<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3200 Owner's Drawings and 2220 Loans from Owners get system slugs (Slice 0,
 * D8): `owners_drawings` and `owner_loans`.
 *
 * Both heads have been in the chart since it was first seeded, but with no
 * slug, so nothing could ask for "the owner's drawings" without hard-coding a
 * code. 3100 Owner's Capital has always had one (`owners_capital`); these are
 * its two siblings on the owner's side of the books.
 *
 * ⚠ Accounts are data written here, the 4300 rule; the seeder carries the same
 * slugs for a fresh database. The courier-statement migration's pattern: a
 * slug is set by CODE, only on a row that has none, and only when no other row
 * already holds it. A chart without the code is skipped, never invented.
 *
 * down() takes back only what up() could have set: the slug, on that code.
 */
return new class extends Migration
{
    private const SLUGS = [
        '3200' => 'owners_drawings',
        '2220' => 'owner_loans',
    ];

    public function up(): void
    {
        foreach (self::SLUGS as $code => $slug) {
            if (DB::table('accounts')->where('system_slug', $slug)->exists()) {
                continue;
            }

            DB::table('accounts')->where('code', $code)->whereNull('system_slug')
                ->update(['system_slug' => $slug, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::SLUGS as $code => $slug) {
            DB::table('accounts')->where('code', $code)->where('system_slug', $slug)
                ->update(['system_slug' => null, 'updated_at' => now()]);
        }
    }
};
