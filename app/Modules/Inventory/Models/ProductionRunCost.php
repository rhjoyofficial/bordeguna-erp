<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Enums\ProductionCostKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cost of a production run besides its materials: labour, overhead,
 * packaging labour, other (PR3, 18 Oct 2026). Added to the output batch's
 * cost at confirm and posted Dr Stock on Hand / Cr `account_id`; the run's
 * reversal undoes both through the journal reversal and the batch's own layer.
 */
class ProductionRunCost extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'production_run_id',
        'kind',
        'account_id',
        'amount_paisa',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ProductionCostKind::class,
            'amount_paisa' => 'integer',
        ];
    }

    public function amount(): Money
    {
        return Money::ofMinor((int) $this->amount_paisa);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
