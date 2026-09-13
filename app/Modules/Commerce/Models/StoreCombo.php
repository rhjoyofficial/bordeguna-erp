<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A bundle of finished products one shop sells together (D25).
 *
 * ⚠⚠ Not a product. It holds no stock and has no SKU; on an order it explodes
 * into the real product lines. See the migration for why its `price` is allowed
 * to exist outside the price list, and what it is never allowed to do.
 */
class StoreCombo extends Model
{
    use BelongsToBusiness;
    use LogsActivity;

    protected $fillable = [
        'store_id',
        'slug',
        'title',
        'summary',
        'image_media_id',
        'price',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'position' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoreComboItem::class)->orderBy('id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(StoreMedia::class, 'image_media_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * ⛔ `published_at` is not fillable, for the reason `StoreProduct` records:
     * a publish written as `create([... 'published_at' => now()])` once
     * published nothing in silence.
     */
    public function publish(): void
    {
        $this->forceFill(['published_at' => now()])->save();
    }

    public function unpublish(): void
    {
        $this->forceFill(['published_at' => null])->save();
    }

    /**
     * ⚠ Price changes are logged: a bundle price is money a customer is shown,
     * and "who made it cheaper, and when" is a question somebody will ask.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['store_id', 'slug', 'title', 'price', 'published_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
