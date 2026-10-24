<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\RecyclableMaster;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Warehouse extends Model implements Removable
{
    use BelongsToBusiness, HasFactory, LogsActivity, RecyclableMaster;

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
        'not_for_sale',
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
        'not_for_sale' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'seller_employee_id' => 'integer',
            'not_for_sale' => 'boolean',
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
            ->logOnly(['name', 'code', 'is_default', 'is_active', 'not_for_sale'])
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

    /**
     * ⭐ Initial plan B7 (Joy, 24 Oct 2026; Q7 = A): warehouses stock may be
     * sold, used or sent on from. A "Not for sale" (quarantine) warehouse
     * only holds damaged goods until they move back to stock or are written
     * off, so every picker that leads to a sale, a run, a staff issue or a
     * transfer lists these only.
     */
    public function scopeSaleable(Builder $query): Builder
    {
        return $query->where('warehouses.not_for_sale', false);
    }

    /**
     * ⭐ B7: the one refusal every path that takes stock OUT for use shares
     * (RecordStockOut for a sale, a run, a staff replacement; ReserveStock;
     * DispatchStockTransfer; RecordProductionRun). A write-off, a purchase
     * return and Move back to stock are the ways out of quarantine.
     *
     * @throws DomainException
     */
    public function assertTakesStockFor(string $what): void
    {
        if ($this->not_for_sale) {
            throw new DomainException("{$this->name} is a Not for sale warehouse, so no {$what} can take stock from it. Move what is reusable back to stock first, or write it off.");
        }
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class);
    }

    // --- Recycle bin and Switch off (B24, 24 Oct 2026, Q5 = A) -------------

    /** Named pointers; foreign keys at this table are found on their own. */
    public function usageColumns(): array
    {
        return ['warehouse_id'];
    }

    public function binLabel(): string
    {
        return 'Warehouse '.$this->name;
    }

    public function whyKept(): ?string
    {
        return $this->is_default ? 'The default warehouse stays. Make another one the default first.' : null;
    }

    /** The warehouse every screen preselects is never off (WarehouseController's rule). */
    public function whyNotDeactivatable(): ?string
    {
        return $this->is_default ? 'The default warehouse cannot be switched off. Make another one the default first.' : null;
    }
}
