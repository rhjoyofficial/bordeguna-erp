<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\CatalogueMigrationPlan;
use App\Modules\Inventory\Support\CatalogueText;
use Illuminate\Support\Collection;
use Normalizer;

/**
 * Pairs the catalogue file's rows to the live products, re-derived from the
 * database at the moment it runs - never from a stale mapping (Joy's explicit
 * requirement for this migration).
 *
 * The approved rules, applied in order per row:
 *
 *   1. The row's SKU already exists live: KEEP that product.
 *   2. Same base product, same brand, same grade: RENAME the live product to
 *      the row's SKU.
 *   3. Same base product, same brand, neither side graded: RENAME.
 *   4. Nothing claimed: CREATE if the row is active, SKIP if inactive
 *      (approved decision: inactive products that never existed are not
 *      created).
 *
 * A live product no row claims is RETIRED - deactivated, never deleted. A
 * deleted product cannot explain a historical document.
 *
 * Identity signals:
 *   - base product = name, NFC-normalised, inline pack size and trailing grade
 *     words stripped (ported from the derivation script proven on the real
 *     catalogue on 2026-08-13);
 *   - live grade = the old SKU's trailing segment (LRG / JAM / PREM), because
 *     grade WORDS in a live name are unreliable - "Kalmi Super Premium" is a
 *     product name, not a Premium grade;
 *   - file grade = the grade word in `variant_title` ("1KG Large");
 *   - when several rows compete for one live product, a row whose pack size
 *     matches the pack parsed from the live name wins, otherwise the first row
 *     in file order (1 kg precedes 5 kg in the file, which is what the
 *     approved table encodes). A live product is claimable exactly once.
 *
 * ⚠ Requires a tenant to be entered - it reads business-scoped products.
 */
class PlanCatalogueMigration
{
    private const GRADE_BY_SKU_SUFFIX = [
        'LRG' => 'large',
        'JAM' => 'jambo',
        'PREM' => 'premium',
        'PRM' => 'premium',
    ];

    /**
     * @param  list<array{line:int, values:array<string,string>}>  $rows
     */
    public function handle(array $rows): CatalogueMigrationPlan
    {
        $plan = new CatalogueMigrationPlan;

        $live = Product::all();
        $liveBySku = $live->keyBy('sku');
        $brandIds = $this->brandIdsByKey();

        // What the matcher knows about each live product, derived once.
        $facts = $live->mapWithKeys(fn (Product $p) => [$p->id => [
            'base' => $this->baseKey($p->name),
            'grade' => $this->gradeFromSku($p->sku),
            'pack' => $this->packFromName($p->name),
        ]]);

        $claimed = [];   // product id => file line that claimed it
        $seenSkus = [];  // file sku => line

        // ─── Pass 1: exact SKU. Authoritative, whatever else moved around it.
        foreach ($rows as $row) {
            $line = $row['line'];
            $sku = CatalogueText::clean($row['values']['sku'] ?? '');

            if ($sku === '') {
                $plan->addError($line, '(none)', 'sku is mandatory.');

                continue;
            }

            if (isset($seenSkus[$sku])) {
                $plan->addError($line, $sku, "sku is already used on line {$seenSkus[$sku]}.");

                continue;
            }

            $seenSkus[$sku] = $line;

            $product = $liveBySku->get($sku);

            if ($product !== null) {
                $plan->keeps[] = ['line' => $line, 'sku' => $sku, 'product_id' => $product->id, 'name' => $product->name];
                $claimed[$product->id] = $line;
            }
        }

        if ($plan->hasErrors()) {
            return $plan;
        }

        // ─── Pass 2: base + brand + grade, in file order.
        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];
            $sku = CatalogueText::clean($values['sku']);

            if ($liveBySku->has($sku)) {
                continue; // kept in pass 1
            }

            $brandId = $this->resolveBrandId($values['brand'] ?? '', $line, $sku, $plan, $brandIds);

