<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⚠⚠ TAX WITHHELD FROM INTEREST THE BUSINESS PAYS ON A LOAN (ChatGPT
 * confirmation, point 6): effective-dated, with its legal basis, confirmed by
 * the accountant before it is switched on. Never a hard-coded 10%.
 *
 * ITA 2023 s.104 asks a "specified person" paying interest to anyone other
 * than a bank or finance company to withhold 10%. Whether Bor de Guna is one
 * is the accountant's call, so the seeded rule is OFF. A lender with an NBR
 * certificate for a reduced or nil rate carries it on its own loan
 * (LoanContract::tds_certificate_rate).
 *
 * When it applies: TDS = rate x GROSS interest, Cr 2180 TDS Payable, and the
 * lender is paid gross interest less the TDS plus the principal.
 */
class LoanTdsRule extends Model
{
    use BelongsToBusiness, LogsActivity;

    public const INTEREST_PAID_ON_LOANS_TAKEN = 'interest_paid_on_loans_taken';

    public const DEFAULT_PAYEE_KINDS = ['company', 'individual', 'government', 'other'];

    protected $fillable = ['business_id', 'name', 'applies_to', 'payee_kinds', 'rate', 'legal_basis', 'effective_from', 'effective_to', 'is_enabled', 'confirmed_by', 'confirmed_on', 'updated_by'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_enabled' => false,
        'applies_to' => self::INTEREST_PAID_ON_LOANS_TAKEN,
    ];

    protected function casts(): array
    {
        return [
            'payee_kinds' => 'array',
            'rate' => 'decimal:4',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'confirmed_on' => 'date:Y-m-d',
            'is_enabled' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'payee_kinds', 'rate', 'legal_basis', 'effective_from', 'effective_to', 'is_enabled', 'confirmed_by', 'confirmed_on'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function scopeInForceOn(Builder $query, string $date): Builder
    {
        return $query->where('is_enabled', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
