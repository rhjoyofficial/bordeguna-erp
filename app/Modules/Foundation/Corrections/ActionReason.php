<?php

namespace App\Modules\Foundation\Corrections;

use App\Modules\Foundation\Models\Reason;

/**
 * Why somebody corrected, reversed, cancelled or deleted a record
 * (PHASE-0-CONTRACT 1.6). A picked reason is a heading; the text is required.
 */
final readonly class ActionReason
{
    public const MIN = 5;

    public const MAX = 1000;

    public function __construct(
        public ?int $reasonId,
        public string $text,
    ) {}

    /** "Wrong amount: typed 50,000 for 5,000", or the text alone. */
    public function display(): string
    {
        $name = $this->reasonId === null ? null : Reason::query()->whereKey($this->reasonId)->value('name');

        return $name === null ? $this->text : "{$name}: {$this->text}";
    }

    public function toArray(): array
    {
        return ['reason_id' => $this->reasonId, 'reason_text' => $this->text];
    }
}
