<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ A purchase order in the registry (corrections wave 1, 19 Oct 2026).
 *
 * ⚠⚠ Reverse and Correct are NEVER offered on an order (it moves no money and
 * no stock: PurchaseOrderActionPolicy), so the posting half of this corrector
 * refuses outright. It exists because the registry resolves a record through
 * its corrector (find), which is what gives an order the History tab and the
 * generic actions endpoint like every other document.
 *
 * Contract note for the report: the frozen contract has no generic route for
 * the actions that post nothing (Withdraw, Edit, Cancel, Fix details); they
 * are the module's own routes, asking the same policy.
 */
class PurchaseOrderCorrector implements DocumentCorrector
{
    public function __construct(private readonly PurchaseOrderActionPolicy $policy) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return PurchaseOrder::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        return PurchaseOrder::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
    }

    public function fingerprint(Model $document): string
    {
        return hash('sha256', (string) json_encode([$document->getKey(), $document->updated_at?->toIso8601String()]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A purchase order is edited, not corrected.']);
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
        /** @var PurchaseOrder $document */
        return Carbon::parse($document->order_date ?? $document->created_at)->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var PurchaseOrder $document */
        return $document->grandTotal()->minor;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return [(int) $document->created_by];
    }

    public function journalOf(Model $document): ?Journal
    {
        return null;
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        throw new LogicException('A purchase order is never reversed: it is cancelled.');
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A purchase order is edited, not corrected.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        throw new LogicException('A purchase order is never reversed: it is cancelled.');
    }

    public function successorClass(): string
    {
        return PurchaseOrder::class;
    }

    public function assertInvariants(Model $original, ?Model $successor): void {}

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
