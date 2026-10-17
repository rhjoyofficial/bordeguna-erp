<?php

namespace App\Modules\Foundation\Corrections;

/**
 * One action for one record and one user, re-evaluated on every request and
 * again under the lock at commit (PHASE-0-CONTRACT 1.2).
 */
final readonly class ActionAvailability
{
    /** @param list<Blocker> $blockers */
    public function __construct(
        public DocumentAction $action,
        public bool $allowed,
        public array $blockers,
        public string $permission,
        public bool $primary,
        public bool $requiresReason,
        public ?string $reasonContext,
        public ApprovalRoute $approval = ApprovalRoute::None,
        public ?DateRuleSummary $date = null,
    ) {}

    /** Blocked, but every blocker lets the person send it as a request (to the Owner). */
    public function isRequestable(): bool
    {
        if ($this->allowed || $this->blockers === []) {
            return false;
        }

        foreach ($this->blockers as $blocker) {
            if (! $blocker->requestable) {
                return false;
            }
        }

        return true;
    }

    /** @return list<Blocker> */
    public function hardBlockers(): array
    {
        return array_values(array_filter($this->blockers, fn (Blocker $b) => ! $b->requestable));
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'label' => $this->action->label(),
            'allowed' => $this->allowed,
            'requestable' => $this->isRequestable(),
            'blockers' => array_map(fn (Blocker $b) => $b->toArray(), $this->blockers),
            'primary' => $this->primary,
            'requires_reason' => $this->requiresReason,
            'reason_context' => $this->reasonContext,
            'approval' => $this->approval->value,
            'date' => $this->date?->toArray(),
        ];
    }
}
