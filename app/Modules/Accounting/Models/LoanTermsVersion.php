<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One set of terms a loan has had, from the day it took effect. Version 1 is
 * the approved agreement; each approved amendment adds the next. A version is
 * never changed once written: the history IS the audit trail of a restructure.
 */
class LoanTermsVersion extends Model
{
    protected $fillable = ['loan_contract_id', 'version', 'effective_from', 'terms', 'reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'terms' => 'array',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanContract::class, 'loan_contract_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
