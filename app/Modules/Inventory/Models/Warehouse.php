<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\RecyclableMaster;
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
