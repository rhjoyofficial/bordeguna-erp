<?php

namespace App\Modules\Foundation\Corrections\Contracts;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use Illuminate\Database\Eloquent\Model;

/**
 * The type's own reversal and successor logic (PHASE-0-CONTRACT 1.4).
 *
 * ⚠⚠ The service orchestrates; THIS does the domain work, through the module's
 * existing actions. No generic row cloning, no generic "inverse row" for stock,
 * custody or payroll (ChatGPT REPLY-0 3.3).
 */
interface DocumentCorrector
{
    public function policy(): DocumentActionPolicy;

    /** Business-scoped; null when the record does not exist here. */
    public function find(int $documentId): ?Model;

    /** Re-read the original AND its dependency rows FOR UPDATE, inside the open transaction. */
    public function lockForCommit(int $documentId): Model;

    /**
     * A stable hash of everything a preview depends on: the document's own
     * columns, its reversal state, the journal ids, the bank-match state of its
     * money lines, and the period boundary. Any change means "look again".
     */
    public function fingerprint(Model $document): string;

    /** The edited form, checked with the same rules as creating the document. */
    public function validateReplacement(Model $document, array $input): ReplacementData;

    /** The replacement as a plain array (stored on a request, re-validated at approval). */
    public function replacementInput(ReplacementData $replacement): array;

    /** @return list<EffectLine> Pure: writes nothing. */
    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array;

    /** @return list<string> "INC-2026-27-0007 stays, marked Corrected." */
    public function describeStays(Model $document, DocumentAction $action): array;

    /** The document's business date. */
    public function originalDate(Model $document): \Illuminate\Support\Carbon;

    /** The document's own total in paisa (the field the Tk 20,000 rule reads). */
    public function totalMinor(Model $document): int;

    /** @return list<int> users who may not be the second person: the maker and the last editor */
    public function makerAndLastEditorIds(Model $document): array;

    /** The journal the original posted, or null (a data problem the policy blocks on). */
    public function journalOf(Model $document): ?Journal;

    /** Post the reversal through the module's own action. */
    public function reverse(Model $document, CommitContext $ctx): ?Journal;

    /** Create and post the successor through the module's own create action. */
    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model;

    /** The successor's journal (null when the type has no ledger effect). */
    public function journalOfSuccessor(Model $successor): ?Journal;

    /** Mark the original Reversed. Columns only; never money fields. */
    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void;

    /** The class of the successor this type creates (bound by the closed-month pass). */
    public function successorClass(): string;

    /** Per-type invariants (v2, C11), inside the transaction after the writes; throw to roll back. */
    public function assertInvariants(Model $original, ?Model $successor): void;

    /** Who may see this document in a duplicate warning. */
    public function visibleTo(Model $document, User $user): bool;
}
