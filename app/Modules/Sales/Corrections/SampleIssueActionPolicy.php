<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Sales\Corrections\Checks\SampleLedgerCheck;
use App\Modules\Sales\Corrections\Checks\SampleStockCheck;
use App\Modules\Sales\Models\SampleIssue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Samples (Joy, 25 Oct 2026): a GIVEN sample in the corrections process.
 *
 *   Reverse   the goods back onto the batches they left and the journal
 *             mirrored (ReverseSampleIssue), on the sample's own date while
 *             the month is open. Owner, Manager and Finance (`sales.correct`,
 *             the people who correct sales documents): Staff may give a
 *             sample, not take one back.
 *   Correct   not offered: a wrong sample is reversed and given again, so the
 *             new one meets the approval limit like the first.
 *
 * A sample still waiting for approval is withdrawn by its approver's reject
 * (the approvals inbox), not here. Blockers: already reversed, a correction
 * already waiting, a batch re-costed since (SampleStockCheck), a broken
 * ledger entry (SampleLedgerCheck), the delivery money matched on the bank
 * statement (BankMatchBlocker). The Tk 20,000 second person reads the cost
 * plus the delivery charge.
 */
class SampleIssueActionPolicy extends SalesDocumentPolicy
{
    public const TYPE = 'sales.sample_issue';

    public const PERMISSION = 'sales.correct';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return SampleIssue::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('sales.samples.manage') || $user->can('sales.reports.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var SampleIssue $document */
        return match (true) {
            $document->isPosted() => DocumentStage::Done,
            $document->isWaiting() => DocumentStage::Waiting,
            default => DocumentStage::ApprovedNoEffect,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Stock, EffectKind::Ledger, EffectKind::BankMatch],
            [SampleStockCheck::class, SampleLedgerCheck::class, BankMatchBlocker::class],
        );
    }

    protected function offered(Model $document): array
    {
        /** @var SampleIssue $document */
        return $document->isPosted() ? [DocumentAction::Reverse] : [];
    }

    protected function permissionFor(Model $document, User $user, DocumentAction $action): string
    {
        return self::PERMISSION;
    }

    public function documentDate(Model $document): Carbon
    {
        /** @var SampleIssue $document */
        return $document->given_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var SampleIssue $document */
        return (int) $document->cost_total_paisa + Money::ofTaka((string) $document->delivery_charge)->minor;
    }

    public function numberOf(Model $document): string
    {
        /** @var SampleIssue $document */
        return (string) $document->number;
    }

    public function urlOf(Model $document): ?string
    {
        /** @var SampleIssue $document */
        return route('sales.samples.index', ['focus' => $document->id]);
    }
}
