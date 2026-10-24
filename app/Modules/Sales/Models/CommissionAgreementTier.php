<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One step of a target deal: reach `threshold` (units or Tk) inside the
 * agreement's dates and the person earns `reward`. With several steps the
 * HIGHEST step reached is what is earned, not the sum of the steps.
 */
class CommissionAgreementTier extends Model
{
    protected $fillable = ['commission_agreement_id', 'threshold', 'reward'];

    protected function casts(): array
    {
        return ['threshold' => 'decimal:4', 'reward' => 'decimal:4'];
    }
}
