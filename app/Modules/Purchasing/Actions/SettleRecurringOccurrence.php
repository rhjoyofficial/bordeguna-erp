<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Enums\RecurringOccurrenceStatus;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\RecurringExpenseOccurrence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What became of one period of a standing cost: it was paid, or it was skipped.
 *
 * ⚠⚠ RECORDING IS NOT POSTING. The voucher is written by the ordinary expense
 * screen through the ordinary action, with a human choosing to save it. This
 * class only ties the voucher that resulted back to the period it settled, so
 * "did we pay September's hosting?" has an answer and next month's reminder
 * stops. Nothing here touches the ledger.
 */
class SettleRecurringOccurrence
{
    public function recorded(RecurringExpenseOccurrence $occurrence, ExpenseVoucher $voucher, User $user): RecurringExpenseOccurrence
    {
        $this->assertOpen($occurrence);

        return DB::transaction(function () use ($occurrence, $voucher, $user) {
            $occurrence->update([
                'status' => RecurringOccurrenceStatus::Recorded,
                'expense_voucher_id' => $voucher->id,
                'settled_by' => $user->id,
                'settled_at' => now(),
            ]);

            return $occurrence->fresh();
        });
    }

    /**
     * ⚠ A reason is required. A month somebody decided not to pay for is a
     * decision, and a decision with no reason is indistinguishable next year
     * from an oversight.
     */
    public function skipped(RecurringExpenseOccurrence $occurrence, User $user, string $reason): RecurringExpenseOccurrence
    {
        $this->assertOpen($occurrence);

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'skipped_reason' => 'Say why this month is being skipped. Next year nobody will remember.',
            ]);
        }

        $occurrence->update([
            'status' => RecurringOccurrenceStatus::Skipped,
            'skipped_reason' => $reason,
            'settled_by' => $user->id,
            'settled_at' => now(),
        ]);

        return $occurrence->fresh();
    }

    /**
     * @throws ValidationException
     */
    private function assertOpen(RecurringExpenseOccurrence $occurrence): void
    {
        if ($occurrence->status->isOpen()) {
            return;
        }

        // ⛔ Settling twice would either double-count a month or quietly replace
        // the voucher that already answered for it.
        throw ValidationException::withMessages([
            'occurrence' => sprintf(
                '%s is already %s.',
                $occurrence->period_label,
                strtolower($occurrence->status->label()),
            ),
        ]);
    }
}
