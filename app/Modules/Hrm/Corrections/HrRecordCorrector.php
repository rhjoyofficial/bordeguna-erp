<?php

namespace App\Modules\Hrm\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReplacementData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ An HR record in the registry (corrections wave 3, 24 Oct 2026): a leave, a
 * movement, an employee or a show cause notice.
 *
 * ⚠⚠ None of them posts a journal, so Reverse and Correct through the
 * corrections service never run on them and the posting half refuses
 * outright (the same shape as PurchaseOrderCorrector). It exists because the
 * registry resolves a record through its corrector, which is what gives these
 * records the History tab and the generic actions endpoint. Their Cancel,
 * Withdraw, Delete and Undo separation are HRM's own routes, asking the same
 * policy again under the lock.
 *
 * Contract note for the report: the frozen contract has no generic route for
 * the actions that post nothing, and no verb for "Undo separation" (the
 * employee policy uses Reverse, labelled Undo separation on screen).
 */
class HrRecordCorrector implements DocumentCorrector
{
    public function __construct(private readonly DocumentActionPolicy $policy) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        $class = $this->policy->modelClass();

        return $class::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $class = $this->policy->modelClass();

        return $class::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
    }

    /** Every stored column: any change after the request was sent means "look again". */
    public function fingerprint(Model $document): string
    {
        return self::fingerprintOf($document);
    }

    public static function fingerprintOf(Model $document): string
    {
        $raw = $document->getRawOriginal();
        ksort($raw);

        return hash('sha256', (string) json_encode(array_map(fn ($value) => $value === null ? null : (string) $value, $raw)));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'An HR record is cancelled or withdrawn, not corrected.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        return [];
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return [];
    }

    public function originalDate(Model $document): Carbon
    {
        $date = $document->getAttribute('start_date')
            ?? $document->getAttribute('movement_date')
            ?? $document->getAttribute('issued_at')
            ?? $document->getAttribute('created_at');

        return Carbon::parse($date)->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return 0;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return array_values(array_filter([(int) ($document->getAttribute('requested_by') ?? $document->getAttribute('issued_by') ?? 0)]));
    }

    public function journalOf(Model $document): ?Journal
    {
        return null;
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        throw new LogicException('An HR record posts no journal, so the corrections service never reverses it.');
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('An HR record is cancelled or withdrawn, not corrected.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        throw new LogicException('An HR record posts no journal, so the corrections service never reverses it.');
    }

    public function successorClass(): string
    {
        return $this->policy->modelClass();
    }

    public function assertInvariants(Model $original, ?Model $successor): void {}

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
