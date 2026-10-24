<?php

namespace App\Modules\Sales\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'sales_order_id',
        'product_id',
        'quantity_ordered',
        'quantity_delivered',
        'unit_price',
        'line_discount',
        'price_note',
        'bundle_label',
        // Owner, 17 Oct 2026: "extra sealed", "gift wrap".
        'description',
        // ⭐ B18 (25 Oct 2026): a free gift, at zero price.
        'is_gift',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:4',
            'quantity_delivered' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_discount' => 'decimal:4',
            'is_gift' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The challans that fulfilled (part of) this line.
     *
     * ⚠ Written in the same commit as the column that makes it possible -
     * pattern 7 of the guard lessons, same note as DeliveryLine::movements().
     */
    public function deliveryLines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'sales_order_line_id');
    }

    /**
     * ⚠ Ordered but not yet delivered. On an open order this is what is owed;
     * on a Closed (short-closed) order it is the recorded shortfall, visible
     * forever with no per-line mutation. The order book reports this number.
     */
    public function quantityRemaining(): string
    {
        return number_format(
            (float) $this->quantity_ordered - (float) $this->quantity_delivered,
            4, '.', ''
        );
    }

    /**
     * The line at its agreed price, before any discount, rounded to the paisa.
     *
     * ⚠ Rounded PER LINE, the same rule `SalesOrder::goodsTotal()` documents:
     * the figure printed beside a line and the total printed under the column
     * must agree when somebody adds them with a pencil.
     */
    public function grossTotal(): Money
    {
        return Money::ofProduct((string) $this->quantity_ordered, (string) $this->unit_price);
    }

    public function discount(): Money
    {
        return Money::ofTaka((string) ($this->line_discount ?? '0'));
    }

    /**
     * What the customer agreed to pay for this line.
     */
    public function netTotal(): Money
    {
        return Money::ofMinor($this->grossTotal()->minor - $this->discount()->minor);
    }

    /**
     * This line's agreed discount, for part of its quantity.
     *
     * ⚠ In proportion to the quantity, rounded half away from zero at the
     * paisa, in integers - never a float, for the reason `Money` gives. Used
     * where a share is wanted and nothing has been billed yet (credit exposure
     * on what is still to be delivered).
     */
    public function discountShare(string $quantity): Money
    {
        $discount = $this->discount()->minor;
        $ordered = self::scaled((string) $this->quantity_ordered);

        if ($discount === 0 || $ordered === 0) {
            return Money::zero();
        }

        $part = self::scaled($quantity);
        $product = $discount * $part;
        $minor = intdiv($product, $ordered);

        if (($product % $ordered) * 2 >= $ordered) {
            $minor++;
        }

        return Money::ofMinor(min($minor, $discount));
    }

    /**
     * The discount an invoice should bill for this quantity of this line.
     *
     * ⭐⭐ THE AGREED DISCOUNT TRAVELS TO THE INVOICE the way the agreed price
     * already does (C-SO-4). An order that agreed a bundle saving and an
     * invoice that billed full price would be the shop quoting one number and
     * the books charging another.
     *
     * ⚠⚠ THE LAST PORTION TAKES THE REMAINDER, not another rounded share. A
     * line invoiced in parts rounds each part, and rounded parts need not sum
     * back to the whole - so the invoice that bills the last of the line bills
     * whatever discount is still unbilled. The same rule `cost_released`
     * follows for GDNI, for the same reason: a figure that never quite clears
     * cannot be told apart from a real mistake.
     */
    public function discountToBill(string $quantity): Money
    {
        $discount = $this->discount();

        if ($discount->isZero()) {
            return Money::zero();
        }

        $lineIds = $this->deliveryLines()->pluck('id');

        $billed = Money::ofTaka((string) (SalesInvoiceLine::query()
            ->whereIn('delivery_line_id', $lineIds)
            ->sum('line_discount') ?: '0'));

        $unbilled = Money::ofMinor(max(0, $discount->minor - $billed->minor));

        $invoiced = self::scaled((string) ($this->deliveryLines()->sum('quantity_invoiced') ?: '0'));
        $stillToInvoice = self::scaled((string) $this->quantity_ordered) - $invoiced;

        if (self::scaled($quantity) >= $stillToInvoice) {
            return $unbilled;
        }

        return Money::ofMinor(min($this->discountShare($quantity)->minor, $unbilled->minor));
    }

    /** "12.3400" to 123400: four places, the precision quantities are stored at. */
    private static function scaled(string $quantity): int
    {
        $raw = trim($quantity);
        $negative = str_starts_with($raw, '-');
        [$whole, $fraction] = str_contains($raw, '.') ? explode('.', ltrim($raw, '+-'), 2) : [ltrim($raw, '+-'), ''];
        $value = ((int) $whole) * 10000 + (int) substr($fraction.'0000', 0, 4);

        return $negative ? -$value : $value;
    }
}
