<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
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
use App\Modules\Purchasing\Actions\ReverseExpenseVoucher;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\ExpenseVoucherLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): an expense voucher reversed
 * through the engine (preview, reason, original date, closed month for the
 * Owner, Tk 20,000 second person). reverse() is the old ReverseExpenseVoucher,
 * handed the engine's date and pass; it marks the voucher itself.
 */
class ExpenseVoucherCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly ExpenseVoucherActionPolicy $policy,
        private readonly ReverseExpenseVoucher $reverseVoucher,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return ExpenseVoucher::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        return ExpenseVoucher::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
    }

    public function fingerprint(Model $document): string
    {
        /** @var ExpenseVoucher $document */
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'v' => [$document->id, $document->voucher_number, $document->spent_on?->toDateString(), (string) $document->total, $document->reversed_at?->toIso8601String()],
            'journal' => $this->journalOf($document)?->id,
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'An expense voucher is not corrected in place. Reverse it and record it again.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var ExpenseVoucher $document */
        $on = Tk::date($dates->reversalDate);
        $lines = [];

        foreach ($document->lines()->with('account:id,code,name')->get() as $line) {
            /** @var ExpenseVoucherLine $line */
            $amount = Money::ofTaka((string) $line->amount)->minor;
            $lines[] = new EffectLine(($line->account?->name ?? 'The expense head').' comes down by '.Tk::of($amount)." on {$on}.", $line->account?->name, -$amount, 'ledger');
        }

        $lines[] = new EffectLine(Tk::of($this->totalMinor($document)).' goes back into '.($document->moneyAccount?->name ?? 'the money account')." on {$on}.", null, null, 'ledger');

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        /** @var ExpenseVoucher $document */
        return ["{$document->voucher_number} stays, marked Reversed, with its reason.", 'Its journal stays; a mirror journal undoes it. A standing cost it paid is due again.'];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var ExpenseVoucher $document */
        return $this->policy->documentDate($document);
    }

    public function totalMinor(Model $document): int
    {
        return $this->policy->totalMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return [(int) $document->getAttribute('created_by')];
    }

    public function journalOf(Model $document): ?Journal
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var ExpenseVoucher $document */
        try {
            $reversed = $this->reverseVoucher->forCorrection($document, $ctx->actor, $ctx->reason->display(), $ctx->dates->reversalDate, $ctx->pass);
        } catch (PeriodClosedException|JournalException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return $reversed->reversal_journal_id === null ? null : Journal::query()->find($reversed->reversal_journal_id);
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('An expense voucher has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ReverseExpenseVoucher wrote the reversal columns with the reason.
    }

    public function successorClass(): string
    {
        return ExpenseVoucher::class;
    }

    public function assertInvariants(Model $original, ?Model $successor): void
    {
        if (! $original->fresh()->isReversed()) {
            throw new LogicException("{$original->getAttribute('voucher_number')} was not marked.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
