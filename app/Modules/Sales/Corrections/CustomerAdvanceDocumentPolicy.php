<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Sales\Corrections\Checks\CustomerAdvanceUseCheck;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ The customer advance documents' shared policy (B11, 24 Oct 2026): an
 * advance (CADV), its use on an invoice (CAAP) and its refund (CARF).
 *
 *   Reverse   the document is kept and marked, its journal mirrored on its own
 *             date while the month is open. Owner and Finance
 *             (sales.receipts.correct), the people who correct receipts.
 *   Correct   not offered: the right one is entered again on the screen it
 *             came from (Receive payment, the invoice, Advances), which keeps
 *             every rule of that screen.
 *
 * Refused in dependency order (an advance with a live use or refund) and while
 * its money line is matched on the bank statement.
 *
 * `effectProfile()` = Ledger + BankMatch + Allocation, each covered (v2, C14).
 */
abstract class CustomerAdvanceDocumentPolicy extends SalesDocumentPolicy
{
    public const PERMISSION = 'sales.receipts.correct';

    abstract protected function dateColumn(): string;

    public function canView(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::BankMatch, EffectKind::Allocation],
            [SalesLedgerCheck::class, BankMatchBlocker::class, CustomerAdvanceUseCheck::class],
        );
    }

    protected function offered(Model $document): array
    {
        return [DocumentAction::Reverse];
    }

    protected function permissionFor(Model $document, User $user, DocumentAction $action): string
    {
        return self::PERMISSION;
    }

    public function documentDate(Model $document): Carbon
    {
        return $document->{$this->dateColumn()}->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return Money::ofTaka((string) $document->amount)->minor;
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('sales.advances.index', ['customer_id' => $document->customer_id]);
    }
}
