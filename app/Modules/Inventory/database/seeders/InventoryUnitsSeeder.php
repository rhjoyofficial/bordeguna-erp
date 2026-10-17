<?php

namespace App\Modules\Inventory\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Support\UnitDimension;
use Illuminate\Database\Seeder;

/**
 * The everyday units, so a new business can create its first product on day
 * one.
 *
 * ⚠⚠ WHY THIS EXISTS (21 Sep 2026). A unit is REQUIRED on every product, and
 * the only seeder that planted units was InventoryDemoDataSeeder - which
 * returns early in production, correctly, because it also invents a catalogue
 * and stock. So a business set up for real had no units at all and the
 * product form was a dead end until somebody found the Units screen.
 *
 * ⚠ PRODUCTION-SAFE AND ADDITIVE. Units are master data, not demo data; this
 * writes nothing else. It only ADDS a unit the business does not already have,
 * never renames, edits or deletes one, so running it again (deploy/README.md
 * names `db:seed --force` as the way permissions reach production) changes
 * nothing that already exists.
 *
 * ⚠⚠ "Already has" is matched on the ALIASES below, against both the name
 * and the abbreviation, case-insensitively. Production's units were typed in
 * by hand and by the catalogue import, so a business with "Ltr" or "Piece"
 * would otherwise be handed a second litre and a second piece, and every
 * product picker after that would offer two of each. Case matters twice:
 * MySQL's collation treats "ML" and "mL" as the same abbreviation (the unique
 * index would refuse the insert), SQLite does not.
 */
class InventoryUnitsSeeder extends Seeder
{
    /**
     * abbreviation => [name, aliases that mean the same unit, dimension, factor].
     *
     * ⭐ Phase 1 Part B (17 Oct 2026): the table lives in UnitDimension, the
     * one place the ERP recognises a unit by name, and carries what each unit
     * MEASURES: g and kg are mass (base g), mL and L are volume (base mL), the
     * rest are count with NO factor (a jar converts into nothing by itself).
     *
     * @var array<string, array{0: string, 1: list<string>, 2: string, 3: ?string}>
     */
    private const UNITS = UnitDimension::CATALOGUE;

    public function run(): void
    {
        $previous = app(BusinessContext::class)->id();

        foreach (Business::withoutGlobalScopes()->orderBy('id')->pluck('id') as $businessId) {
            // ⚠ Inside the tenant: UnitOfMeasure is business-scoped and
            // BusinessScope fails closed, so outside it the "already has" check
            // would read an empty table and add every unit again.
            TenantScope::enter($businessId);

            $existing = UnitOfMeasure::query()->get(['id', 'name', 'abbreviation', 'dimension', 'factor_to_base']);

            $known = $existing
                ->flatMap(fn (UnitOfMeasure $unit) => [mb_strtolower(trim($unit->name)), mb_strtolower(trim($unit->abbreviation))])
                ->unique()
                ->all();

            foreach (self::UNITS as $abbreviation => [$name, $aliases, $dimension, $factor]) {
                if (array_intersect($aliases, $known) !== []) {
                    continue;
                }

                UnitOfMeasure::create([
                    'business_id' => $businessId,
                    'name' => $name,
                    'abbreviation' => $abbreviation,
                    'dimension' => $dimension,
                    'factor_to_base' => $factor,
                ]);
            }

            // ⚠ The UPDATER half (Phase 1 Part B): a unit that already existed
            // and is still unclassified ("Kg", "Ltr", "Piece" typed by hand) is
            // given its dimension and factor. Fills NULL only; a classified unit
            // and everything else about every unit stay as they are.
            foreach ($existing as $unit) {
                if ($unit->dimension !== null) {
                    continue;
                }

                $recognised = UnitDimension::recognise($unit->name, $unit->abbreviation);

                if ($recognised !== null) {
                    $unit->forceFill(['dimension' => $recognised['dimension'], 'factor_to_base' => $recognised['factor']])->save();
                }
            }
        }

        // ⚠ Put the caller back. A seeder that walks every tenant and finishes
        // outside all of them evicts whatever called it.
        if ($previous === null) {
            TenantScope::leave();
        } else {
            TenantScope::enter($previous);
        }
    }
}
