<?php

namespace App\Modules\Commerce\Models;

use App\Support\BdGeo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One district of Bangladesh, and what this shop charges to deliver to it.
 *
 * ⚠⚠ THE DISTRICT IS NORMALISED TO THE DATASET'S OWN SPELLING ON THE WAY IN.
 * Somebody typing "Chittagong" or "Narshingdi" is recording a real decision
 * about a real place, and storing it in their spelling would mean the lookup at
 * checkout - which resolves the customer's district through BdGeo first - would
 * miss it and quietly charge the catch-all rate instead. The shop would be
 * undercharging or overcharging with nothing anywhere saying so.
 */
class DistrictShippingZone extends Model
{
    protected $fillable = ['store_id', 'district', 'shipping_zone_id'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            // ⚠ An unrecognised name is left exactly as typed rather than
            // blanked. It is somebody's decision, and losing it silently would
            // change what a customer pays. It simply will not match anything,
            // which the admin screen can show as unpriced.
            $row->district = BdGeo::districtNamed($row->district) ?? $row->district;
        });
    }
}
