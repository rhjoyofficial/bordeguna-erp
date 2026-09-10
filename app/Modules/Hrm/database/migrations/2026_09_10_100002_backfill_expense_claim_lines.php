<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every claim that already exists becomes a one-line claim.
 *
 * ⚠⚠ NOTHING ABOUT AN EXISTING CLAIM'S JOURNALS CHANGES. Eight claims are live,
 * six of them approved and posted, one settled. This migration copies the
 * header's own figures down into a single line and moves the receipt with them;
 * the amounts, the accounts, the approval journals and the settlement are
 * untouched. A one-line claim reads and posts exactly as it did before, because
 * the line says what the header said.
 *
 * ⚠⚠ THE RECEIPT MOVES RATHER THAN BEING COPIED. Evidence duplicated is
 * evidence that can disagree with itself: two rows pointing at one file, and a
 * later deletion leaving a dangling one. The file on disk is not touched - only
 * the row that says which record it belongs to - and `down()` puts it back.
 *
 * ⚠ `submitted_at` is set to the row's creation time, not to now(). Every
 * existing claim was submitted the moment it was created, because until today
 * there was no draft to sit in, and stamping them all with today's date would
 * invent a history that did not happen.
 */
return new class extends Migration
{
    private const CLAIM = 'App\Modules\Hrm\Models\ExpenseClaim';

    private const LINE = 'App\Modules\Hrm\Models\ExpenseClaimLine';

    public function up(): void
    {
        DB::table('expense_claims')->whereNull('submitted_at')->update([
            'submitted_at' => DB::raw('created_at'),
        ]);

        $claims = DB::table('expense_claims')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('expense_claim_lines')
                ->whereColumn('expense_claim_lines.expense_claim_id', 'expense_claims.id'))
            ->get();

        foreach ($claims as $claim) {
            $lineId = DB::table('expense_claim_lines')->insertGetId([
                'business_id' => $claim->business_id,
                'expense_claim_id' => $claim->id,
                'spent_on' => $claim->spent_on,
                'expense_category_id' => $claim->expense_category_id,
                'expense_account_id' => $claim->expense_account_id,
                'amount' => $claim->amount,
                'description' => $claim->purpose,
                'sort' => 0,
                'created_at' => $claim->created_at,
                'updated_at' => $claim->updated_at,
            ]);

            DB::table('attachments')
                ->where('attachable_type', self::CLAIM)
                ->where('attachable_id', $claim->id)
                ->update(['attachable_type' => self::LINE, 'attachable_id' => $lineId]);
        }
    }

    public function down(): void
    {
        // Send each line's receipts home to its claim before the lines go.
        $lines = DB::table('expense_claim_lines')->get(['id', 'expense_claim_id']);

        foreach ($lines as $line) {
            DB::table('attachments')
                ->where('attachable_type', self::LINE)
                ->where('attachable_id', $line->id)
                ->update(['attachable_type' => self::CLAIM, 'attachable_id' => $line->expense_claim_id]);
        }

        DB::table('expense_claim_lines')->delete();
    }
};
