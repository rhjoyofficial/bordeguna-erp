<?php

namespace App\Modules\Foundation\Console\Commands;

use App\Models\User;
use App\Modules\Foundation\Mail\MorningBriefingMail;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;

/**
 * The 07:00 briefing to the people who read money (accounting.view).
 * Figures come from the same arithmetic the screens use; a business with
 * no such reader sends nothing at all.
 */
class SendMorningBriefing extends Command
{
    protected $signature = 'erp:morning-briefing';

    protected $description = 'Mail the morning briefing to everyone with accounting.view';

    public function handle(): int
    {
        foreach (Business::withoutGlobalScopes()->get() as $business) {
            app(BusinessContext::class)->set($business->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

            $readers = User::query()
                ->whereHas('businesses', fn ($query) => $query->where('businesses.id', $business->id))
                ->get()
                ->filter(fn (User $user) => $user->can('accounting.view'));

            if ($readers->isEmpty()) {
                continue;
            }

            $figures = $this->figures($business->id);

            foreach ($readers as $reader) {
                Mail::to($reader->email)->queue(new MorningBriefingMail($figures, $business->name));
            }

            $this->info("Briefing sent for {$business->name} to {$readers->count()} reader(s).");
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function figures(int $businessId): array
    {
        $yesterday = Carbon::yesterday()->toDateString();

        return [
            'date' => Carbon::today()->format('d M Y'),
            'yesterday_sales' => round((float) DB::table('sales_invoices')
                ->where('business_id', $businessId)
                ->whereDate('invoice_date', $yesterday)
                ->where('is_opening', false)
                ->sum('total'), 2),
            'cash_now' => round((float) DB::table('journal_lines')
                ->where('business_id', $businessId)
                ->whereNotNull('money_account_id')
                ->sum('amount_minor') / 100, 2),
            'due_this_week' => DB::table('recurring_expense_occurrences')
                ->join('recurring_expenses', 'recurring_expenses.id', '=', 'recurring_expense_occurrences.recurring_expense_id')
                ->where('recurring_expense_occurrences.business_id', $businessId)
                ->where('recurring_expense_occurrences.status', 'due')
                ->whereDate('due_on', '<=', Carbon::today()->addDays(7)->toDateString())
                ->orderBy('due_on')
                ->get(['recurring_expenses.name', 'due_on', 'expected_amount'])
                ->map(fn ($row) => [
                    'name' => $row->name,
                    'due_on' => $row->due_on,
                    'amount' => (float) $row->expected_amount,
                ])->all(),
            'tickets_overdue' => DB::table('crm_tickets')
                ->where('business_id', $businessId)
                ->whereIn('status', ['open', 'in_progress'])
                ->where('resolve_by', '<', now())
                ->count(),
        ];
    }
}
