<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
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
    public function handle(ExpenseVoucher $voucher, User $by, string $reason, bool $onOriginalDate = false, ?Carbon $on = null): ExpenseVoucher
    {
        // ⭐ Corrections wave 2 (24 Oct 2026): an expense claim's Reverse payment
        // hands the date it resolved (its own date, or the first open day).
        return $this->reverseDocument($voucher, $by, $reason, $onOriginalDate, $on);
    }

    /**
     * ⭐ Initial plan B24 (25 Oct 2026): the reversal inside the corrections
     * process (ExpenseVoucherCorrector), on the date the date policy resolved
     * and with the Owner's one-document pass for a closed month. The posting
     * is the same as the old Reverse: the mirror journal, the standing cost's
     * month due again.
     */
    public function forCorrection(ExpenseVoucher $voucher, User $by, string $reason, Carbon $on, ?ClosedPeriodPass $pass = null): ExpenseVoucher
    {
        return $this->reverseDocument($voucher, $by, $reason, true, $on, $pass);
    }

    /** One sentence why it cannot be reversed here, or null (the policy asks it). */
    public static function refusal(ExpenseVoucher $voucher): ?string
    {
        if (ExpenseClaim::query()->where('expense_voucher_id', $voucher->id)->exists()) {
            return "Expense voucher {$voucher->voucher_number} pays a staff expense claim, so reverse the payment on the claim instead.";
        }

        return null;
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
