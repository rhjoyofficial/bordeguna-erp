<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\ChequeStatus;
use App\Modules\Accounting\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a cheque cleared, bounced or cancelled (finance decision C5).
 *
 * ⚠ Bounced and cancelled are final and REVERSE the voucher: the money the
 * voucher booked never moved. Cleared is final too; a cleared cheque that
 * later turns out wrong is a new voucher, not an edit here.
 */
class UpdateChequeStatus
{
    public function __construct(private readonly ReverseVoucher $reverse) {}

    /**
     * @throws ValidationException
     */
    public function handle(Voucher $voucher, string $status, mixed $on, ?string $note, User $by): ChequeStatus
    {
        if ($voucher->instrument_type !== 'cheque') {
            throw ValidationException::withMessages(['status' => 'That voucher was not paid by cheque.']);
        }

        if (! in_array($status, ['cleared', 'bounced', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose cleared, bounced or cancelled.']);
        }

        $date = Carbon::parse($on)->startOfDay();

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['status_on' => 'That date has not happened yet.']);
        }

        return DB::transaction(function () use ($voucher, $status, $date, $note, $by) {
            $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if (ChequeStatus::query()->where('voucher_id', $voucher->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'This cheque is already settled one way or the other.']);
            }

            if ($voucher->status !== VoucherStatus::Posted) {
                throw ValidationException::withMessages(['status' => 'Only a posted voucher\'s cheque can change status.']);
            }

            $record = ChequeStatus::create([
                'voucher_id' => $voucher->id,
                'status' => $status,
                'status_on' => $date,
                'note' => $note,
                'updated_by' => $by->id,
            ]);

            if ($status !== 'cleared') {
                $this->reverse->handle($voucher, $by, trim("Cheque {$voucher->instrument_number} {$status}".($note ? ": {$note}" : '')), $date);
            }

            return $record;
        });
    }
}
