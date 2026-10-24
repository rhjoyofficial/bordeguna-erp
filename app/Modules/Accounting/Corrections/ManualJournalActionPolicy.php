<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 3.1 "Manual journal
 * (including opening balance): Reverse with reason and date choice, Reverse
 * and copy, Correct while open").
 *
 * A manual journal has no document behind it: the journal IS the record, so
 * the type's model is Journal itself, limited to journals with no source that
 * are not reversals.
 *
 *   Reverse   its own date by default, or Today while the month is open
 *   Correct   while the month is open: the mirror on its own date, and a new
 *             journal from the edited form, read by the same ManualJournalForm
 *             the record screen uses (a subsystem's own head is refused, as
 *             there; an opening balance keeps going to Opening Balance Equity)
 *
 * ⚠ A closed month is refused, also for the Owner. The Owner's one-document
 * pass names a document, and a manual journal has none (the pass refuses "a
 * journal with no document behind it"), so a closed-month mistake is put right
 * by a new journal in an open month. Recorded as a contract gap (24 Oct 2026).
 *
 * Owner only, the same as posting one (`accounting.journal.post`).
 */
class ManualJournalActionPolicy extends AccountsDocumentPolicy
{
    public const TYPE = 'accounting.manual_journal';

    public const PERMISSION = 'accounting.journal.post';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return Journal::class;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Ledger, EffectKind::BankMatch], [ManualJournalLedgerCheck::class, ManualJournalBankMatchCheck::class]);
    }

    protected function correctPermission(): string
    {
        return self::PERMISSION;
    }

    protected function correctionActions(Model $document): array
    {
        return [DocumentAction::Correct, DocumentAction::Reverse];
    }

    protected function dateOf(Model $document): Carbon
    {
        return $document->journal_date->copy()->startOfDay();
    }

    protected function totalMinorOf(Model $document): int
    {
        /** @var Journal $document */
        return $document->totalDebits()->minor;
    }

    protected function isReversed(Model $document): bool
    {
        /** @var Journal $document */
        return $document->hasBeenReversed();
    }

    protected function alreadyDone(Model $document): Blocker
    {
        /** @var Journal $document */
        $reversal = $document->reversal()->with('postedBy:id,name')->first();

        return $this->reversedSentence((string) $document->journal_number, $reversal?->posted_at, $reversal?->postedBy?->name, CorrectionChain::linkAsOriginal($document)?->successor_number);
    }

    protected function extraBlockers(Model $document, User $user, DocumentAction $action): array
    {
        if (! AccountingPeriod::isClosedOn($this->dateOf($document))) {
            return [];
        }

        $month = $this->dateOf($document)->format('F Y');

        return [new Blocker('manual_closed_month', "{$month} is closed. Put a manual journal right with a new journal in an open month.")];
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->journal_number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('accounting.journals.show', $document->getKey());
    }
}
