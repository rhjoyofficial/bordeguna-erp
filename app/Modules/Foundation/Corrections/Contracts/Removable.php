<?php

namespace App\Modules\Foundation\Corrections\Contracts;

/**
 * A record that may go to the Recycle bin when it was never used
 * (PHASE-0-CONTRACT 1.5).
 */
interface Removable
{
    /** Null when never used; otherwise ONE plain sentence. */
    public function whyNotRemovable(): ?string;

    /** Null when it may be switched off; otherwise one sentence. */
    public function whyNotDeactivatable(): ?string;
}
