<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Sales\Models\CustomerReceipt;
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

            // ⭐ The receipts and payments this voucher created for a customer or
            // supplier (finance decision C3) go with it: their journal line was
            // the voucher's, now reversed, so leaving the documents would make
            // the statement and ageing disagree with the ledger. Their
            // allocations cascade, so the invoices and bills are open again.
            CustomerReceipt::query()->where('voucher_id', $voucher->id)->get()->each->delete();
            SupplierPayment::query()->where('voucher_id', $voucher->id)->get()->each->delete();

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

    /**
     * ⭐ Correcting a mistake (12 Oct 2026, Joy's first live data entry): a Pathao
     * payout of 1,023.40 was posted as 102,340.00 on 1 Sep. Reversing it TODAY
     * and posting the copy TODAY left September 101,316.60 too high in sales and
     * bank until the day it was noticed, so the month's report and the bank
     * reconciliation were wrong while the totals looked right. A correction
     * belongs on the day of the transaction it corrects: the reversal is dated
     * the original voucher's date (PostJournal still refuses a closed period),
     * and the copy keeps that date and the bank's own reference, because it IS
     * the same bank line and the old voucher is no longer live. Pass `$on` to
     * reverse on another day.
     */
    public function andCopy(Voucher $voucher, User $by, string $reason, ?Carbon $on = null): Voucher
    {
        return DB::transaction(function () use ($voucher, $by, $reason, $on) {
            $reversed = $this->handle($voucher, $by, $reason, $on ?? $voucher->voucher_date->copy());

            return $this->copy($reversed, $by, correcting: true);
        });
    }

    /**
     * A new draft carrying everything but the number and the journal. A plain
     * Duplicate is a NEW payment: today's date and no cheque number. A correcting
     * copy (andCopy) keeps the original date and instrument, see above.
     */
    public function copy(Voucher $voucher, User $by, bool $correcting = false): Voucher
    {
        $voucher->loadMissing('lines');

        return $this->saveDraft->handle($voucher->type, [
            'voucher_date' => ($correcting ? $voucher->voucher_date : Carbon::today())->toDateString(),
            'money_account_id' => $voucher->money_account_id,
            'to_money_account_id' => $voucher->to_money_account_id,
            'counterparty' => $voucher->counterparty,
            'narration' => $voucher->narration,
            'instrument_type' => $voucher->instrument_type,
            // ⚠ A Duplicate gets no cheque number or date: it is a NEW payment, and a
            // second live voucher quoting the first one's cheque is how one cheque
            // gets booked twice. A correcting copy keeps them: the old one is reversed.
            'instrument_number' => $correcting ? $voucher->instrument_number : null,
            'instrument_date' => $correcting ? $voucher->instrument_date?->toDateString() : null,
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
