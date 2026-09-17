<?php

namespace App\Modules\Assets\Console\Commands;

use App\Models\User;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Http\Controllers\AssetMaintenanceController;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenancePlan;
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Assets\Notifications\AssetMaintenanceDueNotification;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tells whoever keeps the register what maintenance is waiting (slice A4).
 *
 * ⚠ One digest per person per run; nothing is sent when nothing waits.
 * Overdue plans are included every day until the work is recorded: going
 * quiet after the first mention teaches people to ignore it.
 *
 * ⛔ It reminds and nothing else. It never opens a record or moves a date.
 */
class RemindAssetMaintenance extends Command
{
    protected $signature = 'assets:maintenance-due
        {--business= : Limit to one business id}
        {--dry-run : Show who would be told, and tell nobody}';

    protected $description = 'Send each asset keeper a digest of maintenance due, faults open and warranties ending';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();
        $until = $today->copy()->addDays(AssetMaintenanceController::ENDING_WITHIN_DAYS)->toDateString();
        $sent = 0;

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            // ⚠ Read INSIDE the tenant scope, so one business's keeper never
            // hears about another's things.
            TenantScope::enter($business->id);

            $due = AssetMaintenancePlan::query()->due($today)->with('item:id,tag')->orderBy('next_due_on')->get();
            $open = AssetMaintenanceRecord::query()->where('state', 'open')->count();
            // Each warranty and each service contract counts, as the screen lists them.
            $ending = collect(['warranty_ends_on', 'service_ends_on'])->sum(fn (string $column) => AssetItem::query()
                ->where('status', '!=', AssetStatus::Disposed->value)
                ->whereDate($column, '>=', $today->toDateString())
                ->whereDate($column, '<=', $until)
                ->count());

            $this->line(sprintf('%s: %d due, %d open, %d ending', $business->name, $due->count(), $open, $ending));

            if ($due->isEmpty() && $open === 0 && $ending === 0) {
                continue;
            }

            $named = $due->take(4)->map(fn (AssetMaintenancePlan $plan) => "{$plan->item?->tag} {$plan->title} (due {$plan->next_due_on->toDateString()})")->values()->all();
            $overdue = $due->filter(fn (AssetMaintenancePlan $plan) => $plan->isOverdue($today))->count();

            // ⚠ Whoever may record the work, not everyone who may read the list.
            $keepers = User::query()
                ->whereHas('businesses', fn ($q) => $q->where('businesses.id', $business->id))
                ->get()
                ->filter(fn (User $user) => $user->can('assets.items.manage'));

            foreach ($keepers as $user) {
                if ($dryRun) {
                    $this->line('  would tell '.$user->email);

                    continue;
                }

                $user->notify(new AssetMaintenanceDueNotification($due->count(), $overdue, $open, $ending, $named));
                $sent++;
            }
        }

        TenantScope::leave();

        $this->info($dryRun ? 'Dry run: nothing sent.' : "Sent {$sent} digest(s).");

        return self::SUCCESS;
    }
}
