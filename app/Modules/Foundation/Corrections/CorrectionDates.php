<?php

namespace App\Modules\Foundation\Corrections;

use Illuminate\Support\Carbon;

/**
 * Which dates a correction lands on, and why (PHASE-0-CONTRACT v2, 1.9).
 *
 * `blocker` is set when the dates themselves stop the action (a closed month and
 * not the Owner: requestable; a successor moved out of a closed month: not).
 */
final readonly class CorrectionDates
{
    public function __construct(
        public Carbon $reversalDate,
        public ?Carbon $successorDate,
        public string $rule,
        public bool $needsClosedPeriodPass,
        public ?Carbon $closedThrough,
        public string $sentence,
        public ?Blocker $blocker = null,
        /** @var list<string> Y-m-d dates inside the closed period that the pass must cover */
        public array $closedDates = [],
    ) {}

    /** The facts a preview token or a request pins down; any change means "look again". */
    public function signature(): array
    {
        return [
            'reversal' => $this->reversalDate->toDateString(),
            'successor' => $this->successorDate?->toDateString(),
            'rule' => $this->rule,
            'pass' => $this->needsClosedPeriodPass,
            'closed_dates' => $this->closedDates,
        ];
    }

    public function summary(): DateRuleSummary
    {
        return new DateRuleSummary($this->rule, $this->sentence);
    }
}
