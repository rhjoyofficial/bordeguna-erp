<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the money was spent on.
 *
 * ⚠ The line names its expense account directly. See the migration for why this
 * document is the one place that is right rather than a departure from the
 * mapping principle.
 */
class ExpenseVoucherLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'expense_voucher_id',
        'account_id',
        'description',
        'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(ExpenseVoucher::class, 'expense_voucher_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
