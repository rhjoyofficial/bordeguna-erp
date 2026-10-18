<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⚠ P1 (18 Oct 2026, practice run): on Joy's real data the unit list held only
 * dz, g, kg, L and pcs. No mL for oils, no box, jar, pack, bottle or packet.
 * The Phase 1 units migration (2026_10_17_150001) classified the units that
 * EXISTED and planted none, and InventoryUnitsSeeder (which does plant them)
 * is not run on production by habit (seeders only by --class).
 *
 * ⚠⚠ ADDITIVE ONLY. Inserts a standard unit into each business that lacks it;
 * never edits, renames or deletes a unit. "Lacks it" is matched on the aliases
 * below against both the name and the abbreviation, case-insensitively, the
 * same rule the seeder uses: a business with "ML", "Ltr" or "Bottles" is not
 * handed a second one (and MySQL's collation would refuse "mL" beside "ML" on
 * the unique index anyway). Running it twice changes nothing.
 *
 * The list is inline, frozen as of today: a migration must do next year what
 * it did on deploy day, whatever UnitDimension says by then.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: list<string>, 2: string, 3: ?string}> abbreviation => [name, aliases, dimension, factor] */
    private const UNITS = [
        'mL' => ['Millilitre', ['ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters', 'মিলি'], 'volume', '1'],
        'box' => ['Box', ['box', 'boxes', 'bx', 'বক্স'], 'count', null],
        'jar' => ['Jar', ['jar', 'jars', 'জার'], 'count', null],
        'pack' => ['Pack', ['pack', 'packs', 'pk', 'প্যাক'], 'count', null],
        'bottle' => ['Bottle', ['bottle', 'bottles', 'btl', 'বোতল'], 'count', null],
        'packet' => ['Packet', ['packet', 'packets', 'pkt', 'প্যাকেট'], 'count', null],
    ];

    public function up(): void
    {
        $this->plant();
    }

    /** Public so a test can run exactly this against a chart that already has some units. */
    public function plant(): void
    {
        $now = now();

        foreach (DB::table('businesses')->orderBy('id')->pluck('id') as $businessId) {
            $known = DB::table('units_of_measure')
                ->where('business_id', $businessId)
                ->get(['name', 'abbreviation'])
                ->flatMap(fn ($unit) => [mb_strtolower(trim((string) $unit->name)), mb_strtolower(trim((string) $unit->abbreviation))])
                ->unique()
                ->all();

            foreach (self::UNITS as $abbreviation => [$name, $aliases, $dimension, $factor]) {
                if (array_intersect($aliases, $known) !== []) {
                    continue;
                }

                DB::table('units_of_measure')->insert([
                    'business_id' => $businessId,
                    'name' => $name,
                    'abbreviation' => $abbreviation,
                    'dimension' => $dimension,
                    'factor_to_base' => $factor,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** Nothing: a planted unit may already be on a product by the time anyone rolls back. */
    public function down(): void {}
};
