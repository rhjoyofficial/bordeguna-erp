<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Sales\Corrections\Checks\ReturnMoneyCheck;
use App\Modules\Sales\Corrections\Checks\ReturnStockCheck;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ A sales return: Reverse (B13, 24 Oct 2026; Joy's R101, gap S5,
 * FINAL-PLAN "Sales return, customer refund: Reverse while the goods are still
 * in those batches and no money went back". Owner, Manager, Accountant:
 * sales.correct).
 *
 *   Reverse   the return is kept and marked; a mirror return takes the goods
 *             out of the same batches again and the customer owes again
 *   Correct   not offered: reverse it and record the right return
 *
 * A mirror (the reversal itself) offers nothing.
 *
 * `effectProfile()` = Ledger + Stock + Allocation, each covered (v2, C14).
 */
class SalesReturnActionPolicy extends SalesDocumentPolicy
{
    public const TYPE = 'sales.return';

    public const PERMISSION = 'sales.correct';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return SalesReturn::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Stock, EffectKind::Allocation],
            [SalesLedgerCheck::class, ReturnStockCheck::class, ReturnMoneyCheck::class],
        );
    }

    protected function offered(Model $document): array
    {
        /** @var SalesReturn $document */
        return $document->isMirror() ? [] : [DocumentAction::Reverse];
    }

    protected function permissionFor(Model $document, User $user, DocumentAction $action): string
    {
        return self::PERMISSION;
    }

    public function documentDate(Model $document): Carbon
    {
        return $document->returned_at->copy()->startOfDay();
    }

    /** The customer's credit: the figure the Tk 20,000 rule reads for a return. */
    public function totalMinor(Model $document): int
    {
        return abs(Money::ofTaka((string) $document->credit_total)->minor);
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->return_number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('sales.returns.show', $document);
    }
}
