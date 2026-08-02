<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\MoneyAccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A bank account, a cash box or a mobile money wallet.
 *
 * ⚠ Carries no balance on purpose. It answers "where did the money move", and
 * "how much is in it" is a ledger question that arrives with the General Ledger.
 */
class MoneyAccount extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'name',
        'type',
        'account_number',
        'bank_name',
        'branch',
        'routing_number',
        'provider',
        'currency',
        'note',
        'is_active',
    ];

    /**
     * ⚠ Mirrors the database defaults IN THE MODEL. Without this, a freshly
     * created account has `is_active` = null in memory while the stored row is
     * true - so a guard reading `! $account->is_active` refuses a perfectly
     * active account, and only until somebody reloads it. A default that lives
     * only in the schema makes the object and the row disagree.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'currency' => 'BDT',
    ];

    protected function casts(): array
    {
        return [
            'type' => MoneyAccountType::class,
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'account_number', 'bank_name', 'branch', 'provider', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * How this account reads on a screen: "BRAC Bank - Current (1501...7001)".
     */
    public function label(): string
    {
        return $this->account_number
            ? "{$this->name} ({$this->account_number})"
            : $this->name;
    }
}
