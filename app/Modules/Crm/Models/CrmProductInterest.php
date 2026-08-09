<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\InterestStatus;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Something a prospect or customer asked for, on a date.
 *
 * ⚠⚠ THE REQUEST IS IMMUTABLE; ONLY THE OUTCOME MAY BE APPENDED. What somebody
 * asked for is a historical fact - "wants 10kg for Eid" was true when they said
 * it, whatever happened afterwards. Linking a sale, closing it as unavailable
 * or withdrawing it must never rewrite the original statement, or the demand
 * history the business plans from becomes a record of the latest opinion.
 *
 * ⛔ NOT A SALES RECORD and not a copy of one. No price, no stock, no product
 * name is stored here; the master data is referenced. Whether they actually
 * bought it is answered by `sales_invoice_lines`.
 */
class CrmProductInterest extends Model
{
    use BelongsToBusiness, LogsActivity;

    /** The only things interest may be about. Same whitelist as activities. */
    public const SUBJECTS = [Lead::class, Customer::class];

    /**
     * ⚠⚠ WHAT THEY ASKED FOR. Frozen at creation, guarded below. Changing any
     * of these would rewrite history rather than record an outcome.
     */
    public const REQUEST_COLUMNS = [
        'subject_type', 'subject_id', 'product_id', 'category_id', 'brand_id',
        'requested_quantity', 'requested_unit_id', 'note', 'requested_at',
    ];

    protected $fillable = [
        'business_id',
        'subject_type',
        'subject_id',
        'product_id',
        'category_id',
        'brand_id',
        'requested_quantity',
        'requested_unit_id',
        'note',
        'requested_at',
        'recorded_by',
        // Outcome only.
        'status',
        'closed_at',
        'closed_by',
        'closed_note',
        'fulfilled_by_invoice_id',
    ];

    /**
     * ⚠ Mirrors the database default. Without it a freshly built interest
     * carries null for status in memory while the stored row is `open`, and a
     * caller reading `$interest->status->isOpen()` fatals on null. This project
     * has met that bug on money_accounts, LeadSource, businesses and CrmTask.
     */
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'status' => InterestStatus::class,
            'requested_quantity' => 'decimal:4',
            'requested_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * ⚠⚠ THE GUARD THAT MAKES "the request is immutable" TRUE rather than
     * merely intended. Enforced on the MODEL so every path meets it - the
     * controller, a future importer, a tinker session - instead of on one route
     * that a second caller bypasses.
     */
    protected static function booted(): void
    {
        static::updating(function (self $interest): void {
            $rewritten = array_keys(array_intersect_key($interest->getDirty(), array_flip(self::REQUEST_COLUMNS)));

            if ($rewritten !== []) {
                throw new RuntimeException(
                    'What was originally asked for cannot be changed ('.implode(', ', $rewritten).
                    '). Close this interest and record a new one.'
                );
            }
        });

        static::deleting(function (): never {
            throw new RuntimeException('An interest is a dated request and cannot be deleted. Withdraw it instead.');
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'closed_at', 'fulfilled_by_invoice_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** ⚠ The unit they SAID, which may not be the product's stock unit. */
    public function requestedUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'requested_unit_id');
    }

    public function fulfilledByInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'fulfilled_by_invoice_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', InterestStatus::Open);
    }

    /** "5 kg of Himsagar Mango", or "Honey", or "LaLaDia". */
    public function what(): string
    {
        return $this->product?->name
            ?? $this->category?->name
            ?? $this->brand?->name
            ?? 'Unspecified';
    }
}
