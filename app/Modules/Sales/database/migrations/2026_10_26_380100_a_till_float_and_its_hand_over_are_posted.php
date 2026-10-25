<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Joy's answer 5 (25 Oct 2026): "record the opening float in the books when
 * a shift opens". The open popup asks where the float came from (a cash box or
 * a bank account) and posts a money transfer into the till on the shift date;
 * the close asks where the counted cash is handed over to (optional) and posts
 * the transfer out.
 *
 *   pos_shifts.float_transfer_id      the transfer that put the float in the till
 *   pos_shifts.float_journal_id       its journal, left out of the shift's own flows
 *   pos_shifts.handover_transfer_id   the transfer that took the counted cash out
 *   pos_shifts.handover_journal_id    its journal, also left out of the flows
 *
 * Nothing is backfilled: a shift opened before this keeps its typed float and
 * no transfer, and the Z summary says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->foreignId('float_transfer_id')->nullable()->after('opening_float')
                ->constrained('money_transfers', 'id', 'pos_shifts_float_transfer_fk')->nullOnDelete();
            $table->foreignId('float_journal_id')->nullable()->after('float_transfer_id')
                ->constrained('journals', 'id', 'pos_shifts_float_journal_fk')->nullOnDelete();
            $table->foreignId('handover_transfer_id')->nullable()->after('over_short')
                ->constrained('money_transfers', 'id', 'pos_shifts_handover_transfer_fk')->nullOnDelete();
            $table->foreignId('handover_journal_id')->nullable()->after('handover_transfer_id')
                ->constrained('journals', 'id', 'pos_shifts_handover_journal_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dropForeign('pos_shifts_float_transfer_fk');
            $table->dropForeign('pos_shifts_float_journal_fk');
            $table->dropForeign('pos_shifts_handover_transfer_fk');
            $table->dropForeign('pos_shifts_handover_journal_fk');
            $table->dropColumn(['float_transfer_id', 'float_journal_id', 'handover_transfer_id', 'handover_journal_id']);
        });
    }
};
