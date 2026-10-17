<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Part B (17 Oct 2026, ChatGPT REPLY-1 Q2): what each unit MEASURES.
 *
 * `dimension` = mass | volume | count | NULL (not recognised), and
 * `factor_to_base` = how many base units (g or mL) one of it holds, set only
 * for mass and volume. g = 1, kg = 1000, mL = 1, L = 1000. The ERP derives
 * g<->kg and mL<->L from these and nobody ever types those ratios.
 *
 * ⚠⚠ COUNT UNITS GET NO FACTOR. pcs, box, jar, pack, bottle, packet share a
 * dimension so a screen can say "count"; they are NOT interchangeable, and a
 * product converts between them only through its own sourced row.
 *
 * ⚠ Additive and nullable. The backfill below recognises the units already
 * typed into production ("Kg", "gm", "Ltr", "Piece") by the same alias table
 * the units seeder uses, in bounded batches, and leaves every unit it does
 * not recognise at NULL. Nothing else about a unit changes.
 */
return new class extends Migration
{
    /**
     * The alias table AS IT STOOD when this migration was written. Deliberately
     * inline rather than a call into UnitDimension: a migration must do the
     * same thing next year as it did on deploy day.
     *
     * @var array<string, array{0: list<string>, 1: ?string}> dimension => [aliases, factor]
     */
    private const KNOWN = [
        'g' => [['g', 'gm', 'gms', 'gr', 'grm', 'gram', 'grams', 'গ্রাম'], '1'],
        'kg' => [['kg', 'kgs', 'kilo', 'kilogram', 'kilograms', 'কেজি'], '1000'],
        'mL' => [['ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters', 'মিলি'], '1'],
        'L' => [['l', 'lt', 'ltr', 'litre', 'litres', 'liter', 'liters', 'লিটার'], '1000'],
        'count' => [[
            'pcs', 'pc', 'pce', 'piece', 'pieces', 'nos', 'পিস',
            'box', 'boxes', 'bx', 'বক্স',
            'jar', 'jars', 'জার',
            'pack', 'packs', 'pk', 'প্যাক',
            'bottle', 'bottles', 'btl', 'বোতল',
            'packet', 'packets', 'pkt', 'প্যাকেট',
        ], null],
    ];

    private const DIMENSION_OF = ['g' => 'mass', 'kg' => 'mass', 'mL' => 'volume', 'L' => 'volume', 'count' => 'count'];

    public function up(): void
    {
        Schema::table('units_of_measure', function (Blueprint $table) {
            $table->string('dimension', 10)->nullable()->after('abbreviation');
            // Eight places, like every conversion factor in this module.
            $table->decimal('factor_to_base', 20, 8)->nullable()->after('dimension');
        });

        $this->backfill();
    }

    /**
     * Public so a test can run exactly this against units typed in by hand and
     * prove what it recognises and what it leaves alone.
     */
    public function backfill(): void
    {
        DB::table('units_of_measure')
            ->whereNull('dimension')
            ->orderBy('id')
            ->chunkById(500, function ($units) {
                foreach ($units as $unit) {
                    $known = $this->recognise($unit->name, $unit->abbreviation);

                    if ($known === null) {
                        continue;
                    }

                    DB::table('units_of_measure')->where('id', $unit->id)->update([
                        'dimension' => $known['dimension'],
                        'factor_to_base' => $known['factor'],
                    ]);
                }
            });
    }

    /**
     * @return array{dimension: string, factor: ?string}|null
     */
    private function recognise(?string $name, ?string $abbreviation): ?array
    {
        $keys = array_filter([
            mb_strtolower(trim((string) $abbreviation)),
            mb_strtolower(trim((string) $name)),
        ], fn (string $key) => $key !== '');

        foreach (self::KNOWN as $unit => [$aliases, $factor]) {
            if (array_intersect($keys, $aliases) !== []) {
                return ['dimension' => self::DIMENSION_OF[$unit], 'factor' => $factor];
            }
        }

        return null;
    }

    public function down(): void
    {
        Schema::table('units_of_measure', function (Blueprint $table) {
            $table->dropColumn(['dimension', 'factor_to_base']);
        });
    }
};
