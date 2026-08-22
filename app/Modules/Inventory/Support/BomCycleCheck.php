<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\BillOfMaterialLine;

/**
 * A BOM must refuse to contain itself (C-BOM-7).
 *
 * ⚠⚠ AT ANY DEPTH, not just directly. A contains B, B contains C, C contains A
 * is the same mistake as A containing A, and it is the one nobody spots by
 * reading the screen.
 *
 * ⚠⚠ THE WALK IGNORES `is_active`, ON PURPOSE. Phase 1 does not explode
 * sub-assemblies - a run consumes a component as stock and never recurses - so
 * nothing today would actually loop. This check exists so that a LATER phase
 * cannot inherit a poisoned table, and a deactivated version that somebody
 * reactivates next year is exactly how a cycle would arrive. Refusing a recipe
 * because a dormant version would close the loop costs one rename; discovering
 * it after an exploder is written costs an infinite loop in production.
 *
 * ⚠ Reads the whole line table once and walks it in memory. There will be tens
 * of recipes, not thousands, and a recursive query per component would be a
 * query per node of a graph nobody can see the shape of.
 */
final class BomCycleCheck
{
    /**
     * The chain that proves the cycle, output first, or null when there is
     * none.
     *
     * @param  list<int>  $componentProductIds  the components being proposed
     * @return list<int>|null
     */
    public static function find(int $outputProductId, array $componentProductIds, ?int $ignoreBomId = null): ?array
    {
        foreach ($componentProductIds as $componentId) {
            // The direct case, and the only one a person spots unaided.
            if ($componentId === $outputProductId) {
                return [$outputProductId, $componentId];
            }
        }

        $graph = self::graph($ignoreBomId);

        foreach ($componentProductIds as $componentId) {
            $path = self::pathTo($graph, $componentId, $outputProductId, [$outputProductId]);

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * component product id → the products IT is made of, across every version.
     *
     * @return array<int, list<int>>
     */
    private static function graph(?int $ignoreBomId): array
    {
        $rows = BillOfMaterialLine::query()
            ->when($ignoreBomId !== null, fn ($query) => $query->where('bill_of_material_id', '!=', $ignoreBomId))
            ->join('bills_of_material', 'bills_of_material.id', '=', 'bill_of_material_lines.bill_of_material_id')
            ->get(['bills_of_material.output_product_id as output', 'bill_of_material_lines.component_product_id as component']);

        $graph = [];

        foreach ($rows as $row) {
            $graph[(int) $row->output][] = (int) $row->component;
        }

        return $graph;
    }

    /**
     * Depth-first from `$from`, looking for `$target`.
     *
     * @param  array<int, list<int>>  $graph
     * @param  list<int>  $path
     * @return list<int>|null
     */
    private static function pathTo(array $graph, int $from, int $target, array $path): ?array
    {
        // ⚠ Guards against a cycle that ALREADY exists in the stored table as
        // well as the one being proposed. Without it a poisoned row would hang
        // the request rather than refuse the save.
        if (in_array($from, $path, true)) {
            return null;
        }

        $path[] = $from;

        foreach ($graph[$from] ?? [] as $next) {
            if ($next === $target) {
                return [...$path, $next];
            }

            $found = self::pathTo($graph, $next, $target, $path);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
