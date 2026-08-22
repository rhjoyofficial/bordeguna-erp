<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\BomCycleCheck;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Writes a recipe, or the next version of one.
 *
 * ⚠⚠ C-BOM-1. THIS POSTS NOTHING. No journal, no movement, no batch. The only
 * thing a saved BOM changes is what the production run screen SUGGESTS. If this
 * class ever grows a call into the ledger or the stock engine, the production
 * run has stopped being the last sanctioned stock document.
 *
 * C-BOM-6. Editing a version NO RUN HAS USED mutates it in place, because
 * during setup a typo should not leave a trail of dead versions. Editing a
 * version a run WAS built from creates the next version instead, and
 * deactivates the old one - the old one is the answer to "what recipe was that
 * run built from", and mutating it would change history.
 *
 * C-BOM-7. Cycles refused at any depth, walking every version.
 *
 * C-BOM-8. Components must be STOCKED products. A service has no stock to
 * consume. An inactive product is refused as a new component; a line that
 * already references one is left alone, because breaking an old recipe is a
 * worse outcome than an odd-looking one.
 */
class SaveBillOfMaterial
{
    /**
     * @param  list<array{product_id:int, quantity_per_output:string, wastage_percent?:string, note?:?string}>  $lines
     */
    public function handle(
        Product $outputProduct,
        array $lines,
        User $by,
        ?BillOfMaterial $editing = null,
        ?string $name = null,
        string $outputQuantity = '1',
        ?string $effectiveFrom = null,
        ?string $note = null,
    ): BillOfMaterial {
        if (! $outputProduct->type->isStocked()) {
            throw new DomainException("{$outputProduct->name} is a service, so nothing can be produced into it.");
        }

        if (bccomp($outputQuantity, '0', 4) <= 0) {
            throw new DomainException('A recipe must state a positive output quantity.');
        }

        if ($lines === []) {
            throw new DomainException('A recipe with no components is not a recipe.');
        }

        $components = Product::query()->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        $existingComponentIds = $editing?->lines->pluck('component_product_id')->all() ?? [];
        $seen = [];

        foreach ($lines as $line) {
            $component = $components[$line['product_id']] ?? null;

            if ($component === null) {
                throw new DomainException('A component was chosen that does not exist in this business.');
            }

            if (! $component->type->isStocked()) {
                throw new DomainException("{$component->name} is a service and cannot be consumed by a run.");
            }

            // C-BOM-8. Inactive is refused only as a NEW component.
            if (! $component->is_active && ! in_array($component->id, $existingComponentIds, true)) {
                throw new DomainException("{$component->name} is inactive and cannot be added to a recipe.");
            }

            if (isset($seen[$component->id])) {
                throw new DomainException("{$component->name} appears twice. A component belongs on one line, with one quantity.");
            }

            $seen[$component->id] = true;

            if (bccomp($line['quantity_per_output'], '0', 4) <= 0) {
                throw new DomainException("{$component->name} needs a quantity greater than zero, or it does not belong in the recipe.");
            }

            $wastage = $line['wastage_percent'] ?? '0';

            if (bccomp($wastage, '0', 4) < 0 || bccomp($wastage, '100', 4) > 0) {
                // ⚠ A guard against a typed decimal point, not a business rule.
                // An allowance above 100% more than doubles the suggestion, and
                // a recipe that really needs that should say so as a quantity.
                throw new DomainException("The wastage allowance on {$component->name} must be between 0 and 100 percent.");
            }
        }

        $cycle = BomCycleCheck::find($outputProduct->id, array_column($lines, 'product_id'), $editing?->id);

        if ($cycle !== null) {
            throw new DomainException('This recipe would contain itself: '.$this->describe($cycle).'.');
        }

        return DB::transaction(function () use ($outputProduct, $lines, $by, $editing, $name, $outputQuantity, $effectiveFrom, $note) {
            // C-BOM-6.
            $mutateInPlace = $editing !== null && ! $editing->hasBeenUsed();

            if ($mutateInPlace) {
                $bom = $editing;
                $bom->fill([
                    'name' => $name,
                    'output_quantity' => $outputQuantity,
                    'effective_from' => $effectiveFrom,
                    'note' => $note,
                ])->save();

                $bom->lines()->delete();
            } else {
                // ⚠ One active version per output product, enforced here rather
                // than by a unique index: MySQL has no partial unique, and a
                // second "active" row would make the run screen pick one of two
                // recipes at random.
                BillOfMaterial::query()
                    ->where('output_product_id', $outputProduct->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);

                $next = (int) BillOfMaterial::query()
                    ->where('output_product_id', $outputProduct->id)
                    ->max('version');

                $bom = BillOfMaterial::create([
                    'output_product_id' => $outputProduct->id,
                    'version' => $next + 1,
                    'name' => $name,
                    'output_quantity' => $outputQuantity,
                    'is_active' => true,
                    'effective_from' => $effectiveFrom,
                    'note' => $note,
                    'created_by' => $by->id,
                ]);
            }

            foreach (array_values($lines) as $index => $line) {
                $bom->lines()->create([
                    'component_product_id' => $line['product_id'],
                    'quantity_per_output' => $line['quantity_per_output'],
                    'wastage_percent' => $line['wastage_percent'] ?? '0',
                    'note' => $line['note'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            return $bom->fresh(['lines.componentProduct', 'outputProduct']);
        });
    }

    /**
     * @param  list<int>  $productIds
     */
    private function describe(array $productIds): string
    {
        $names = Product::query()->whereIn('id', $productIds)->pluck('name', 'id');

        return implode(' → ', array_map(fn (int $id) => $names[$id] ?? "#{$id}", $productIds));
    }
}
