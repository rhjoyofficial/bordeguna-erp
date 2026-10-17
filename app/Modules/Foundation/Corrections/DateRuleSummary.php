<?php

namespace App\Modules\Foundation\Corrections;

/** Which date a money action lands on, and why, for the button and the dialog. */
final readonly class DateRuleSummary
{
    public function __construct(public string $rule, public string $sentence) {}

    public function toArray(): array
    {
        return ['rule' => $this->rule, 'sentence' => $this->sentence];
    }
}
