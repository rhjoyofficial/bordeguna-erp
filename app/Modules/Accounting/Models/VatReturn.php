<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One VAT period, worked out and filed (VAT plan D9).
 *
 * ⚠ A FILED return is a record of what was declared. Its figures are frozen on
 * the row: the screen for a filed month shows these, never a recalculation, so
 * a document dated back into the month later shows up as a difference to be
 * explained rather than silently changing a return already sent to NBR.
 */
class VatReturn extends Model
{
    use BelongsToBusiness;

    public const FILED = 'filed';

    protected $fillable = [
        'business_id', 'return_number', 'regime', 'period_start', 'period_end', 'status',
        'output_tax_minor', 'input_tax_minor', 'adjustment_minor', 'adjustment_note',
        'credit_brought_forward_minor', 'turnover_minor', 'turnover_tax_rate',
        'net_payable_minor', 'credit_carried_forward_minor',
        'journal_id', 'filed_by', 'filed_at', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'filed_at' => 'datetime',
            'turnover_tax_rate' => 'decimal:4',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function netPayable(): Money
    {
        return Money::ofMinor((int) $this->net_payable_minor);
    }
}
