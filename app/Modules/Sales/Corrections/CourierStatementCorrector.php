<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\CourierParcelClaim;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\CourierStatementLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ A posted courier payout's own reversal (B13, 24 Oct 2026).
 *
 *   reverse()       the statement's one journal mirrored on the date the rules
 *                   give (its posting day by default): the cash on delivery is
 *                   back with the courier (1145), the fees and the bank line
 *                   undone; then its parcel claims are RELEASED, so the right
 *                   statement can settle those parcels
 *   markOriginal()  status `reversed`, the reversal columns, `live_marker`
 *                   cleared so the same file and invoice id import again
 *
 * The lines keep their matched parcels: they are the record of what this
 * statement said, which is the history a reversal keeps.
 */
class CourierStatementCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly CourierStatementActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return CourierStatement::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $statement = CourierStatement::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        Journal::query()->where('source_type', $statement->getMorphClass())->where('source_id', $statement->getKey())->lockForUpdate()->get(['id']);

        return $statement->load('lines', 'courier');
    }

    public function fingerprint(Model $document): string
    {
        /** @var CourierStatement $document */
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            's' => [$document->id, $document->status, (string) $document->share_amount, $document->posted_on?->toDateString(), $document->reversed_at?->toIso8601String()],
            'claims' => CourierParcelClaim::query()->whereIn('courier_statement_line_id', $document->lines()->select('id'))->count(),
            'journals' => Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())->orderBy('id')->pluck('id')->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A courier statement is not corrected in place. Reverse it and import the right statement.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var CourierStatement $document */
        $document->loadMissing(['courier:id,name', 'receivedInto:id,name', 'lines']);
        $on = Tk::date($dates->reversalDate);
        $courier = $document->courier?->name ?? 'the courier';
        $lines = [new EffectLine("Its journal is mirrored on {$on}: the cash on delivery is held by {$courier} again and the fees come off.", 'Receivable from Couriers', null, 'ledger')];

        if ($document->receivedInto !== null && $document->deposit_amount !== null) {
            $lines[] = new EffectLine("Its share of the deposit comes out of {$document->receivedInto->name}.", $document->receivedInto->name, null, 'ledger');
        }

        $lines[] = new EffectLine("The {$document->lines->count()} parcels on it can be settled by the right statement.", null, null, 'external');

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["Statement {$document->statement_reference} stays, marked Reversed, with its file.", 'The same file or invoice id can then be imported again.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $this->policy->documentDate($document);
    }

    public function totalMinor(Model $document): int
    {
        return $this->policy->totalMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return array_values(array_unique(array_filter([(int) $document->created_by, (int) $document->posted_by])));
    }

    public function journalOf(Model $document): ?Journal
    {
        return SalesLedgerCheck::journalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var CourierStatement $document */
        if ($document->status !== CourierStatement::POSTED) {
            throw ValidationException::withMessages(['reason_text' => "Statement {$document->statement_reference} is not posted."]);
        }

        $journal = SalesLedgerCheck::journalOf($document);

        try {
            $reversal = $journal === null ? null : $this->reverseJournal->handle(
                $journal->load('lines'),
                $ctx->actor,
                $ctx->dates->reversalDate,
                "Reversal of {$document->courier?->name} statement {$document->statement_reference}: {$ctx->reason->display()}",
                $ctx->pass,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        CourierParcelClaim::query()
            ->whereIn('courier_statement_line_id', CourierStatementLine::query()->where('courier_statement_id', $document->id)->select('id'))
            ->delete();

        return $reversal;
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A courier statement has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $document->forceFill([
            'status' => CourierStatement::REVERSED,
            'live_marker' => null,
            'reversed_on' => $ctx->dates->reversalDate,
            'reversed_at' => now(),
            'reversed_by' => $ctx->actor->id,
            'reversal_reason' => $ctx->reason->display(),
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    public function successorClass(): string
    {
        return CourierStatement::class;
    }

    /** Marked, no live journal, and no parcel still claimed by it. */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var CourierStatement $original */
        if (! $original->isReversed() || $original->status !== CourierStatement::REVERSED) {
            throw new LogicException("Statement {$original->statement_reference} was not marked.");
        }

        if (SalesLedgerCheck::liveJournals($original)->isNotEmpty()) {
            throw new LogicException("Statement {$original->statement_reference} still has a live journal.");
        }

        if (CourierParcelClaim::query()->whereIn('courier_statement_line_id', $original->lines()->select('id'))->exists()) {
            throw new LogicException("Statement {$original->statement_reference} still claims parcels.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }
}
