<?php

namespace App\Modules\Foundation\Corrections;

/** The candidates and the ack token that lets this person say "Save anyway". */
final readonly class DuplicateWarning
{
    /** @param list<DuplicateCandidate> $candidates */
    public function __construct(public array $candidates, public string $ackToken) {}

    public function toArray(): array
    {
        return [
            'candidates' => array_map(fn (DuplicateCandidate $c) => $c->toArray(), $this->candidates),
            'ack_token' => $this->ackToken,
        ];
    }
}
