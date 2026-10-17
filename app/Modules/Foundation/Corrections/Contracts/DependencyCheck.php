<?php

namespace App\Modules\Foundation\Corrections\Contracts;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use Illuminate\Database\Eloquent\Model;

/**
 * Something else in the system that depends on a document in a way that can
 * block an action on it (a bank statement match, an allocation, a stock batch
 * already consumed).
 *
 * ⚠ v2 (C14): a check declares the effect it guards. An effect declared by a
 * policy with no check covering it blocks every money action (fail closed).
 */
interface DependencyCheck
{
    public function covers(): EffectKind;

    /** Null when nothing depends on the document in a way that blocks $action. */
    public function blocker(Model $document, DocumentAction $action): ?Blocker;
}
