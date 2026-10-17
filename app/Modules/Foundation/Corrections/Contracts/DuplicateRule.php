<?php

namespace App\Modules\Foundation\Corrections\Contracts;

use App\Models\User;
use App\Modules\Foundation\Corrections\DuplicateCandidate;

/**
 * "This looks like a record saved recently" (PHASE-0-CONTRACT 1.11).
 *
 * ⚠ A warning, never a hard rule: hard uniqueness (a supplier bill number, an
 * asset tag) stays a unique index. The guard asks, the person decides, and the
 * decision is kept.
 */
interface DuplicateRule
{
    public function key(): string;

    /** The normalised fields that must match (amount in paisa, dates as Y-m-d). */
    public function fingerprintOf(array $payload, int $businessId): array;

    /**
     * Same business; not reversed; `$exclude` = "type:id" of the record being
     * corrected and its chain (a correction never warns about its own original).
     *
     * @param  list<string>  $exclude
     * @return list<DuplicateCandidate>
     */
    public function candidates(array $payload, int $businessId, User $viewer, array $exclude = []): array;
}
