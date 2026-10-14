<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank statement matching, two holes (audit #11, 14 Oct 2026).
 *
 * ⚠ ONE CLAIM PER BOOK LINE. A manual match never asked whether another
 * statement line already held the book line, so one Tk 4,900 payment could be
 * "proven" by two bank rows and the reconciliation said both agreed. A unique
 * index on `matched_journal_line_id` makes it impossible rather than unlikely
 * (NULLs do not collide on MySQL or SQLite, so unmatched rows are untouched).
 * Any duplicate claims already there are released first, keeping the OLDEST
 * statement line's claim: the later ones go back to "unmatched" for a human.
 *
 * ⚠ ONE IMPORT PER FILE PER ACCOUNT. Importing the same CSV twice doubled every
 * line. `file_sha256` is the fingerprint of the file's bytes; the unique index
 * is per money account, so the same file can still be imported to the right
 * account after a mistake on the wrong one. Older imports keep NULL (nothing
 * to fingerprint after the fact) and never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('bank_statement_lines')
            ->whereNotNull('matched_journal_line_id')
            ->groupBy('matched_journal_line_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('matched_journal_line_id');

        foreach ($duplicates as $journalLineId) {
            $keep = DB::table('bank_statement_lines')->where('matched_journal_line_id', $journalLineId)->min('id');

            DB::table('bank_statement_lines')
                ->where('matched_journal_line_id', $journalLineId)
                ->where('id', '!=', $keep)
                ->update([
                    'match_status' => 'unmatched',
                    'matched_journal_line_id' => null,
                    'matched_by' => null,
                    'matched_at' => null,
                    'updated_at' => now(),
                ]);
        }

        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->unique('matched_journal_line_id', 'bsl_one_claim_per_book_line');
        });

        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->char('file_sha256', 64)->nullable()->after('filename');
            $table->unique(['money_account_id', 'file_sha256'], 'bsi_one_import_per_file');
        });
    }

    public function down(): void
    {
        // ⚠ MySQL refuses to drop an index a foreign key leans on. Both FKs
        // (matched_journal_line_id, money_account_id) got their own index when
        // the tables were created, so these two are free to go.
        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->dropUnique('bsi_one_import_per_file');
            $table->dropColumn('file_sha256');
        });

        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropUnique('bsl_one_claim_per_book_line');
        });
    }
};
