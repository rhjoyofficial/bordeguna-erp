<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\Loans\RecordLoanLinesFromJournal;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\ManualJournalForm;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026): a manual journal's reversal and its
 * corrected journal.
 *
 *   reverse()          ReverseJournal on the journal itself (its own date by
 *                      default); a line that moved a register loan is unlinked
 *                      as the journal screen's Reverse always did
 *   createSuccessor()  a new manual journal from the edited form, through
 *                      ManualJournalForm and PostJournal, exactly as the record
 *                      screen posts one, its memo naming what it corrects
 *   markOriginal()     nothing: a journal is never updated. "Reversed" is the
 *                      reversal journal pointing at it, and the correction
 *                      link names the successor.
 */
class ManualJournalCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly ManualJournalActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
        private readonly PostJournal $postJournal,
        private readonly RecordLoanLinesFromJournal $loanLines,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return Journal::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        return Journal::query()->whereKey($documentId)->lockForUpdate()->firstOrFail()->load('lines');
    }

    public function fingerprint(Model $document): string
    {
        /** @var Journal $document */
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'v' => [$document->id, $document->journal_number, $document->journal_date?->toDateString(), $document->source_type, $document->reverses_journal_id],
            'lines' => $document->lines()->orderBy('id')->get(['account_id', 'amount_minor', 'party_type', 'party_id'])
                ->map(fn ($l) => [(int) $l->account_id, (int) $l->amount_minor, $l->party_type, $l->party_id])->all(),
            'reversed' => $document->reversal()->pluck('id')->all(),
            'matched' => DB::table('bank_statement_lines')
                ->whereIn('matched_journal_line_id', DB::table('journal_lines')->where('journal_id', $document->id)->select('id'))
                ->count(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var Journal $document */
        $data = ManualJournalForm::validate($input);

        // Read fully now (one side per line, the parties, a subsystem's own
        // head), so the preview says what the save would; it writes nothing.
        $draft = ManualJournalForm::draft($data);

        if ($this->sameAs($document, $draft)) {
            throw ValidationException::withMessages(['lines' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        return new ReplacementData($draft->date, ManualJournalForm::debitMinor($data), $data, $data);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var Journal $document */
        $document->loadMissing('lines.account');
        $on = Tk::date($dates->reversalDate);
        $lines = [];

        foreach ($document->lines as $line) {
            $amount = (int) $line->amount_minor;
            $name = $line->account?->name ?? 'a ledger head';
            $lines[] = new EffectLine(($amount > 0 ? 'Credit ' : 'Debit ').Tk::of(abs($amount))." to {$name} on {$on}, undoing the entry.", $name, -$amount, 'ledger');
        }

        if ($action === DocumentAction::Correct && $replacement !== null) {
            $lines[] = new EffectLine('A new journal of '.Tk::of($replacement->totalMinor).' posts on '.Tk::date($replacement->date).'.', null, $replacement->totalMinor, 'ledger');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $document->journal_number;

        return $action === DocumentAction::Correct
            ? ["{$number} stays, marked Corrected.", "The new journal gets its own number and the label \"Correction of {$number}\"."]
            : ["{$number} stays, marked Reversed.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->journal_date->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var Journal $document */
        return $document->totalDebits()->minor;
    }

    /** @return list<int> a journal has no maker column: who posted it */
    public function makerAndLastEditorIds(Model $document): array
    {
        return $document->posted_by === null ? [] : [(int) $document->posted_by];
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var Journal $document */
        return $document;
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var Journal $document */
        try {
            $reversal = $this->reverseJournal->handle($document->load('lines'), $ctx->actor, $ctx->dates->reversalDate, "Reversal of {$document->journal_number}: {$ctx->reason->display()}", $ctx->pass);
        } catch (PeriodClosedException|JournalException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        // A manual journal that named a register loan: its movement goes too.
        RecordLoanLinesFromJournal::markReversed($document, $reversal, $ctx->actor, $ctx->reason->display());

        return $reversal;
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        $values = $replacement->values;
        $memo = trim((string) ($values['memo'] ?? ''));
        $draft = ManualJournalForm::draft($values, ($memo === '' ? '' : "{$memo} - ")."Correction of {$ctx->originalNumber}");

        try {
            $posted = $this->postJournal->handle($draft, $ctx->actor, pass: $ctx->pass);
        } catch (PeriodClosedException|JournalException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $this->loanLines->handle($posted, $ctx->actor);

        return $posted;
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        /** @var Journal $successor */
        return $successor;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ⚠ Nothing to write: a posted journal is never updated (Journal::booted
        // refuses it). The reversal names it, and the correction link names
        // the successor.
    }

    public function successorClass(): string
    {
        return Journal::class;
    }

    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var Journal $original */
        if (! $original->hasBeenReversed()) {
            throw new LogicException("{$original->journal_number} was not reversed.");
        }

        if ($successor !== null && (int) $successor->lines()->sum('amount_minor') !== 0) {
            throw new LogicException("{$successor->journal_number} does not balance.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('accounting.view');
    }

    private function sameAs(Journal $journal, JournalDraft $draft): bool
    {
        $journal->loadMissing('lines');
        $norm = fn (?string $v) => $v === null || trim($v) === '' ? null : trim($v);

        $before = [
            $journal->journal_date->toDateString(), $journal->journal_type->value, $norm($journal->memo),
            $journal->lines->map(fn ($l) => [(int) $l->account_id, (int) $l->amount_minor, $norm($l->memo), $l->party_type, $l->party_id === null ? null : (int) $l->party_id])->values()->all(),
        ];

        $after = [
            $draft->date->toDateString(), $draft->type->value, $norm($draft->memo),
            array_map(fn ($l) => [(int) $l->accountId, $l->amount->minor, $norm($l->memo), $l->party?->getMorphClass(), $l->party === null ? null : (int) $l->party->getKey()], $draft->lines),
        ];

        return $before === $after;
    }
}
