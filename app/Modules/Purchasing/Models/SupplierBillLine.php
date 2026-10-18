<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Enums\PurchaseEntryMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBillLine extends Model
{
    use BelongsToBusiness, HasFactory;

    public const KIND_GOODS = 'goods';

    public const KIND_EXPENSE = 'expense';

    protected $fillable = [
        'business_id',
        'supplier_bill_id',
        'purchase_order_line_id',
        // ⭐ Ruling P5 (19 Oct 2026): the receipt line a goods line bills, and
        // the kind of line (goods, or expense for a non-PO service line).
        'goods_receipt_line_id',
        'line_kind',
        'expense_account_id',
        'description',
        'quantity',
        'unit_cost',
        'line_total',
        'entry_mode',
        'tax_class_id',
        'tax_rate',
        'tax_amount',
        'prices_include_tax',
        'tax_recoverable',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            // Six places since 17 Oct 2026 (PurchaseLineEntry); `line_total`
            // was always the stored truth on a bill line.
            'unit_cost' => 'decimal:6',
            'line_total' => 'decimal:4',
            'entry_mode' => PurchaseEntryMode::class,
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'prices_include_tax' => 'boolean',
            'tax_recoverable' => 'boolean',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /** ⭐ Ruling P5: the delivery (challan) line this goods line bills. */
    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }

    /** ⭐ Ruling P5: the expense head a non-PO line debits. */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function isExpense(): bool
    {
        return $this->line_kind === self::KIND_EXPENSE;
    }
}
