<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Assets\Enums\AccountAccessRole;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Models\CompanyAccountAccess;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Writes an account the business owns, and who may use it (slice 4).
 *
 * ⚠ The rules live here rather than in the controller because the importer
 * writes the same rows: an employee named as responsible must work in THIS
 * business, a money account must be this business's, access is granted once
 * and revoked by date, never deleted.
 *
 * ⭐ `shared_with` (review ruling 2): the ids of the OTHER businesses of the
 * group the account also serves. Pulled out of the data and synced to the
 * pivot after the row is saved; the home business is never among them.
 */
class SaveCompanyAccount
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $by): CompanyAccount
    {
        $shared = $this->takeShared($data);
        $this->check($data);

        $account = CompanyAccount::create($data + ['created_by' => $by->id]);
        $this->share($account, $shared, $by);

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CompanyAccount $account, array $data, ?User $by = null): CompanyAccount
    {
        $shared = $this->takeShared($data);
        $this->check($data);

        $account->update($data);
        $this->share($account, $shared, $by);

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
        foreach (['responsible_employee_id', 'two_factor_employee_id'] as $field) {
            if (! empty($data[$field]) && ! $this->employeeWorksHere((int) $data[$field])) {
                throw ValidationException::withMessages([$field => 'Choose an employee of this business.']);
            }
        }

        if (! empty($data['renewal_money_account_id']) && ! MoneyAccount::query()->whereKey($data['renewal_money_account_id'])->exists()) {
            throw ValidationException::withMessages(['renewal_money_account_id' => 'Choose a money account of this business.']);
        }
    }

    /**
     * Pull `shared_with` out of the data: null when the caller did not speak
     * of it (leave the pivot alone), else the list of business ids.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>|null
     */
    private function takeShared(array &$data): ?array
    {
        if (! array_key_exists('shared_with', $data)) {
            return null;
        }

        $ids = array_values(array_unique(array_map('intval', (array) $data['shared_with'])));
        unset($data['shared_with']);

        return $ids;
    }

    /**
     * @param  list<int>|null  $ids
     */
    private function share(CompanyAccount $account, ?array $ids, ?User $by): void
    {
        if ($ids === null) {
            return;
        }

        // ⚠ Never its own home, and only businesses that exist.
        $ids = array_values(array_filter($ids, fn (int $id) => $id !== (int) $account->business_id));

        if ($ids !== [] && Business::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['shared_with' => 'One of those businesses does not exist.']);
        }

        $before = $account->sharedBusinesses()->pluck('businesses.id')->sort()->values()->all();
        $account->sharedBusinesses()->sync($ids);
        $after = collect($ids)->sort()->values()->all();

        if ($before !== $after) {
            $names = Business::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ');
            activity('assets.accounts')->performedOn($account)->causedBy($by)
                ->log($ids === [] ? 'No longer shared with another business' : "Also serves: {$names}");
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
