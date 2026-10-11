<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ EVERY PRODUCT HAS A WEIGHT (Joy, 11 Oct 2026, courier hub owner decision 1).
 * A parcel's weight is worked out from its lines, so each SKU needs one.
 *
 * - products.weight_grams: the weight of ONE stock unit as it ships, in whole
 *   grams. Null = not known yet, and ⚠ never read as zero (ruling 10).
 * - products.weight_source: where the number came from - `manual` (typed on
 *   the product or the weights sheet), `from_net_content` (filled below), or
 *   `learned` (reserved for the slice that learns packed weight from courier
 *   hub weights). Null when there is no weight.
 *
 * ⚠ THE PRE-FILL ONLY TRUSTS MASS. A 400 g pack is filled as 400 g; a 5 L jar
 * is left empty rather than guessed through a density, and shows "weight
 * missing" until somebody weighs one. Matched on the aliases the units seeder
 * uses, since production's units were typed by hand ("gm", "Kg").
 *
 * The aliases live here on purpose, not in a class: a migration must keep
 * meaning what it meant on the day it ran.
 */
return new class extends Migration
{
    private const GRAM_ALIASES = ['g', 'gm', 'gms', 'gr', 'grm', 'gram', 'grams', 'গ্রাম'];

    private const KILOGRAM_ALIASES = ['kg', 'kgs', 'kilo', 'kilogram', 'kilograms', 'কেজি'];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('weight_grams')->nullable()->after('content_unit_id');
            $table->string('weight_source', 30)->nullable()->after('weight_grams');
        });

        $this->prefill();
    }

    /**
     * Public so the test can run it against rows it planted. Touches only
     * products with no weight yet, so running it twice changes nothing.
     */
    public function prefill(): void
    {
        $factors = [];

        foreach (DB::table('units_of_measure')->get(['id', 'name', 'abbreviation']) as $unit) {
            $keys = [mb_strtolower(trim((string) $unit->abbreviation)), mb_strtolower(trim((string) $unit->name))];

            if (array_intersect($keys, self::GRAM_ALIASES) !== []) {
                $factors[$unit->id] = 1;
            } elseif (array_intersect($keys, self::KILOGRAM_ALIASES) !== []) {
                $factors[$unit->id] = 1000;
            }
        }

        if ($factors === []) {
            return;
        }

        DB::table('products')
            ->whereNull('weight_grams')
            ->whereNotNull('content_quantity')
            ->whereIn('content_unit_id', array_keys($factors))
            ->orderBy('id')
            ->get(['id', 'content_quantity', 'content_unit_id'])
            ->each(function ($product) use ($factors) {
                $grams = (int) round((float) $product->content_quantity * $factors[$product->content_unit_id]);

                if ($grams <= 0) {
                    return;
                }

                DB::table('products')->where('id', $product->id)->update([
                    'weight_grams' => $grams,
                    'weight_source' => 'from_net_content',
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['weight_grams', 'weight_source']);
        });
    }
};
