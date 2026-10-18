<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Purchasing\Models\SupplierAdvance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * An advance paid by mistake, reversed with a reason (ruling P2; PUR-003's
 * rules: reason, today or its own date, kept and marked, once only).
 *
 *   the journal   Dr the money account (and TDS back) / Cr 1160
 *
 * ⚠⚠ In dependency order only. An advance that has been applied to a bill or
 * partly refunded is refused until that application or refund is reversed:
 * otherwise the bill would stay settled by money the ledger no longer holds.
 *
 * ⚠ An overpayment advance is reversed with its payment (one remittance, one
 * journal), and an opening advance is removed under Opening balances.
 */
class ReverseSupplierAdvance extends ReversePurchasingDocument
{
    public function __construct(ReverseJournal $reverseJournal, private readonly PostSupplierAdvanceJournals $posting)
    {
        parent::__construct($reverseJournal);
    }

    /**
     * @throws ValidationException
     */
    public function handle(SupplierAdvance $advance, User $by, string $reason, bool $onOriginalDate = false): SupplierAdvance
    {
        return $this->reverseDocument($advance, $by, $reason, $onOriginalDate);
    }

    protected function label(Model $document): string
    {
        return "Advance {$document->number}";
    }

    protected function originalDate(Model $document): Carbon
    {
        return Carbon::parse($document->paid_on->toDateString());
    }

    protected function journalOf(Model $document): ?Journal
    {
        return $this->posting->existingJournal($document);
    }

    protected function guard(Model $document): void
    {
        if ($document->kind === 'overpayment') {
            throw ValidationException::withMessages([
                'reason' => "{$this->label($document)} is the excess of supplier payment #{$document->supplier_payment_id}. Reverse that payment; the advance goes with it.",
            ]);
        }

        if ($document->kind === 'opening') {
            throw ValidationException::withMessages([
                'reason' => "{$this->label($document)} is an opening balance. Remove it under Accounting, Opening balances.",
            ]);
        }

        self::assertNothingDependsOn($document);
    }

    protected function restore(Model $document): array
    {
        return [];
    }

    /**
     * @throws ValidationException
     */
    public static function assertNothingDependsOn(SupplierAdvance $advance): void
    {
        $applied = $advance->applications()->live()->pluck('number');
        $refunded = $advance->refunds()->live()->pluck('number');

        if ($applied->isNotEmpty() || $refunded->isNotEmpty()) {
            throw ValidationException::withMessages([
                'reason' => "Advance {$advance->number} has been used: ".$applied->concat($refunded)->implode(', ').'. Reverse those first.',
            ]);
        }
    }
}
