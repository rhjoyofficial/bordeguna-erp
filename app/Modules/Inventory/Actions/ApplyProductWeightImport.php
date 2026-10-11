<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\WeightSource;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductWeightImportPlan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a weights plan, entirely or not at all. A weight from the sheet is a
 * weight somebody typed, so its source is `manual`; each product saves through
 * the model, so the activity log records who changed which weight.
 */
class ApplyProductWeightImport
{
    public function handle(ProductWeightImportPlan $plan): int
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('Refusing to apply: the sheet has '.count($plan->errors).' error(s). Nothing has been written.');
        }

        return DB::transaction(function () use ($plan) {
            foreach ($plan->changes as $change) {
                Product::query()->findOrFail($change['id'])->update([
                    'weight_grams' => $change['to'],
                    'weight_source' => WeightSource::Manual,
                ]);
            }

            return count($plan->changes);
        });
    }
}
