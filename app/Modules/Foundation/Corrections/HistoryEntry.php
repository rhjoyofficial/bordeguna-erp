<?php

namespace App\Modules\Foundation\Corrections;

use Illuminate\Support\Carbon;

/** One line of a record's History tab (PHASE-0-CONTRACT 1.13). */
final readonly class HistoryEntry
{
    /** @param list<array{label: string, url: ?string}> $links */
    public function __construct(
        public Carbon $at,
        public ?string $actor,
        public string $kind,
        public string $summary,
        public ?array $before = null,
        public ?array $after = null,
        public ?string $reason = null,
        public array $links = [],
    ) {}

    public function toArray(): array
    {
        return [
            'at' => $this->at->toIso8601String(),
            'at_human' => $this->at->format('j M Y, g:i a'),
            'actor' => $this->actor,
            'kind' => $this->kind,
            'summary' => $this->summary,
            'before' => $this->before,
            'after' => $this->after,
            'reason' => $this->reason,
            'links' => $this->links,
        ];
    }
}
