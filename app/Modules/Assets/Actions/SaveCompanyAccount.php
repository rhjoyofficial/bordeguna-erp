<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Assets\Enums\AccountAccessRole;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Models\CompanyAccountAccess;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Writes an account the business owns, and who may use it (slice 4).
 *
 * ⚠ The rules live here rather than in the controller because the importer
 * writes the same rows: an employee named as owner must work in THIS
 * business, a money account must be this business's, access is granted once
 * and revoked by date, never deleted.
 */
class SaveCompanyAccount
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $by): CompanyAccount
    {
        $this->check($data);

        return CompanyAccount::create($data + ['created_by' => $by->id]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CompanyAccount $account, array $data): CompanyAccount
    {
        $this->check($data);

        $account->update($data);

        return $account;
    }

    /**
     * Give an employee access. Refused while they already hold it: "granted
     * twice" would leave two rows to revoke and nobody sure which is current.
     */
    public function grant(CompanyAccount $account, int $employeeId, AccountAccessRole $role, string $grantedOn, ?string $note, User $by): CompanyAccountAccess
    {
        if (! $this->employeeWorksHere($employeeId)) {
            throw ValidationException::withMessages(['employee_id' => 'Choose an employee of this business.']);
        }

        if ($account->activeAccess()->where('employee_id', $employeeId)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'This person already has access. Revoke it first to change the role.']);
        }

        $access = $account->access()->create([
            'employee_id' => $employeeId,
            'role' => $role,
            'granted_on' => $grantedOn,
            'granted_by' => $by->id,
            'note' => $note,
        ]);

        $name = Employee::query()->whereKey($employeeId)->first()?->fullName() ?? "employee #{$employeeId}";
        activity('assets.accounts')->performedOn($account)->causedBy($by)->log("Access granted to {$name} as {$role->label()}");

        return $access;
    }

    public function revoke(CompanyAccount $account, CompanyAccountAccess $access, string $revokedOn, User $by): void
    {
        if ($access->company_account_id !== $account->id) {
            throw ValidationException::withMessages(['access' => 'That access row belongs to another account.']);
        }

        if ($access->revoked_on !== null) {
            throw ValidationException::withMessages(['access' => 'This access was already revoked on '.$access->revoked_on->toDateString().'.']);
        }

        if (Carbon::parse($revokedOn)->lt($access->granted_on)) {
            throw ValidationException::withMessages(['revoked_on' => 'Revoked before it was granted ('.$access->granted_on->toDateString().').']);
        }

        $access->update(['revoked_on' => $revokedOn]);

        $name = $access->employee?->fullName() ?? "employee #{$access->employee_id}";
        activity('assets.accounts')->performedOn($account)->causedBy($by)->log("Access revoked from {$name}");
    }

    /**
     * The renewal was paid: the date moves on, the reminder cycle starts again.
     *
     * ⚠ Logged by hand and the model's own log switched off for the write, so
     * the history says "renewed" once rather than "renews_on changed" twice.
     */
    public function markRenewed(CompanyAccount $account, string $nextRenewsOn, ?string $cost, User $by): CompanyAccount
    {
        $next = Carbon::parse($nextRenewsOn)->startOfDay();

        if ($account->renews_on !== null && $next->lte($account->renews_on)) {
            throw ValidationException::withMessages(['next_renews_on' => 'The next renewal must be after '.$account->renews_on->toDateString().'.']);
        }

        $was = $account->renews_on?->toDateString() ?? 'none';

        $account->disableLogging();
        $account->update([
            'renews_on' => $next->toDateString(),
            'renewal_cost' => $cost === null || $cost === '' ? $account->renewal_cost : $cost,
            'renewal_reminded_stage' => null,
            'renewal_reminded_for' => null,
        ]);
        $account->enableLogging();

        activity('assets.accounts')->performedOn($account)->causedBy($by)
            ->log("Marked renewed: next renewal {$next->toDateString()} (was {$was})");

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function check(array $data): void
    {
        foreach (['owner_employee_id' => 'owner_employee_id', 'two_factor_employee_id' => 'two_factor_employee_id'] as $field => $key) {
            if (! empty($data[$field]) && ! $this->employeeWorksHere((int) $data[$field])) {
                throw ValidationException::withMessages([$key => 'Choose an employee of this business.']);
            }
        }

        if (! empty($data['renewal_money_account_id']) && ! MoneyAccount::query()->whereKey($data['renewal_money_account_id'])->exists()) {
            throw ValidationException::withMessages(['renewal_money_account_id' => 'Choose a money account of this business.']);
        }
    }

    private function employeeWorksHere(int $employeeId): bool
    {
        // ⚠ `assignments` is business-scoped, so this is "works in the current
        // business", the same test the hand-over form applies.
        return Employee::query()->whereKey($employeeId)
            ->where('status', '!=', 'separated')
            ->whereHas('assignments')
            ->exists();
    }
}
