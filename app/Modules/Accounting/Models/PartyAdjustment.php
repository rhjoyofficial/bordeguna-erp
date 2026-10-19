<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A write-off, discount or set-off against specific invoices and bills
 * (see the migration for the postings).
 */
class PartyAdjustment extends Model
{
    use BelongsToBusiness, LogsActivity;

    public const KINDS = [
        'customer_write_off' => 'Bad debt written off',
        'customer_discount' => 'Discount allowed to a customer',
        'supplier_discount' => 'Discount received from a supplier',
        'set_off' => 'Set-off (customer and supplier are the same party)',
        // ⭐ Courier hub slice 8: paisa a courier did not collect because the
        // cash on delivery was rounded to whole taka.
        'rounding' => 'Rounding difference on a customer invoice',
        // ⭐ Supplier advances (ruling P2, 18 Oct 2026): a supplier advance used
        // against a bill. Written only by ApplySupplierAdvances, whose
        // application document owns the journal; never chosen on this screen.
        'advance_applied' => 'Supplier advance applied to a bill',
        // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): the allocation a
        // SupplierDiscount keeps on its bill. Raised only by that document,
        // whose own journal reduces stock cost; never chosen on this screen.
        'supplier_trade_discount' => 'Trade discount from a supplier',
        'supplier_settlement_discount' => 'Settlement discount from a supplier',
        // ⭐ Corrections wave 1 (20 Oct 2026): the allocation a price-only
        // SalesCreditNote keeps on its invoice. Raised only by that document,
        // whose own journal (Dr 4200 / Cr 1140) it carries; never chosen here.
        'customer_credit_note' => 'Price credit note to a customer',
    ];

    /** Kinds another document owns and posts: raised and undone only through that document, never here. */
    public const OWNED_BY_DOCUMENTS = ['set_off', 'advance_applied', 'supplier_trade_discount', 'supplier_settlement_discount', 'customer_credit_note'];

    protected $fillable = ['business_id', 'kind', 'adjusted_on', 'customer_id', 'supplier_id', 'amount', 'reason', 'created_by', 'voucher_id'];

    protected function casts(): array
    {
        return ['adjusted_on' => 'date:Y-m-d', 'amount' => 'decimal:4'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PartyAdjustmentAllocation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('party_adjustment');
    }
}
