<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Supplier payments in the corrections process (corrections wave 1, 20 Oct
 * 2026, FINAL-PLAN 3.3 "Supplier payment" and 4.2): Correct (date, amount,
 * account, which bills) and Reverse, both refused while the money line is
 * matched on a bank statement (BankMatchBlocker): the bank saw that money
 * move, so it is unmatched first, on purpose, by a person.
 *
 * `effectProfile()` = Ledger + BankMatch + Allocation (the bills it settled,
 * SupplierPaymentSettlementCheck).
 */
class SupplierPaymentActionPolicy extends PurchasingDocumentPolicy
{
    public const TYPE = 'purchasing.supplier_payment';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return SupplierPayment::class;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::BankMatch, EffectKind::Allocation],
            [LedgerEntryCheck::class, BankMatchBlocker::class, SupplierPaymentSettlementCheck::class],
        );
    }

    protected function offered(): array
    {
        return [DocumentAction::Correct, DocumentAction::Reverse];
    }

    public function documentDate(Model $document): Carbon
    {
        /** @var SupplierPayment $document */
        return Carbon::parse($document->paid_on->toDateString())->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return Money::ofTaka((string) $document->amount)->minor;
    }

    public function numberOf(Model $document): string
    {
        return "Payment #{$document->getKey()}";
    }

    public function urlOf(Model $document): ?string
    {
        return route('purchasing.payments.show', $document->getKey());
    }
}
