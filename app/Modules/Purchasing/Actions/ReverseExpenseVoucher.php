<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Purchasing\Enums\RecurringOccurrenceStatus;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\RecurringExpenseOccurrence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * PUR-003 (owner decision 14 Oct 2026): an expense voucher reversed.
 *
 *   the journal   Dr the money account / Cr the expense heads
 *   the month     a standing cost's month this voucher settled is DUE again,
 *                 so the reminder comes back until the corrected voucher is
 *                 recorded
 *
 * Refused while a staff expense claim names this voucher as its payment: the
 * claim would stay "paid" with nothing behind it.
 */
class ReverseExpenseVoucher extends ReversePurchasingDocument
{
    /**
     * @throws ValidationException
     */
    public function handle(ExpenseVoucher $voucher, User $by, string $reason, bool $onOriginalDate = false): ExpenseVoucher
    {
        return $this->reverseDocument($voucher, $by, $reason, $onOriginalDate);
    }

    protected function label(Model $document): string
    {
        return "Expense voucher {$document->voucher_number}";
    }

    protected function originalDate(Model $document): Carbon
    {
        return Carbon::parse($document->spent_on->toDateString());
    }

    protected function journalOf(Model $document): ?Journal
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }

    protected function guard(Model $document): void
    {
        if (ExpenseClaim::query()->where('expense_voucher_id', $document->id)->exists()) {
            throw ValidationException::withMessages([
                'reason' => "{$this->label($document)} pays a staff expense claim, so reversing it here would leave that claim marked paid with nothing behind it.",
            ]);
        }
    }

    protected function restore(Model $document): array
    {
        RecurringExpenseOccurrence::query()
            ->where('expense_voucher_id', $document->id)
            ->get()
            ->each(fn (RecurringExpenseOccurrence $occurrence) => $occurrence->update([
                'status' => RecurringOccurrenceStatus::Due,
                'expense_voucher_id' => null,
                'settled_by' => null,
                'settled_at' => null,
            ]));

        return [];
    }
}
