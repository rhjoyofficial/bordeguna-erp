<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Assets\Enums\AccountAccessRole;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's access to one account (slice 4): granted on a date, revoked
 * on a date, never deleted. "Who had the Facebook page in March" is a
 * question this table must still answer in December.
 *
 * ⚠ Not business-scoped itself; it hangs off the account, which is.
 */
class CompanyAccountAccess extends Model
{
    protected $table = 'company_account_access';

    protected $fillable = [
        'company_account_id',
        'employee_id',
        'role',
        'granted_on',
        'revoked_on',
        'granted_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'role' => AccountAccessRole::class,
            'granted_on' => 'date:Y-m-d',
            'revoked_on' => 'date:Y-m-d',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class, 'company_account_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_on === null;
    }
}
