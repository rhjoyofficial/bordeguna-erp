<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One tax rate, in force from a date.
 *
 * ⚠⚠ NEVER EDITED IN PLACE. A rate change is a NEW ROW with a later
 * `effective_from`; the old row stays exactly as it was. Rates change by
 * government order, and an invoice raised under the old one must remain
 * explicable for as long as it is kept. This is the same shape `ProductPrice`
 * and `AccountMapping` already use, deliberately - three effective-dated things
 * behaving three different ways would be three things to get wrong.
 *
 * ⛔ NOTHING CALCULATES WITH THIS YET. VAT-READY-ARCHITECTURE.md, C-VAT-3.
 */
class TaxRate extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = ['business_id', 'tax_class_id', 'rate', 'effective_from', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            // ⚠ A percentage, not money: 15.0000 means fifteen percent. It does
            // not go through Money, which counts paisa.
            'rate' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tax_class_id', 'rate', 'effective_from'])
            ->dontSubmitEmptyLogs();
    }

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "15.0000" reads as "15%". */
    public function label(): string
    {
        return rtrim(rtrim((string) $this->rate, '0'), '.').'%';
    }
}
