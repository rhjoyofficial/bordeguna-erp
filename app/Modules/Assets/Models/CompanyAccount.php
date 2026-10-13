<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Assets\Enums\CompanyAccountKind;
use App\Modules\Assets\Enums\CompanyAccountStatus;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One account the business owns (asset register slice 4, 13 October 2026):
 * a mailbox, a domain, a hosting panel, a Facebook page, a courier portal, a
 * bank's internet banking.
 *
 * ⚠ The record says WHO is responsible (owner), WHO holds the 2FA device, WHO
 * has access (rows, with dates) and WHEN it renews. Its passwords are not
 * here: they are AssetCredential rows (slice 3), encrypted and revealed under
 * CredentialAccess.
 */
class CompanyAccount extends Model
{
    use BelongsToBusiness, LogsActivity;

    public const REMIND_DAYS_FIRST = 30;

    public const REMIND_DAYS_SECOND = 7;

    protected $fillable = [
        'business_id',
        'kind',
        'name',
        'provider',
        'identifier',
        'url',
        'owner_employee_id',
        'two_factor_employee_id',
        'renews_on',
        'renewal_cost',
        'renewal_money_account_id',
        'recovery_email',
        'recovery_phone',
        'status',
        'note',
        'renewal_reminded_stage',
        'renewal_reminded_for',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CompanyAccountKind::class,
            'status' => CompanyAccountStatus::class,
            'renews_on' => 'date:Y-m-d',
            'renewal_cost' => 'decimal:4',
            'renewal_reminded_for' => 'date:Y-m-d',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // ⚠ The reminder bookkeeping is left out: a stage firing is not a
        // change anyone made to the account.
        return LogOptions::defaults()
            ->useLogName('assets.accounts')
            ->logOnly(['kind', 'name', 'provider', 'identifier', 'url', 'owner_employee_id', 'two_factor_employee_id', 'renews_on', 'renewal_cost', 'renewal_money_account_id', 'recovery_email', 'recovery_phone', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    public function twoFactorHolder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'two_factor_employee_id');
    }

    public function renewalMoneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'renewal_money_account_id');
    }

    public function access(): HasMany
    {
        return $this->hasMany(CompanyAccountAccess::class, 'company_account_id');
    }

    public function activeAccess(): HasMany
    {
        return $this->access()->whereNull('revoked_on');
    }

    public function credentials(): MorphMany
    {
        return $this->morphMany(AssetCredential::class, 'credentialable');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return $this->status === CompanyAccountStatus::Closed;
    }

    /**
     * Which reminder the renewal date calls for today: '30', '7', 'overdue',
     * or null when nothing is due. Closed accounts and accounts with no date
     * never call for one.
     */
    public function renewalStage(Carbon $today): ?string
    {
        if ($this->renews_on === null || $this->isClosed()) {
            return null;
        }

        $days = (int) $today->startOfDay()->diffInDays($this->renews_on->startOfDay(), false);

        return match (true) {
            $days < 0 => 'overdue',
            $days <= self::REMIND_DAYS_SECOND => (string) self::REMIND_DAYS_SECOND,
            $days <= self::REMIND_DAYS_FIRST => (string) self::REMIND_DAYS_FIRST,
            default => null,
        };
    }

    /**
     * Said already, for this renewal date and this stage?
     */
    public function wasRemindedAt(string $stage): bool
    {
        return $this->renewal_reminded_stage === $stage
            && $this->renewal_reminded_for !== null
            && $this->renews_on !== null
            && $this->renewal_reminded_for->isSameDay($this->renews_on);
    }

    /**
     * The chip the list draws: 'overdue', 'soon' (within 30 days) or null.
     */
    public function renewalTone(Carbon $today): ?string
    {
        return match ($this->renewalStage($today)) {
            'overdue' => 'overdue',
            '7', '30' => 'soon',
            default => null,
        };
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', CompanyAccountStatus::Closed->value);
    }
}
