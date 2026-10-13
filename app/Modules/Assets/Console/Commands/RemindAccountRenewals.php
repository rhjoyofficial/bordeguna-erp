<?php

namespace App\Modules\Assets\Console\Commands;

use App\Models\User;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Notifications\AccountRenewalDueNotification;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tells the account's responsible employee and whoever manages the hub that a renewal is
 * near or late (slice 4). Three stages - 30 days, 7 days, overdue - each said
 * ONCE per renewal date; marking the account renewed moves the date and the
 * cycle starts again. Closed accounts never remind.
 *
 * ⛔ It reminds and nothing else. It never moves a date.
 */
class RemindAccountRenewals extends Command
{
    protected $signature = 'assets:remind-renewals
        {--business= : Limit to one business id}
        {--dry-run : Show who would be told, and tell nobody}';

    protected $description = 'Remind the employees responsible for accounts and the hub managers of renewals due in 30 days, 7 days, or overdue';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();
        $sent = 0;

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            // ⚠ Read INSIDE the tenant scope: one business's owner never hears
            // about another's accounts, and `can()` answers for this business.
            TenantScope::enter($business->id);

            $accounts = CompanyAccount::query()->open()->whereNotNull('renews_on')
                ->with('responsible:id,user_id')->orderBy('renews_on')->get();

            $managers = User::query()
                ->whereHas('businesses', fn ($q) => $q->where('businesses.id', $business->id))
                ->get()
                ->filter(fn (User $user) => $user->can('assets.accounts.manage'));

            $told = 0;

            foreach ($accounts as $account) {
                $stage = $account->renewalStage($today);

                if ($stage === null || $account->wasRemindedAt($stage)) {
                    continue;
                }

                $recipients = $managers->keyBy('id');
                $ownerUser = $account->responsible?->user_id ? User::query()->find($account->responsible->user_id) : null;

                if ($ownerUser !== null && $ownerUser->businesses()->where('businesses.id', $business->id)->exists()) {
                    $recipients->put($ownerUser->id, $ownerUser);
                }

                $days = (int) $today->copy()->startOfDay()->diffInDays($account->renews_on->copy()->startOfDay(), false);
                $this->line(sprintf('  %s: stage %s (%s), %d recipient(s)', $account->name, $stage, $account->renews_on->toDateString(), $recipients->count()));

                if ($dryRun) {
                    foreach ($recipients as $user) {
                        $this->line('    would tell '.$user->email);
                    }

                    continue;
                }

                foreach ($recipients as $user) {
                    $user->notify(new AccountRenewalDueNotification($account->id, $account->name, $account->kind->label(), $account->renews_on->toDateString(), $stage, $days));
                    $sent++;
                }

                // ⚠ Query builder: a stage firing is bookkeeping, not an edit
                // anyone made, so it stays out of the account's history.
                CompanyAccount::query()->whereKey($account->id)
                    ->update(['renewal_reminded_stage' => $stage, 'renewal_reminded_for' => $account->renews_on->toDateString()]);
                $told++;
            }

            $this->line(sprintf('%s: %d account(s) reminded', $business->name, $told));
        }

        TenantScope::leave();

        $this->info($dryRun ? 'Dry run: nothing sent.' : "Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
