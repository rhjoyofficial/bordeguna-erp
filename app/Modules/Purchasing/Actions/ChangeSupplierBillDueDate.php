<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Ruling P6: an authorised change to a recorded bill's due date, with a
 * reason.
 *
 * The due date was worked out once, when the bill was recorded, and nothing
 * recomputes it; a supplier who agrees a later date is the one case it moves.
 * ⚠ A due date is not a figure in the books: no journal is written or
 * reversed, so this is an edit of one field, never of posted history. The
 * old date, the new date, the reason and who did it are kept in the bill's
 * activity log (SupplierBill logs due_date and due_date_reason).
 */
class ChangeSupplierBillDueDate
{
    public function handle(SupplierBill $bill, mixed $dueDate, string $reason, User $changedBy): SupplierBill
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Say why the due date is changing.');
        }

        if ($bill->isReversed()) {
            throw new InvalidArgumentException("Bill {$bill->bill_number} is reversed; it owes nothing, so it has no due date to change.");
        }

        $date = Carbon::parse($dueDate)->startOfDay();

        if ($date->toDateString() < $bill->bill_date->toDateString()) {
            throw new InvalidArgumentException('The due date cannot be before the bill date.');
        }

        if ($bill->due_date?->toDateString() === $date->toDateString()) {
            throw new InvalidArgumentException('That is already the due date.');
        }

        return DB::transaction(function () use ($bill, $date, $reason, $changedBy) {
            $locked = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'due_date' => $date,
                'due_date_source' => 'changed',
                'due_date_reason' => mb_substr($reason, 0, 500),
            ])->save();

            // The log line names who changed it, even when no one is signed in
            // (a console run), and keeps the reason with the old and new date.
            activity()->performedOn($locked)->causedBy($changedBy)
                ->withProperties(['old' => $bill->due_date?->toDateString(), 'new' => $date->toDateString(), 'reason' => $reason])
                ->log('due date changed');

            return $locked;
        });
    }
}
