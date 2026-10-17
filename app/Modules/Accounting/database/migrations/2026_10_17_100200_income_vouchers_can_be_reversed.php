<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Other income can be reversed and corrected (PHASE-0-CONTRACT v2, slice 3).
 *
 * The Reversible columns (kept and marked, never edited, never deleted), plus
 * `journal_id`: the one journal the voucher posted, which until now could only
 * be found through journals.source_type/source_id.
 *
 * ⚠⚠ THE BACKFILL GUESSES NOTHING (ChatGPT REPLY-6 4.10). `journal_id` is filled
 * only where EXACTLY ONE live journal (not a reversal, not reversed) has the
 * voucher as its source. A voucher with none or several is left empty and listed
 * by `php artisan erp:corrections-preflight` for a person to look at. Such a
 * voucher cannot be reversed or corrected until somebody has.
 */
return new class extends Migration
{
    private const VOUCHER_CLASS = 'App\\Modules\\Accounting\\Models\\IncomeVoucher';

    public function up(): void
    {
        Schema::table('income_vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_id')->nullable()->after('note');
            $table->date('reversed_on')->nullable()->after('created_by');
            $table->timestamp('reversed_at')->nullable()->after('reversed_on');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('reversal_reason');

            $table->foreign('journal_id', 'income_vouchers_journal_fk')->references('id')->on('journals')->restrictOnDelete();
            $table->foreign('reversed_by', 'income_vouchers_reversed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversal_journal_id', 'income_vouchers_reversal_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
        });

        $this->backfill();
    }

    /**
     * Public so a test can run exactly this against legacy shapes (zero or
     * several live journals) and prove it leaves them alone.
     */
    public function backfill(): void
    {
        $live = DB::table('journals as j')
            ->where('j.source_type', self::VOUCHER_CLASS)
            ->whereNull('j.reverses_journal_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journals as r')->whereColumn('r.reverses_journal_id', 'j.id'))
            ->groupBy('j.source_id')
            ->havingRaw('COUNT(*) = 1')
            ->selectRaw('j.source_id as voucher_id, MIN(j.id) as journal_id')
            ->get();

        foreach ($live as $row) {
            DB::table('income_vouchers')
                ->where('id', $row->voucher_id)
                ->whereNull('journal_id')
                ->update(['journal_id' => $row->journal_id]);
        }
    }

    public function down(): void
    {
        Schema::table('income_vouchers', function (Blueprint $table) {
            $table->dropForeign('income_vouchers_journal_fk');
            $table->dropForeign('income_vouchers_reversed_by_fk');
            $table->dropForeign('income_vouchers_reversal_jnl_fk');
            $table->dropColumn(['journal_id', 'reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id']);
        });
    }
};
