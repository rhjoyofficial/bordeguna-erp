<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One installment: what falls due on a day, principal and interest apart.
 *
 * ⚠ What has been PAID against it is never stored here: it is the sum of the
 * allocations of the loan's live entries (Support\Loans\LoanPosition), so a
 * reversed repayment un-pays the row without anybody touching it.
 *
 * ⚠ Rows belong to a terms version. An amendment writes a new set and keeps
 * the old ones as history.
 */
class LoanScheduleRow extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'loan_contract_id', 'version', 'sequence', 'due_date', 'principal_due_minor', 'interest_due_minor'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'sequence' => 'integer',
            'due_date' => 'date:Y-m-d',
            'principal_due_minor' => 'integer',
            'interest_due_minor' => 'integer',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanContract::class, 'loan_contract_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LoanAllocation::class);
    }
}
