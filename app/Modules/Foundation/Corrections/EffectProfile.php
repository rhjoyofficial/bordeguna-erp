<?php

namespace App\Modules\Foundation\Corrections;

use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;

/**
 * The effects a document type CAN have, and the checks that guard them.
 *
 * ⚠⚠ v2 (C14): every declared effect needs a check that covers it. When one is
 * missing, `uncoveredEffects()` names it and the policy blocks every money
 * action on the type (fail closed), rather than skipping the check.
 */
final readonly class EffectProfile
{
    /**
     * @param  list<EffectKind>  $effects
     * @param  list<class-string<DependencyCheck>>  $dependencyChecks
     */
    public function __construct(
        public array $effects,
        public array $dependencyChecks,
    ) {}

    /** @return list<DependencyCheck> */
    public function checks(): array
    {
        return array_map(fn (string $class) => app($class), $this->dependencyChecks);
    }

    /** @return list<EffectKind> */
    public function uncoveredEffects(): array
    {
        $covered = array_map(fn (DependencyCheck $check) => $check->covers(), $this->checks());

        return array_values(array_filter($this->effects, fn (EffectKind $effect) => ! in_array($effect, $covered, true)));
    }

    public function has(EffectKind $effect): bool
    {
        return in_array($effect, $this->effects, true);
    }
}
