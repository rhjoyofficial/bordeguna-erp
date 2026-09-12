<?php

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A delivery rate on one shop: a name somebody reads and a charge they pay.
 *
 * ⚠ Scoped to a STORE rather than a business. Two shops on this ERP may ship
 * from different places and agree different rates, and the architecture puts
 * shipping rules on the Store for exactly that reason.
 *
 * ⚠ `charge` is a string on the way in and out, because it is a decimal column.
 * Casting money to float is how a total ends up a paisa away from itself.
 */
class ShippingZone extends Model
{
    use LogsActivity;

    protected $fillable = ['store_id', 'name', 'charge', 'sort_order'];

    /**
     * ⚠⚠ `is_default` is NOT fillable. Exactly one band per shop may be the
     * catch-all, and that is an invariant across ROWS rather than a property of
     * one - a mass assignment could set it on a second band and the shop would
     * then have two answers for an unpriced district. `makeDefault()` is the
     * only writer and it clears the others in the same transaction.
     */
    /**
     * ⚠⚠ `charge` IS CAST, and the reason is portability rather than tidiness.
     * The column is decimal(12,2); MySQL hands back "60.00" and SQLite hands
     * back "60" for the same stored value. The suite runs on SQLite and
     * production is MySQL, so without this a money assertion passes locally and
     * a money comparison behaves differently in production - which is the whole
     * class of defect the scratch-MySQL rule exists to catch.
     *
     * ⚠ `decimal:2` keeps it a STRING. Casting money to float is how a total
     * ends up a paisa away from itself.
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'charge' => 'decimal:2'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function districts(): HasMany
    {
        return $this->hasMany(DistrictShippingZone::class);
    }

    /**
     * Make this the shop's catch-all, and make sure nothing else is.
     *
     * ⚠ Both writes in one transaction. Half of this applied leaves a shop with
     * either two catch-alls or none, and "none" means it cannot quote at all.
     */
    public function makeDefault(): void
    {
        $this->getConnection()->transaction(function (): void {
            static::query()
                ->where('store_id', $this->store_id)
                ->whereKeyNot($this->getKey())
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'charge', 'is_default'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
