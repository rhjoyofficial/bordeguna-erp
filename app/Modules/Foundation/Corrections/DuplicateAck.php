<?php

namespace App\Modules\Foundation\Corrections;

/**
 * A valid "Save anyway", ready to be written beside the saved record.
 *
 * ⚠ v2 (C15): it only says "this is a different record". It is never an
 * approval: the second person and the Owner gate still apply.
 */
final readonly class DuplicateAck
{
    /** @param list<array{type: string, id: int, number: string}> $candidates */
    public function __construct(
        public int $userId,
        public int $businessId,
        public string $ruleKey,
        public string $payloadHash,
        public array $candidates,
    ) {}
}
