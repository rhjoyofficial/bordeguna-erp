<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\Recyclable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10, Q9 = A): a product family, the
 * "Template item" Joy named (Miniket Rice), grouping separate sizes (1 kg,
 * 25 kg, 50 kg bosta). Each size is an ordinary product with its own SKU,
 * stock, batches and prices; the family only groups them on screens, in the
 * pickers and in reports.
 *
 * ⚠⚠ Nothing that moves stock or money reads this model. Joining or leaving
 * a family changes two columns on the product and nothing else.
 */
class ProductFamily extends Model implements Removable
{
    use BelongsToBusiness, LogsActivity, Recyclable;

    /** Joy's own word for the attribute in the plan. */
    public const DEFAULT_ATTRIBUTE = 'Packaging size';

    protected $fillable = [
        'business_id',
        'name',
        'sku',
        'category_id',
        'brand_id',
        'attribute_name',
        'description',
    ];

    protected $attributes = [
        'attribute_name' => self::DEFAULT_ATTRIBUTE,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'category_id', 'brand_id', 'attribute_name', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** Every size, switched off ones included: a family is never deleted from under them. */
    public function sizes(): HasMany
    {
        return $this->hasMany(Product::class, 'product_family_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * The size a new size copies its unit, type, tracking and selling switches
     * from: the first one added (lowest id). Null when the family is empty.
     */
    public function templateSize(): ?Product
    {
        return $this->sizes()->orderBy('id')->first();
    }

    /** "Miniket Rice - 25 kg", the words every picker shows for a size. */
    public static function sizeLabel(string $familyName, ?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? $familyName : "{$familyName} - {$value}";
    }

    /**
     * The SKU proposed for a new size: the family SKU, a dash, and the size
     * with only letters and digits, upper case (MINIKET + "25 kg" =
     * MINIKET-25KG). A proposal only: the popup lets it be typed over. The
     * screen (resources/js/lib/product-family.ts) uses the same rule.
     */
    public static function proposeSizeSku(string $familySku, string $value): string
    {
        $size = strtoupper((string) preg_replace('/[^A-Za-z0-9.]/', '', $value));
        $base = strtoupper(trim($familySku));

        return $size === '' ? $base : "{$base}-{$size}";
    }

    // --- Recycle bin (Q5, Joy 24 Oct 2026) -----------------------------------

    /**
     * An unused family goes to the Recycle bin. One with sizes cannot: its
     * sizes leave first (Leave family on each), so no product is ever left
     * pointing at a binned family.
     */
    public function whyNotRemovable(): ?string
    {
        $count = $this->sizes()->count();

        return $count === 0
            ? null
            : "{$this->name} still has {$count} size".($count === 1 ? '' : 's').'. Use Leave family on each size first.';
    }

    public function whyNotDeactivatable(): ?string
    {
        return 'A family is not switched off. Switch off its sizes, or delete it once it has none.';
    }

    /** A family restored from the bin must not take a SKU a live family now uses. */
    public function whyNotRestorable(): ?string
    {
        $taken = self::query()->where('sku', $this->sku)->whereKeyNot($this->getKey())->exists();

        return $taken ? "Another family now uses the SKU {$this->sku}. Change that one first." : null;
    }

    public function binLabel(): string
    {
        return 'Product family '.$this->name." ({$this->sku})";
    }
}
