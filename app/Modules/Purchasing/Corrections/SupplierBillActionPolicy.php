<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Supplier bills in the corrections process (corrections wave 1, 20 Oct
 * 2026, FINAL-PLAN 3.3 and 4.2): which buttons, which blockers.
 *
 *   Correct       reverse the bill on its own date and record the right one
 *                 in one step; a PAID bill keeps its payments and credit notes,
 *                 which move to the corrected bill ("Correct paid bill")
 *   Reverse       only while nothing paid or credited it
 *   Fix details   due date, note, the supplier's bill number typed wrong: text
 *                 only, no journal moves, any time (SupplierBillController)
 *
 * `effectProfile()` = Ledger (LedgerEntryCheck) + Allocation (what settled it,
 * SupplierBillSettlementCheck). A bill moves no stock (the goods receipt did)
 * and no money line, so nothing can be bank-matched on it.
 *
 * ⚠ An opening bill (its journal is the opening balance's) and a landed cost's
 * bill (its journal is the landed cost's) are corrected where they came from.
 */
class SupplierBillActionPolicy extends PurchasingDocumentPolicy
{
    public const TYPE = 'purchasing.supplier_bill';

    /** Fix details is text: who may record a bill may fix its text too. */
    public const FIX_PERMISSIONS = ['purchasing.bill.record', 'accounting.correct'];

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return SupplierBill::class;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Allocation],
            [LedgerEntryCheck::class, SupplierBillSettlementCheck::class],
        );
    }

    protected function offered(): array
    {
        return [DocumentAction::Correct, DocumentAction::Reverse];
    }

    public function documentDate(Model $document): Carbon
    {
        /** @var SupplierBill $document */
        return Carbon::parse($document->bill_date->toDateString())->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return Money::ofTaka((string) $document->total)->minor;
    }

    protected function ownBlockers(Model $document, DocumentAction $action): array
    {
        /** @var SupplierBill $document */
        if ($document->is_opening) {
            return [new Blocker('opening_bill', "Bill {$document->bill_number} is an opening balance. Correct it under Accounting, Opening balances.")];
        }

        return [];
    }

    /** Correct and Reverse (primary), then Fix details (secondary). */
    public function actionsFor(Model $document, User $user): ActionSet
    {
        $set = parent::actionsFor($document, $user);

        return new ActionSet($set->stage, [...$set->actions, $this->fixDetails($document, $user)]);
    }

    /**
     * Fix details: not a correction (no journal, no date), so the service never
     * runs it; this only tells the screen whether to show it, and why not.
     */
    public function fixDetails(Model $document, User $user): ActionAvailability
    {
        /** @var SupplierBill $document */
        $blockers = [];

        if (! $user->canAny(self::FIX_PERMISSIONS)) {
            $blockers[] = Blocker::noPermission(DocumentAction::FixDetails);
        }

        if ($document->isReversed()) {
            $blockers[] = new Blocker('already_reversed', "Bill {$document->bill_number} was reversed, so its details stay as they were.");
        }

        return new ActionAvailability(
            action: DocumentAction::FixDetails,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::FIX_PERMISSIONS[0],
            primary: false,
            requiresReason: true,
            reasonContext: 'fix_details',
        );
    }

    public function numberOf(Model $document): string
    {
        /** @var SupplierBill $document */
        return mb_substr("Bill {$document->bill_number}", 0, 40);
    }

    public function urlOf(Model $document): ?string
    {
        return route('purchasing.bills.show', $document->getKey());
    }
}
