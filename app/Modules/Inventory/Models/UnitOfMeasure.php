<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\RecyclableMaster;
use App\Modules\Inventory\Support\UnitDimension;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A unit of measure, and (17 Oct 2026, Phase 1 Part B) what it measures.
 *
 * `dimension` is mass, volume or count, or null for a unit nobody recognised
 * ("dozen"). `factor_to_base` is how many base units (g or mL) one of it holds
 * and is set ONLY for mass and volume: g = 1, kg = 1000, mL = 1, L = 1000.
 *
 * ⚠⚠ A COUNT UNIT HAS NO FACTOR AND CONVERTS INTO NOTHING BY ITSELF. Jar, box,
 * pack and pieces share a dimension so the screens can say "count", not so
 * they can be swapped: how many pieces a box holds is a fact about one product
 * and lives on its conversion row (ChatGPT REPLY-1, rejected item 3).
 *
 * ⚠ The two columns are filled from the unit's name when it is created
 * (UnitDimension::recognise), never typed: 1 kg = 1000 g is not an opinion.
 */
class UnitOfMeasure extends Model implements Removable
{
    use BelongsToBusiness, HasFactory, RecyclableMaster;

    protected $table = 'units_of_measure';

    protected $fillable = [
        'business_id',
        'name',
        'abbreviation',
        'dimension',
        'factor_to_base',
    ];

    protected function casts(): array
    {
        return [
            'factor_to_base' => 'decimal:8',
        ];
    }

    protected static function booted(): void
    {
        // ⚠ Fills only what is empty: a unit the seeder or the backfill already
        // classified keeps its classification, and a rename never reclassifies.
        static::saving(function (UnitOfMeasure $unit) {
            if ($unit->dimension !== null) {
                return;
            }

            $known = UnitDimension::recognise($unit->name, $unit->abbreviation);

            if ($known !== null) {
                $unit->dimension = $known['dimension'];
                $unit->factor_to_base = $known['factor'];
            }
        });
    }

    public function isCount(): bool
    {
        return $this->dimension === UnitDimension::COUNT;
    }

    /** Mass or volume with a base factor: the only units the ERP converts between by itself. */
    public function isMetric(): bool
    {
        return $this->factor_to_base !== null
            && in_array($this->dimension, [UnitDimension::MASS, UnitDimension::VOLUME], true);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // --- Recycle bin and Switch off (B24, 24 Oct 2026, Q5 = A) -------------

    /** Named pointers; foreign keys at this table are found on their own. */
    public function usageColumns(): array
    {
        return ['unit_of_measure_id', 'unit_id'];
    }

    public function binLabel(): string
    {
        return 'Unit '.$this->name;
    }
}
