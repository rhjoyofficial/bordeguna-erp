<?php

namespace App\Modules\Foundation\Scopes;

use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Row-level tenant scoping. Applied automatically via BelongsToBusiness.
 *
 * To bypass (Super Admin cross-business views only — see Business plan risk #2):
 * SomeModel::withoutGlobalScope(BusinessScope::class)->...
 * Never branch on "is super admin, skip scope" inside application code —
 * always use this explicit, grep-able call so cross-business access stays auditable.
 */
class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $businessId = app(BusinessContext::class)->id();

        if ($businessId !== null) {
            $builder->where($model->qualifyColumn('business_id'), $businessId);

            return;
        }

        // Fail closed, not open: a forgotten BusinessContext::set() call (e.g. in a
        // queued job or console command) must return zero rows, never every
        // business's data. Cross-business access must go through the explicit
        // withoutGlobalScope(BusinessScope::class) escape hatch instead.
        $builder->whereRaw('1 = 0');
    }
}
