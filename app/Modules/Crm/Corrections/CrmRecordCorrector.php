<?php

namespace App\Modules\Crm\Corrections;

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

/**
 * ⭐ A CRM record in the corrections registry (corrections wave 3, 24 Oct 2026).
 *
 * ⚠⚠ Nothing in CRM moves money, stock, pay or custody, so the corrections
 * service (and its closed-month rules, journals and links) never runs on a CRM
 * record. Every action is the module's own route (EditCrmActivity,
 * ReverseLeadConversion, DeleteLead, ...), which asks the type's policy again
 * under the row lock. This corrector exists because the registry resolves a
 * record through it (find), which is what gives a lead, an activity and a
 * contact the History panel and the generic actions endpoint.
 *
 * ⚠ The generic /records/{type}/{id}/reverse and /correct routes refuse with a
 * sentence (the preview first): the CRM screens never post there, and a hand
 * made request must not reach a half-built posting path.
 */
abstract class CrmRecordCorrector implements DocumentCorrector
{
    /** What the person reads when the generic route is used by hand. */
    abstract protected function useInstead(): string;

    public function find(int $documentId): ?Model
    {
        $class = $this->policy()->modelClass();

        return $class::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $class = $this->policy()->modelClass();

        return $class::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
    }

    public function fingerprint(Model $document): string
    {
        return hash('sha256', (string) json_encode([$document->getKey(), $document->getAttribute('updated_at')?->toIso8601String()]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        $this->refuse();
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        $this->refuse();
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $this->refuse();
    }

    public function originalDate(Model $document): Carbon
    {
        return Carbon::parse($document->getAttribute('created_at') ?? now())->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return 0;
    }

    public function journalOf(Model $document): ?Journal
    {
        return null;
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        $this->refuse();
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        $this->refuse();
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $this->refuse();
    }

    public function successorClass(): string
    {
        return $this->policy()->modelClass();
    }

    public function assertInvariants(Model $original, ?Model $successor): void {}

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy()->canView($document, $user);
    }

    abstract public function policy(): DocumentActionPolicy;

    /**
     * @throws ValidationException
     */
    private function refuse(): never
    {
        throw ValidationException::withMessages(['correction' => $this->useInstead()]);
    }
}
