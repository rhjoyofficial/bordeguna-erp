<?php

namespace App\Modules\Foundation\Corrections;

/** A recent record that looks like the one being saved (PHASE-0-CONTRACT 1.11). */
final readonly class DuplicateCandidate
{
    public function __construct(
        public string $type,
        public int $id,
        public string $number,
        public string $summary,
        public string $savedBy,
        public string $savedAgo,
        public string $status,
        public bool $visibleToUser,
        public ?string $url,
    ) {}

    public function toArray(): array
    {
        if (! $this->visibleToUser) {
            return ['number' => null, 'summary' => 'A similar record exists that you cannot see.', 'saved_by' => null, 'saved_ago' => null, 'status' => null, 'url' => null];
        }

        return ['number' => $this->number, 'summary' => $this->summary, 'saved_by' => $this->savedBy, 'saved_ago' => $this->savedAgo, 'status' => $this->status, 'url' => $this->url];
    }
}
