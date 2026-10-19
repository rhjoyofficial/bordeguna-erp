<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Supplier credit notes in the corrections process (corrections wave 1, 20
 * Oct 2026, FINAL-PLAN 3.3 "Supplier payment, supplier credit note: Reverse,
 * plus Correct"): Correct (number, date, amount, which bills) and Reverse.
 * No money line, so nothing can be bank-matched.
 *
 * `effectProfile()` = Ledger + Allocation (SupplierCreditNoteAllocationCheck).
 */
class SupplierCreditNoteActionPolicy extends PurchasingDocumentPolicy
{
    public const TYPE = 'purchasing.supplier_credit_note';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return SupplierCreditNote::class;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Allocation],
            [LedgerEntryCheck::class, SupplierCreditNoteAllocationCheck::class],
        );
    }

    protected function offered(): array
    {
        return [DocumentAction::Correct, DocumentAction::Reverse];
    }

    public function documentDate(Model $document): Carbon
    {
        /** @var SupplierCreditNote $document */
        return Carbon::parse($document->credit_date->toDateString())->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return Money::ofTaka((string) $document->total)->minor;
    }

    public function numberOf(Model $document): string
    {
        /** @var SupplierCreditNote $document */
        return mb_substr("Credit note {$document->credit_note_number}", 0, 40);
    }

    public function urlOf(Model $document): ?string
    {
        return route('purchasing.credit-notes.show', $document->getKey());
    }
}
