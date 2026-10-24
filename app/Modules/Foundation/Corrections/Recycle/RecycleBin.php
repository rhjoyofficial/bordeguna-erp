<?php

namespace App\Modules\Foundation\Corrections\Recycle;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\Owners;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Recycle bin (PHASE-0-CONTRACT v2, 1.5): the allow-listed models' deleted
 * rows, for the Owner only, with Restore and nothing else (no purge, ever).
 */
class RecycleBin
{
    /** @return array<string, class-string<Model>> */
    public function models(): array
    {
        return config('recycle_bin.models', []);
    }

    /** @return Collection<int, array> newest first */
    public function entries(User $owner, ?string $module = null): Collection
    {
        if (! Owners::is($owner)) {
            return collect();
        }

        $rows = collect();

        foreach ($this->models() as $key => $class) {
            if ($module !== null && $module !== '' && $module !== $key) {
                continue;
            }

            foreach ($this->trashed($class)->with('deletedBy:id,name')->latest('deleted_at')->limit(200)->get() as $model) {
                $rows->push([
                    'type' => $key,
                    'id' => $model->getKey(),
                    'label' => method_exists($model, 'binLabel') ? $model->binLabel() : class_basename($model).' #'.$model->getKey(),
                    'deleted_at' => $model->deleted_at?->format('j M Y, g:i a'),
                    'deleted_sort' => $model->deleted_at?->getTimestamp() ?? 0,
                    'days_in_bin' => $model->deleted_at !== null ? (int) $model->deleted_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
                    'deleted_by' => $model->deletedBy?->name,
                    'reason' => $model->delete_reason,
                ]);
            }
        }

        return $rows->sortByDesc('deleted_sort')->values();
    }

    public function restore(string $type, int $id, User $owner, ActionReason $reason): Model
    {
        $class = $this->models()[$type] ?? throw new NotFoundHttpException;
        $model = $this->trashed($class)->findOrFail($id);

        DB::transaction(fn () => $model->restoreFromBin($owner, $reason));

        return $model;
    }

    /**
     * The trashed rows of one allow-listed model. ⭐ Wave 3 (24 Oct 2026): a
     * model that is not business-scoped (a login, shared across the group)
     * narrows itself with `scopeVisibleInBin`, so one business's Owner never
     * sees or restores another business's rows.
     *
     * @param  class-string<Model>  $class
     */
    private function trashed(string $class): Builder
    {
        $query = $class::onlyTrashed();

        return method_exists($class, 'scopeVisibleInBin') ? $query->visibleInBin() : $query;
    }
}
