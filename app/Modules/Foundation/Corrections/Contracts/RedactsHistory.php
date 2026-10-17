<?php

namespace App\Modules\Foundation\Corrections\Contracts;

use App\Models\User;

/** A model whose history hides some attributes from some viewers (PHASE-0-CONTRACT 1.13). */
interface RedactsHistory
{
    /** @return list<string> attribute names never shown to this viewer */
    public function historyHidden(User $viewer): array;
}
