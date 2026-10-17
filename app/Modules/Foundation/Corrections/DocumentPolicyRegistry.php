<?php

namespace App\Modules\Foundation\Corrections;

use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every document type that offers correction actions (PHASE-0-CONTRACT 1.2).
 *
 * ⚠ The generic /records/{type}/... routes accept ONLY a type registered here
 * (an unknown type is a 404), so one registry entry is one deliberate decision
 * per type. Each module registers its own types from its service provider.
 */
class DocumentPolicyRegistry
{
    /** @var array<string, array{policy: DocumentActionPolicy, corrector: ?DocumentCorrector, duplicate: ?DuplicateRule}> */
    private array $types = [];

    public function register(DocumentActionPolicy $policy, ?DocumentCorrector $corrector = null, ?DuplicateRule $duplicate = null): void
    {
        $this->types[$policy->type()] = ['policy' => $policy, 'corrector' => $corrector, 'duplicate' => $duplicate];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->types);
    }

    public function for(string $type): DocumentActionPolicy
    {
        return $this->entry($type)['policy'];
    }

    public function corrector(string $type): DocumentCorrector
    {
        return $this->entry($type)['corrector'] ?? throw new NotFoundHttpException;
    }

    public function duplicateRule(string $type): ?DuplicateRule
    {
        return $this->entry($type)['duplicate'];
    }

    public function forModel(Model $model): ?DocumentActionPolicy
    {
        foreach ($this->types as $entry) {
            if ($model instanceof ($entry['policy']->modelClass())) {
                return $entry['policy'];
            }
        }

        return null;
    }

    /** The registry key of a stored morph type, or null. */
    public function typeOfMorph(string $morphClass): ?string
    {
        foreach ($this->types as $type => $entry) {
            $class = $entry['policy']->modelClass();

            if ((new $class)->getMorphClass() === $morphClass) {
                return $type;
            }
        }

        return null;
    }

    private function entry(string $type): array
    {
        return $this->types[$type] ?? throw new NotFoundHttpException;
    }
}
