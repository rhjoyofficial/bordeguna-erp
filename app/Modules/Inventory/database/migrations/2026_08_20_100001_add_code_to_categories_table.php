<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-SKU-1. Categories get a stored SKU segment, and it is backfilled from the
 * SKUs the business already uses rather than from the category names.
 *
 * ⚠⚠ WHY IT CANNOT BE DERIVED. The live segments do not follow from the names:
 * Nuts is MIX, Salts is SLT, Seeds is SED, Sweeteners is SWT, Fruits is FRT. A
 * generator that took the first three letters would propose BNC-SEE-CHI-500G
 * next to the existing BNC-SED-CHI-500G, and the catalogue would quietly split
 * into two families of the same product.
 *
 * ⚠ The backfill reads real data, so it changes nothing it is unsure of: a
 * category whose products disagree, or whose products carry no conventional
 * SKU, is left NULL and the generator declines to propose for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Nullable on purpose. A category with no code is not broken; it
            // simply cannot have a SKU proposed for it until someone says what
            // its segment is.
            //
            // ⚠ Wide enough for a family path such as `PK-JAR`, not only a
            // single three-letter segment.
            $table->string('code', 16)->nullable()->after('slug');
        });

        $this->backfillFromExistingSkus();
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }

    /**
     * For every category, look at what its own products' SKUs already say the
     * segment is, and adopt it only when they all agree.
     */
    private function backfillFromExistingSkus(): void
    {
        // The brand codes in use, per business. A SKU only votes if its first
        // segment is a real brand code, which is what keeps legacy off-convention
        // SKUs such as MANGO-BAN from teaching Fruits that its segment is BAN.
        $brandCodes = [];

        foreach (DB::table('brands')->get(['business_id', 'code']) as $brand) {
            $code = strtoupper((string) $brand->code);

            if ($code !== '') {
                $brandCodes[$brand->business_id][$code] = true;
            }
        }

        foreach (DB::table('categories')->get(['id', 'business_id']) as $category) {
            $known = $brandCodes[$category->business_id] ?? [];

            if ($known === []) {
                continue;
            }

            $candidates = [];

            $skus = DB::table('products')
                ->where('category_id', $category->id)
                ->pluck('sku');

            foreach ($skus as $sku) {
                $segments = explode('-', strtoupper((string) $sku));

                // Needs a brand, a category segment and at least an item, or it
                // is not the convention and has no opinion worth counting.
                if (count($segments) < 3) {
                    continue;
                }

                if (! isset($known[$segments[0]])) {
                    continue;
                }

                if (! preg_match('/^[A-Z]{2,8}$/', $segments[1])) {
                    continue;
                }

                $candidates[$segments[1]] = true;
            }

            // ⚠ Exactly one candidate, or nothing. Two disagreeing segments mean
            // the category really does hold two families and a human should say
            // which one wins.
            if (count($candidates) === 1) {
                DB::table('categories')
                    ->where('id', $category->id)
                    ->update(['code' => array_key_first($candidates)]);
            }
        }
    }
};
