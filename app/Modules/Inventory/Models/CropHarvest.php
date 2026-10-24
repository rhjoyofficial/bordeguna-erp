<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): a season's cost moved out of a Crops in
 * Progress head into stock, as the batches its lines made.
 *
 * ```
 * Dr  Stock on Hand            the value of the batches made
 *     Cr  Crops in Progress    the amount moved (to the paisa)
 * ```
 *
 * Posted the moment it is recorded (RecordCropHarvest); a wrong one is
 * reversed through the corrections engine while its batches are untouched
 * (CropHarvestCorrector) and stays, marked Reversed.
 */
class CropHarvest extends Model
{
    use BelongsToBusiness, Reversible;

    protected $fillable = [
        'business_id',
        'number',
        'harvested_on',
        'crop_account_id',
        'warehouse_id',
        'amount_paisa',
        'note',
        'journal_id',
        'idempotency_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'harvested_on' => 'date:Y-m-d',
            'amount_paisa' => 'integer',
        ];
    }

    public function amount(): Money
    {
        return Money::ofMinor((int) $this->amount_paisa);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CropHarvestLine::class)->orderByDesc('is_main')->orderBy('id');
    }

    public function cropAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'crop_account_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
