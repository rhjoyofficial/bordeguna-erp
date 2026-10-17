<?php

namespace App\Modules\Foundation\Corrections\Contracts;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * One per document type: which buttons it shows, which are blocked and why, and
 * which permission each needs (PHASE-0-CONTRACT 1.2, frozen after Joy's walk).
 *
 * ⚠ Hiding a button is not security. Every endpoint asks the same policy again,
 * and the correction service asks it once more under the lock.
 *
 * Rules every policy follows (tests/Feature/Corrections/PolicyKitTest.php):
 *   1. Edit is never offered on a Done record.
 *   2. Delete is never offered when the type has an effect that occurred.
 *   3. A blocked action keeps its place, with its blockers.
 *   4. Every blocker message is one sentence, with no codes or class names.
 */
interface DocumentActionPolicy
{
    /** Registry key, stable, used in URLs: 'accounting.income_voucher'. */
    public function type(): string;

    /** @return class-string<Model> */
    public function modelClass(): string;

    /** Whether this user may see the record at all (checked before anything else). */
    public function canView(Model $document, User $user): bool;

    public function stageOf(Model $document): DocumentStage;

    public function effectProfile(): EffectProfile;

    public function actionsFor(Model $document, User $user): ActionSet;

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability;

    /** "INC-2026-27-0007". */
    public function numberOf(Model $document): string;

    /** Where the record is seen, for links in reports and history. */
    public function urlOf(Model $document): ?string;
}
