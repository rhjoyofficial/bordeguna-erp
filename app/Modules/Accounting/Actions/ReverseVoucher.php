<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a posted voucher the only way the ledger allows: a reversing journal.
 *
 * ⚠⚠ THERE IS NO "EDIT". The original voucher, its number and its journal stay
 * exactly as they were; a second journal undoes them and both are marked. A
 * printed copy of the original therefore never disagrees with the books - it is
 * a true record of an entry that was later reversed, and says so.
 *
 * ⚠ A reason is required. "Why was PV-0042 reversed?" is the first thing an
 * auditor asks, and eight months later nobody remembers.
 *
 * `andCopy` returns a NEW DRAFT pre-filled from the reversed voucher - what an
 * accountant used to Tally's Alter actually wants, in one step.
 */
class ReverseVoucher
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly SaveVoucherDraft $saveDraft,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Voucher $voucher, User $by, string $reason, ?Carbon $on = null): Voucher
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why this voucher is being reversed.']);
        }

        return DB::transaction(function () use ($voucher, $by, $reason, $on) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);

            if (! $voucher->isPosted()) {
                throw ValidationException::withMessages([
                    'voucher' => $voucher->isDraft() ? 'A draft is deleted, not reversed.' : "{$voucher->title()} is already reversed.",
                ]);
            }

            $journal = $voucher->journal()->with('lines')->firstOrFail();

            // Period lock, future date and "once only" are PostJournal's and
            // ReverseJournal's own refusals; they are not repeated here.
            $reversal = $this->reverseJournal->handle(
                $journal,
                $by,
                $on,
                "Reversal of {$voucher->type->label()} {$voucher->voucher_number}: {$reason}",
            );

            $voucher->update([
                'status' => VoucherStatus::Reversed,
                'reversal_journal_id' => $reversal->id,
                'reversal_reason' => $reason,
                'reversed_by' => $by->id,
                'reversed_at' => now(),
            ]);

            return $voucher->fresh(['lines', 'journal', 'reversalJournal']);
        });
    }

    public function andCopy(Voucher $voucher, User $by, string $reason, ?Carbon $on = null): Voucher
    {
        return DB::transaction(function () use ($voucher, $by, $reason, $on) {
            $reversed = $this->handle($voucher, $by, $reason, $on);

            return $this->copy($reversed, $by);
        });
    }

    /** A new draft carrying everything but the number, the date and the journal. */
    public function copy(Voucher $voucher, User $by): Voucher
    {
        $voucher->loadMissing('lines');

        return $this->saveDraft->handle($voucher->type, [
            'voucher_date' => Carbon::today()->toDateString(),
            'money_account_id' => $voucher->money_account_id,
            'to_money_account_id' => $voucher->to_money_account_id,
            'counterparty' => $voucher->counterparty,
            'narration' => $voucher->narration,
            'instrument_type' => $voucher->instrument_type,
            // ⚠ Not the cheque number or date: a copy is a NEW payment, and a
            // second voucher quoting the first one's cheque is how one cheque
            // gets booked twice.
            'instrument_bank' => $voucher->instrument_bank,
            'amount' => $voucher->total()->toTaka(),
            'copied_from_id' => $voucher->id,
            'lines' => $voucher->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'side' => $line->side,
                'amount' => $line->amount()->toTaka(),
                'narration' => $line->narration,
                'party_id' => $line->party_id,
            ])->all(),
        ], $by);
    }
}
