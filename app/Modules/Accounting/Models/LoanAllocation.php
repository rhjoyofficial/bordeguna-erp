<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one entry settled one schedule row. Counted only while its
 * entry is live (Support\Loans\LoanPosition).
 */
class LoanAllocation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['loan_entry_id', 'loan_schedule_row_id', 'principal_minor', 'interest_minor'];

    protected function casts(): array
    {
        return [
            'principal_minor' => 'integer',
            'interest_minor' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LoanEntry::class, 'loan_entry_id');
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(LoanScheduleRow::class, 'loan_schedule_row_id');
    }
}
