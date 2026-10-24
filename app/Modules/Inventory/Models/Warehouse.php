<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Warehouse extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'name',
        'code',
        'is_default',
        'is_active',
        // ⭐ Initial plan B21 (24 Oct 2026, Joy's Q3 = A): the retail seller
        // (HR employee) whose "seller stock" this is. Null for an ordinary
        // warehouse. Written only by OpenSellerStock.
        'seller_employee_id',
    ];

    /**
     * ⚠ The database defaults, repeated on the model. A column default only
     * applies once the row is written, so a freshly created Warehouse instance
     * has a NULL `is_active` and any caller checking `! $warehouse->is_active`
     * refuses a warehouse the database considers active.
     *
     * Latent until Sales slice 2 asked the question on 2026-08-04. Same
     * convention as Account, MoneyAccount and Customer.
     */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'seller_employee_id' => 'integer',
        ];
    }

    /**
     * ⚠⚠ The CODE is the opening-stock import's matching key, written once per
     * warehouse for the life of the business - and until this trait landed it
     * could be changed with no trail while Product edits were logged. Same
     * field set discipline as Product: identity and switches, nothing chatty.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'is_default', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Make this the business's default warehouse, and make sure nothing else is.
     *
     * ⚠ Both writes in one transaction. Half of this applied leaves a business
     * with two defaults or none, and every screen that preselects "the default"
     * (Stock, Opening stock, Stock count, the counter) would then pick
     * whichever row the database returned first. Same shape as
     * ShippingZone::makeDefault.
     *
     * ⚠ Row by row, not a mass update, so each change lands in the activity log
     * like any other edit of `is_default`.
     */
    public function makeDefault(): void
    {
        $this->getConnection()->transaction(function (): void {
            static::query()
                ->where('is_default', true)
                ->whereKeyNot($this->getKey())
                ->get()
                ->each(fn (self $other) => $other->update(['is_default' => false]));

            $this->update(['is_default' => true]);
        });
    }

    /**
     * ⭐ Initial plan B21: a retail seller's own stock (consignment). Still the
     * company's goods, but only the seller sheets move or sell them: no
     * counter, order, challan, staff purchase or warehouse transfer may (see
     * SellerStockGate), so FEFO for every other sale never reaches into it.
     */
    public function isSellerStock(): bool
    {
        return $this->seller_employee_id !== null;
    }

    /** Warehouses an ordinary sale, purchase or transfer may pick: never a seller stock. */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->qualifyColumn('seller_employee_id'));
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class);
    }
}
