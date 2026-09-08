<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One ERP product, published to one store.
 *
 * ⛔⛔ THE PRODUCT MASTER IS NOT FORKED. This row says a product APPEARS on a
 * store and how it is PRESENTED there. It does not restate what the product is.
 * The name, the unit, the SKU and the category live in the catalogue and have
 * exactly one owner; a second copy that starts equal is a second copy that ends
 * different, and the shop is then telling a customer something the warehouse
 * does not know.
 *
 * ⛔⛔ AND THERE IS NO PRICE COLUMN HERE, WHICH IS THE ENFORCEMENT. ADR-005 says
 * a website is B2C and each website IS a price group, so what a visitor pays is
 * decided by the store's price group against the ordinary price list. A price
 * override on this row would be a second opinion about money, reachable from a
 * content screen, and invisible to every report that reads `product_prices`.
 *
 * ⛔ There is no stock, availability or delivery column either, for the reason
 * recorded in the section contract: those are not the website's to state.
 */
class StoreProduct extends Model
{
    use BelongsToBusiness;
    use LogsActivity;

    protected $fillable = [
        'store_id',
        'product_id',
        'slug',
        'title_override',
        'summary_override',
        'image_media_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * ⚠ The image is chosen from THIS store's own library, never by URL. Same
     * rule as a page section: a dangling reference or another shop's
     * photography is the defect the media library was built to make impossible.
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(StoreMedia::class, 'image_media_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * ⛔⛔ `published_at` IS NOT FILLABLE, AND THAT IS NOT A DETAIL. Putting a
     * product in front of the public is an act somebody takes, not a field a
     * form posts - the same rule `Page` already follows.
     *
     * ⚠⚠ It was learned the hard way here. The publish action was written as
     * `create([... 'published_at' => now()])`, mass assignment dropped the key
     * silently, and publishing a product **published nothing** while reporting
     * success. Unpublishing was the same in reverse. Nothing errored; the
     * catalogue simply said everything was off sale. These two methods exist so
     * that cannot be written by accident again.
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
     * What the shop calls this product.
     *
     * ⚠ The override is presentation, not a rename: "Sundarban Honey, 500g jar"
     * on the website where the ERP calls it "HON-SUN-500". Falling back to the
     * master means a store that overrides nothing still has a complete
     * catalogue, which is what makes publishing cheap.
     */
    public function displayTitle(): string
    {
        return $this->title_override ?: (string) $this->product?->name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['store_id', 'product_id', 'slug', 'title_override', 'published_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
