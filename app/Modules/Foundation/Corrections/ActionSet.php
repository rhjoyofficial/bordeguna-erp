<?php

namespace App\Modules\Foundation\Corrections;

/** Every action the UI may draw for one record and one user, in display order. */
final readonly class ActionSet
{
    /** @param list<ActionAvailability> $actions */
    public function __construct(public DocumentStage $stage, public array $actions) {}

    public function get(DocumentAction $action): ?ActionAvailability
    {
        foreach ($this->actions as $availability) {
            if ($availability->action === $action) {
                return $availability;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'stage' => $this->stage->value,
            'actions' => array_map(fn (ActionAvailability $a) => $a->toArray(), $this->actions),
        ];
    }
}
