<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Assets\Enums\AccountAccessRole;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Support\CompanyAccountImportPlan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes an accounts plan, entirely or not at all (slice 4). Each account
 * saves through the model, so the activity log records who changed what;
 * each new access row goes through SaveCompanyAccount::grant, so it is
 * logged like one granted by hand.
 */
class ApplyCompanyAccountImport
{
    public function __construct(private SaveCompanyAccount $save) {}

    /**
     * @return array{created:int, updated:int, access:int}
     */
    public function handle(CompanyAccountImportPlan $plan, User $by): array
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('Refusing to apply: the sheet has '.count($plan->errors).' error(s). Nothing has been written.');
        }

        return DB::transaction(function () use ($plan, $by) {
            $access = 0;
            $today = now()->toDateString();

            foreach ($plan->creates as $create) {
                $account = $this->save->create($create['data'], $by);

                foreach ($create['users'] as $employeeId) {
                    $this->save->grant($account, $employeeId, AccountAccessRole::User, $today, 'From the accounts import', $by);
                    $access++;
                }
            }

            foreach ($plan->updates as $update) {
                $account = CompanyAccount::query()->findOrFail($update['id']);

                if ($update['changes'] !== []) {
                    $this->save->update($account, $update['data'], $by);
                }

                foreach ($update['users'] as $employeeId) {
                    $this->save->grant($account, $employeeId, AccountAccessRole::User, $today, 'From the accounts import', $by);
                    $access++;
                }
            }

            return ['created' => count($plan->creates), 'updated' => count($plan->updates), 'access' => $access];
        });
    }
}