            if ($brandId === null) {
                continue; // unknown brand is already an error
            }

            $base = $this->baseKey($values['product_name'] ?? '');
            $grade = $this->gradeFromVariant($values['variant_title'] ?? '');
            $pack = $this->packKey($values['pack_size'] ?? '');
            $isActive = strtolower(CatalogueText::clean($values['is_active'] ?? '')) !== 'inactive';

            $candidates = $live
                ->filter(fn (Product $p) => ! isset($claimed[$p->id])
                    && $p->brand_id === $brandId
                    && $facts[$p->id]['base'] === $base
                    && $facts[$p->id]['grade'] === $grade)
                ->sortBy('id')
                ->values();

            if ($candidates->isEmpty()) {
                if ($isActive) {
                    $plan->creates[] = ['line' => $line, 'sku' => $sku, 'name' => CatalogueText::clean($values['name'] ?? '')];
                } else {
                    $plan->skips[] = [
                        'line' => $line, 'sku' => $sku,
                        'name' => CatalogueText::clean($values['name'] ?? ''),
                        'reason' => 'inactive and never existed - not created (approved decision)',
                    ];
                }

                continue;
            }

            $product = $this->pick($candidates, $pack, $facts);

            $claimed[$product->id] = $line;

            $plan->renames[] = [
                'line' => $line,
                'old_sku' => $product->sku,
                'new_sku' => $sku,
                'product_id' => $product->id,
                'name' => $product->name,
                'how' => $grade === null ? 'base + brand' : "base + brand + grade ({$grade})",
            ];
        }

        // ─── Live products nothing claimed: retire, never delete.
        foreach ($live->sortBy('id') as $product) {
            if (isset($claimed[$product->id])) {
                continue;
            }

            $plan->retires[] = [
                'sku' => $product->sku,
                'product_id' => $product->id,
                'name' => $product->name,
                'was_active' => (bool) $product->is_active,
            ];

            // ⚠ Retiring a product that is ACTIVE today deserves the operator's
            // eyes: it usually means "the losing half of a duplicate" (the
            // Bionic Mangrove Gold Honey), and if it ever means anything else,
            // this line is where that is caught.
            if ($product->is_active) {
                $plan->addWarning(0, $product->sku,
                    "\"{$product->name}\" is active today and no file row claims it - it will be deactivated.");
            }
        }

        $this->assertFinalSkusAreUnique($plan, $live, $claimed);

        // The import planner receives everything except the skips: after the
        // renames, every one of these rows matches a live SKU or is a create.
        $skippedLines = array_column($plan->skips, 'line');
        $plan->importRows = array_values(array_filter(
            $rows,
            fn (array $row) => ! in_array($row['line'], $skippedLines, true),
        ));

        return $plan;
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @param  Collection<int|string, array{base:string, grade:?string, pack:?string}>  $facts
     */
    private function pick(Collection $candidates, ?string $pack, Collection $facts): Product
    {
        if ($pack !== null) {
            $byPack = $candidates->first(fn (Product $p) => $facts[$p->id]['pack'] === $pack);

            if ($byPack !== null) {
                return $byPack;
            }
        }

        return $candidates->first();
    }

    /**
     * The identity of a product, independent of pack size and grade.
     *
     * ⚠⚠ NFC FIRST. Production stores decomposed য + nukta; the file stores
     * precomposed য়. They render identically and never compare equal, so
     * without this line every Bangla name reads as a brand-new product.
     */
    private function baseKey(string $name): string
    {
        $n = Normalizer::normalize($name, Normalizer::FORM_C) ?: $name;
        $n = (string) preg_replace('/\s*[-\x{2013}]\s*\d.*$/u', '', $n);
        $n = (string) preg_replace('/\s+\d+\s*(gm|g|kg|ml|l)\b.*$/iu', '', $n);
        $n = (string) preg_replace('/\s+(Jambo|Large|Premium)\s*$/iu', '', $n);
        $n = (string) preg_replace('/\s+/u', ' ', $n);

        return mb_strtolower(trim($n));
    }

    /**
     * ⚠ From the SKU, not the name. "Kalmi Super Premium" carries Premium in
     * its NAME while being ungraded; the old SKUs carry grade as a trailing
     * segment (DATE-MED-LRG, DATE-AJW-PREM) and nothing else does.
     */
    private function gradeFromSku(string $sku): ?string
    {
        $segments = explode('-', $sku);
        $last = strtoupper((string) end($segments));

        return self::GRADE_BY_SKU_SUFFIX[$last] ?? null;
    }

    private function gradeFromVariant(string $variant): ?string
    {
        if (preg_match('/\b(Large|Jambo|Premium)\b/i', $variant, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    /** "1 kg" and "1KG" and "1000 gm" stay distinct; spacing and case do not. */
    private function packKey(string $pack): ?string
    {
        $key = strtolower((string) preg_replace('/\s+/u', '', CatalogueText::clean($pack)));

        if ($key === '') {
            return null;
        }

        return (string) preg_replace('/gm$/', 'g', $key);
    }

    /** The pack a live name states inline, if it states one. */
    private function packFromName(string $name): ?string
    {
        if (preg_match_all('/(\d+)\s*(gm|g|kg|ml|l)\b/iu', $name, $m, PREG_SET_ORDER) === 0) {
            return null;
        }

        $last = end($m);

        return $this->packKey($last[1].$last[2]);
    }

    /**
     * Brands are matched by id, resolved from the file's code (BNC) or name
     * (Bionic) - the same two spellings the catalogue importer accepts.
     *
     * @return array<string, int>
     */
    private function brandIdsByKey(): array
    {
        $ids = [];

        foreach (Brand::all() as $brand) {
            $ids[CatalogueText::unitKey($brand->code)] = $brand->id;
            $ids[CatalogueText::categoryKey($brand->name)] = $brand->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $brandIds
     */
    private function resolveBrandId(string $raw, int $line, string $sku, CatalogueMigrationPlan $plan, array $brandIds): ?int
    {
        $clean = CatalogueText::clean($raw);

        if ($clean === '') {
            $plan->addError($line, $sku, 'brand is mandatory in the migration file - matching is per brand.');

            return null;
        }

        $id = $brandIds[CatalogueText::unitKey($clean)]
            ?? $brandIds[CatalogueText::categoryKey($clean)]
            ?? null;

        if ($id === null) {
            $plan->addError($line, $sku, "unknown brand \"{$clean}\". Brands are never created by a migration.");
        }

        return $id;
    }

    /**
     * After every rename, retire and create, each SKU must name exactly one
     * product. Renames land inside one transaction, so a transient swap would
     * not survive - but a PLAN that ends in a collision is wrong before any
     * SQL runs, and must say so while everything is still readable.
     *
     * @param  Collection<int, Product>  $live
     * @param  array<int, int>  $claimed
     */
    private function assertFinalSkusAreUnique(CatalogueMigrationPlan $plan, Collection $live, array $claimed): void
    {
        $final = [];

        $add = function (string $sku, string $who) use (&$final, $plan) {
            if (isset($final[$sku])) {
                $plan->addError(0, $sku, "after the migration two products would share SKU {$sku}: {$final[$sku]} and {$who}.");

                return;
            }

            $final[$sku] = $who;
        };

        $renamedIds = array_column($plan->renames, 'product_id');

        foreach ($live as $product) {
            if (! in_array($product->id, $renamedIds, true)) {
                $add($product->sku, "live product id {$product->id} (unchanged)");
            }
        }

        foreach ($plan->renames as $rename) {
            $add($rename['new_sku'], "rename of {$rename['old_sku']}");
        }

        foreach ($plan->creates as $create) {
            $add($create['sku'], "create on line {$create['line']}");
        }
    }
}
