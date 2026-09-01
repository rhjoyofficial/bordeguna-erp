<?php

namespace App\Modules\Purchasing\Console\Commands;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\Actions\RollRecurringExpenses;
use App\Modules\Purchasing\Models\RecurringExpenseOccurrence;
use App\Modules\Purchasing\Notifications\RecurringExpensesDueNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Rolls every standing cost forward, then tells somebody what is about to be due.
 *
 * ⚠⚠ WHY THIS EXISTS. Nikola Host is due on the last day of every month and
 * nothing in this ERP has ever mentioned it. Miss it and the sites go down. A
 * due date nobody is told about is a note.
 *
 * ⚠ ONE DIGEST PER PERSON PER RUN, never one message per bill - the same shape
 * as the CRM follow-up digest, and for the same reason: ten alerts in a morning
 * are ten things to dismiss, and the eleventh gets dismissed with them.
 *
 * ⚠ OVERDUE ITEMS ARE INCLUDED EVERY DAY, deliberately. A bill missed yesterday
 * is more urgent today, not less, and going quiet after the first mention is how
 * a system teaches people to ignore it.
 *
 * ⛔ It creates reminders and nothing else. No voucher, no journal, no money.
 */
class RollAndRemindRecurringExpenses extends Command
{
    protected $signature = 'purchasing:recurring-expenses
        {--business= : Limit to one business id}
        {--dry-run : Show who would be told, and tell nobody}';

    protected $description = 'Generate upcoming standing costs and send each person a digest of what is due';

    public function handle(RollRecurringExpenses $roll): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = CarbonImmutable::today();

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        $sent = 0;

        foreach ($businesses as $business) {
            TenantScope::enter($business->id);

            $created = $roll->handle($today);

            // ⚠ Read INSIDE the tenant scope. A global query would tell one
            // company's manager about another company's subscriptions.
            $due = RecurringExpenseOccurrence::with('recurringExpense')
                ->due()
                ->get()
                ->filter(function (RecurringExpenseOccurrence $o) use ($today) {
                    $lead = (int) ($o->recurringExpense?->lead_days ?? 3);

                    // Everything overdue, plus everything inside its own warning
                    // window. A yearly domain renewal wants more notice than a
                    // monthly bill, which is why the window is per schedule.
                    return $o->due_on->lte($today->addDays($lead));
                });

            $this->line(sprintf('%s: %d generated, %d due', $business->name, $created, $due->count()));

            if ($due->isEmpty()) {
                continue;
            }

            foreach ($this->recipients($business) as $user) {
                if ($dryRun) {
                    $this->line('  would tell '.$user->email);

                    continue;
                }

                $user->notify(new RecurringExpensesDueNotification($due->values()->all()));
                $sent++;
            }
        }

        TenantScope::leave();

        $this->info($dryRun ? 'Dry run: nothing sent.' : "Sent {$sent} digest(s).");

        return self::SUCCESS;
    }

    /**
     * ⚠ Whoever may RECORD the payment, not whoever may read the list. Telling
     * everybody about a bill makes it nobody's.
     *
     * @return Collection<int, User>
     */
    private function recipients(Business $business)
    {
        return User::query()
            ->whereHas('businesses', fn ($q) => $q->whereKey($business->id))
            ->get()
            ->filter(fn (User $user) => $user->can('purchasing.expense.record'));
    }
}
