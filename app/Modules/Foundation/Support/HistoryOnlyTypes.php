<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1 gaps (24 Oct 2026): records that have a History tab but
 * are not corrected through the corrections engine (a supplier advance, a
 * sales order). GET /records/{type}/{id}/history serves them beside the
 * DocumentPolicyRegistry's types.
 *
 * ⚠ Kept OUT of DocumentPolicyRegistry::types() on purpose: every type there
 * offers Reverse and Correct and is walked by the policy kit (PolicyKitTest).
 * A History tab is read-only, so it needs only "which model" and "who may see
 * it", nothing that could post.
 */
class HistoryOnlyTypes
{
    /** @var array<string, array{model: class-string<Model>, canView: Closure(Model, User): bool}> */
    private array $types = [];

    /**
     * @param  class-string<Model>  $modelClass
     * @param  Closure(Model, User): bool  $canView  the record's own view rule (its show page's)
     */
    public function register(string $type, string $modelClass, Closure $canView): void
    {
        $this->types[$type] = ['model' => $modelClass, 'canView' => $canView];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * The record, found through the model's own (business-scoped) query, or
     * null when it does not exist here or this person may not see it.
     */
    public function find(string $type, int $id, User $user): ?Model
    {
        $entry = $this->types[$type] ?? null;

        if ($entry === null) {
            return null;
        }

        $record = $entry['model']::query()->find($id);

        return $record !== null && ($entry['canView'])($record, $user) ? $record : null;
    }
}
