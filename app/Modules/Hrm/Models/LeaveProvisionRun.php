<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One true-up of the leave encashment liability.
 *
 * ⚠ The `plan` rows are the explanation, not decoration: employee, encashable
 * days, the PAY-02 daily rate they were priced at, and the liability that
 * produced. "Why did the provision move" is answered from this document alone.
 */
class LeaveProvisionRun extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'valued_on',
        'is_dry_run',
        'plan',
        'employees_affected',
        'total_liability',
        'opening_balance',
        'delta',
        'note',
        'run_by',
    ];

    protected function casts(): array
    {
        return [
            'valued_on' => 'date',
            'is_dry_run' => 'boolean',
            'plan' => 'array',
            'employees_affected' => 'integer',
            'total_liability' => 'decimal:4',
            'opening_balance' => 'decimal:4',
            'delta' => 'decimal:4',
        ];
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function deltaMoney(): Money
    {
        return Money::ofTaka((string) $this->delta);
    }

    public function totalLiabilityMoney(): Money
    {
        return Money::ofTaka((string) $this->total_liability);
    }
}
