<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 1 (20 Oct 2026, FINAL-PLAN 3.3 "Supplier bill, unpaid:
 * Fix details (due date, note, bill number typo)", decision 4): text on a
 * posted bill that moves no money, so no journal is written or reversed.
 *
 *   bill number   the supplier's number typed wrong (the same paper, one
 *                 character off); still unique among the supplier's live bills
 *   due date      a supplier who agreed a later date, or a typo (ruling P6:
 *                 recorded as "changed", with the reason)
 *   note          our own note
 *
 * ⚠ Allowed in a closed month too: nothing in the books changes. The old and
 * the new values, who, when and why are in the bill's history and the
 * Corrections report (activity log `corrections`, event `fix_details`). The
 * posted journal keeps the old number in its memo: a journal is never edited.
 *
 * ⚠ Stale guard: the screen sends the fingerprint of what it showed; a bill
 * that changed since is refused, never overwritten.
 */
class FixSupplierBillDetails
{
    public const FIELDS = ['bill_number', 'due_date', 'note'];

    /** What the screen showed, so a change made since is caught. */
    public static function fingerprint(SupplierBill $bill): string
    {
        return hash('sha256', implode('|', [$bill->bill_number, $bill->due_date?->toDateString(), (string) $bill->note, $bill->reversed_at?->toIso8601String()]));
    }

    /**
     * @param  array{bill_number?: ?string, due_date?: ?string, note?: ?string}  $changes
     *
     * @throws ValidationException
     */
    public function handle(SupplierBill $bill, array $changes, ActionReason $reason, User $by, ?string $seen = null): SupplierBill
    {
        return DB::transaction(function () use ($bill, $changes, $reason, $by, $seen) {
            $bill = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($seen !== null && ! hash_equals(self::fingerprint($bill), $seen)) {
                throw ValidationException::withMessages(['stale' => 'This record changed after you opened it. Please look again.']);
            }

            if ($bill->isReversed()) {
                throw ValidationException::withMessages(['bill_number' => "Bill {$bill->bill_number} was reversed, so its details stay as they were."]);
            }

            $before = ['bill_number' => $bill->bill_number, 'due_date' => $bill->due_date?->toDateString(), 'note' => $bill->note];
            $after = $before;

            if (array_key_exists('bill_number', $changes)) {
                $number = trim((string) $changes['bill_number']);

                if ($number === '') {
                    throw ValidationException::withMessages(['bill_number' => "Type the supplier's bill number."]);
                }

                $taken = SupplierBill::query()->where('supplier_id', $bill->supplier_id)->where('bill_number', $number)
                    ->whereNull('reversed_at')->whereKeyNot($bill->id)->exists();

                if ($taken) {
                    throw ValidationException::withMessages(['bill_number' => "Bill {$number} has already been recorded for this supplier."]);
                }

                $after['bill_number'] = $number;
            }

            if (array_key_exists('due_date', $changes)) {
                $due = $changes['due_date'] === null || $changes['due_date'] === '' ? null : Carbon::parse($changes['due_date'])->toDateString();

                if ($due !== null && $due < $bill->bill_date->toDateString()) {
                    throw ValidationException::withMessages(['due_date' => 'The due date cannot be before the bill date.']);
                }

                $after['due_date'] = $due;
            }

            if (array_key_exists('note', $changes)) {
                $note = trim((string) $changes['note']);
                $after['note'] = $note === '' ? null : $note;
            }

            if ($after === $before) {
                throw ValidationException::withMessages(['bill_number' => 'Nothing was changed. Change what was wrong, or go back.']);
            }

            $fill = ['bill_number' => $after['bill_number'], 'note' => $after['note']];

            if ($after['due_date'] !== $before['due_date']) {
                $fill += ['due_date' => $after['due_date'], 'due_date_source' => $after['due_date'] === null ? null : 'changed', 'due_date_reason' => mb_substr($reason->display(), 0, 500)];
            }

            $bill->forceFill($fill)->save();

            $changed = array_values(array_filter(self::FIELDS, fn (string $field) => $before[$field] !== $after[$field]));

            CorrectionLog::event('fix_details', $by, $bill, [
                'number' => "Bill {$bill->bill_number}",
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
                'reason' => $reason->display(),
            ]);

            return $bill->fresh();
        });
    }
}
