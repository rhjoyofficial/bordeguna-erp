<?php

namespace App\Modules\Crm\Console\Commands;

use App\Models\User;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Notifications\FollowUpsDueNotification;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tells each person what they owe somebody today.
 *
 * ⚠⚠ WHY THIS EXISTS. CRM tasks carried a due date from the first day and
 * nothing ever looked at one: the follow-up queue had to be opened by somebody
 * who already remembered to open it, which is what a follow-up system is for
 * removing. A due date nobody is told about is a note.
 *
 * ⚠ ONE DIGEST PER PERSON PER RUN, never one message per task. Ten alerts in a
 * morning are ten things to dismiss, and the eleventh gets dismissed with them.
 *
 * ⚠ Overdue tasks are included every day, deliberately. A follow-up that was
 * missed yesterday is more urgent today, not less, and going quiet after the
 * first mention is how a system teaches people to ignore it.
 *
 * ⚠⚠ Tenant-scoped per business, and the tasks are read INSIDE that scope. CRM
 * tasks belong to a business; a global query would tell one company's manager
 * about another's customers.
 */
class NotifyFollowUpsDue extends Command
{
    protected $signature = 'crm:notify-follow-ups
        {--business= : Limit to one business id}
        {--dry-run : Show who would be told, and tell nobody}';

    protected $description = 'Send each person a single digest of the CRM follow-ups they owe today, and the ones they already owed';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::now()->endOfDay();

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        $sent = 0;

        foreach ($businesses as $business) {
            TenantScope::enter($business->id);

            // ⚠ Only assigned tasks. An unassigned follow-up is nobody's, and
            // notifying everybody about it would make it nobody's twice over.
            // It still shows on the queue screen, which is where it gets picked
            // up.
            $tasks = CrmTask::query()
                ->open()
                ->whereNotNull('assigned_to_user_id')
                ->whereNotNull('due_at')
                ->where('due_at', '<=', $today)
                ->orderBy('due_at')
                ->get()
                ->groupBy('assigned_to_user_id');

            foreach ($tasks as $userId => $group) {
                $user = User::find($userId);

                if ($user === null) {
                    continue;
                }

                $overdue = $group->filter(fn (CrmTask $task) => $task->due_at->lt(Carbon::today()))->count();
                $dueToday = $group->count() - $overdue;

                // The oldest three by name. A digest that lists forty is a wall
                // of text; three is enough to recognise the work.
                $titles = $group->take(3)->map(fn (CrmTask $task) => $task->title)->values()->all();

                $this->line(sprintf(
                    '%s: %d due today, %d already late',
                    $user->name,
                    $dueToday,
                    $overdue,
                ));

                if (! $dryRun) {
                    $user->notify(new FollowUpsDueNotification($dueToday, $overdue, $titles));
                    $sent++;
                }
            }
        }

        TenantScope::leave();

        $this->info($dryRun
            ? 'Dry run. Nobody was told.'
            : sprintf('%d person(s) told.', $sent));

        return self::SUCCESS;
    }
}
