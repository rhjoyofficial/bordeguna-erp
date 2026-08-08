<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Support\CatalogueImportPlan;
use App\Modules\Inventory\Support\CatalogueText;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a plan, entirely or not at all.
 *
 * ⚠⚠ Refuses a plan carrying any error. That check is here rather than only in
 * the command because "no partial imports" is a property of the operation, not
 * of the way somebody happened to invoke it - a future caller must not be able
 * to skip it by not being the console.
 */
class ApplyProductCatalogueImport
{
    public function handle(CatalogueImportPlan $plan): void
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException(
                'Refusing to import: the plan carries '.count($plan->errors).' error(s). Nothing has been written.'
            );
        }

        DB::transaction(function () use ($plan) {
            $categories = $this->ensureCategories($plan);
            $units = $this->ensureUnits($plan);

            foreach ($plan->creates as $create) {
                Product::create($create['attributes'] + [
                    'category_id' => $create['categoryKey'] === null ? null : $categories[$create['categoryKey']],
                    'unit_of_measure_id' => $units[$create['unitKey']],
                ]);
            }

            foreach ($plan->updates as $update) {
                $product = Product::findOrFail($update['id']);

                $product->update($update['attributes'] + [
                    'category_id' => $update['categoryKey'] === null ? null : $categories[$update['categoryKey']],
                    'unit_of_measure_id' => $units[$update['unitKey']],
                ]);
            }
        });
    }

    /**
     * @return array<string, int> category key => id
     */
    private function ensureCategories(CatalogueImportPlan $plan): array
    {
        $byKey = Category::all()
            ->mapWithKeys(fn (Category $c) => [CatalogueText::categoryKey($c->name) => $c->id])
            ->all();

        foreach ($plan->newCategories as $key => $name) {
            if (isset($byKey[$key])) {
                continue;
            }

            // ⚠ The slug is what the database is unique on, so it must be the
            // same string the planner keyed on - not a second, separately
            // derived slug that could disagree by one character.
            $byKey[$key] = Category::create(['name' => $name, 'slug' => $key])->id;
        }

        return $byKey;
    }

    /**
     * @return array<string, int> unit key => id
     */
    private function ensureUnits(CatalogueImportPlan $plan): array
    {
        $byKey = UnitOfMeasure::all()
            ->mapWithKeys(fn (UnitOfMeasure $u) => [CatalogueText::unitKey($u->abbreviation) => $u->id])
            ->all();

        foreach ($plan->newUnits as $key => $unit) {
            if (isset($byKey[$key])) {
                continue;
            }

            $byKey[$key] = UnitOfMeasure::create([
                'name' => $unit['name'],
                'abbreviation' => $unit['abbreviation'],
            ])->id;
        }

        return $byKey;
    }
}
