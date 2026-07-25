<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Hrm\Enums\SalaryComponentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a payslip, stored with the quantity, rate, formula and source
 * that produced it (PAY-13).
 *
 * Amounts are always positive; `type` decides which side of the payslip the
 * line falls on, so a stray sign can never turn a deduction into a payment.
 * Tenant scoping is inherited through the payslip.
 */
class PayslipLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'payslip_id',
        'code',
        'label',
        'type',
        'amount',
        'quantity',
        'rate',
        'formula',
        'source',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'type' => SalaryComponentType::class,
            'amount' => 'decimal:2',
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'sequence' => 'integer',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function isEarning(): bool
    {
        return $this->type === SalaryComponentType::Earning;
    }
}
