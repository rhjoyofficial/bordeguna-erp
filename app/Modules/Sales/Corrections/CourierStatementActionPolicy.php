<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Sales\Corrections\Checks\CourierStatementCheck;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\CourierStatement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ A posted courier payout statement: Reverse (B13, 24 Oct 2026; Joy's R100,
 * gap S4, FINAL-PLAN "Courier statement, posted: Reverse posting, then import
 * the corrected statement", Owner, Manager, Accountant: sales.correct).
 *
 *   Reverse   the statement is kept and marked Reversed, its journal mirrored
 *             on the day it was posted on, its parcel claims released, and the
 *             same file or invoice id can be imported and posted again
 *
 * A draft has its own Delete (the statements screen); nothing here.
 * Refused while the deposit is matched on the bank statement, and while a
 * loss claim or a later payout stands on it.
 *
 * `effectProfile()` = Ledger + BankMatch + External, each covered (v2, C14).
 */
class CourierStatementActionPolicy extends SalesDocumentPolicy
{
    public const TYPE = 'sales.courier_statement';

    public const PERMISSION = 'sales.correct';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return CourierStatement::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::BankMatch, EffectKind::External],
            [SalesLedgerCheck::class, BankMatchBlocker::class, CourierStatementCheck::class],
        );
    }

    protected function offered(Model $document): array
    {
        /** @var CourierStatement $document */
        return $document->status === CourierStatement::DRAFT ? [] : [DocumentAction::Reverse];
    }

    protected function permissionFor(Model $document, User $user, DocumentAction $action): string
    {
        return self::PERMISSION;
    }

    /** The day its journal was posted on (a closed-month statement posts on the first open day). */
    public function documentDate(Model $document): Carbon
    {
        return Carbon::parse(($document->posted_on ?? $document->statement_date)->toDateString())->startOfDay();
    }

    /** This business's share of the payout. */
    public function totalMinor(Model $document): int
    {
        return abs(Money::ofTaka((string) ($document->share_amount ?? '0'))->minor);
    }

    public function numberOf(Model $document): string
    {
        return "Statement {$document->statement_reference}";
    }

    public function urlOf(Model $document): ?string
    {
        return route('sales.courier-statements.show', $document);
    }
}
