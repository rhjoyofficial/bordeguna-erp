<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\CashFlowForecast;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Support\ApprovalInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The Business Health feed (Module 7, Slice 1): the morning walk around the
 * shop, done by the system.
 *
 * Every signal is computed FRESH from the module that owns it and links to
 * the screen where the fix lives. The feed invents no state and stores
 * nothing - there is no flag to go stale, and no signal can disagree with
 * its own module's screen. A viewer sees only the signals their permissions
 * let them act on; a broken signal source drops that signal rather than the
 * page (a health check that itself falls ill helps nobody).
 */
class BusinessHealthController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $businessId = app(BusinessContext::class)->id();
        $signals = [];

        $add = function (string $permission, callable $signal) use (&$signals, $user) {
            if ($permission !== '' && ! $user->can($permission)) {
                return;
            }
            try {
                $signals[] = $signal();
            } catch (Throwable) {
                // The feed never falls over one sick source.
            }
        };

        $add('crm.leads.view', fn () => [
            'key' => 'tickets_overdue',
            'label' => 'Tickets past their deadline',
            'count' => DB::table('crm_tickets')
                ->where('business_id', $businessId)
                ->whereIn('status', ['open', 'in_progress'])
                ->where('resolve_by', '<', now())
                ->count(),
            'url' => '/crm/tickets',
            'hint' => 'Late is late - resolve them or the record says so forever.',
        ]);

        $add('crm.leads.view', fn () => [
            'key' => 'followups_overdue',
            'label' => 'Follow-ups past due',
            'count' => DB::table('crm_tasks')
                ->where('business_id', $businessId)
                ->where('status', 'open')
                ->where('due_at', '<', now())
                ->count(),
            'url' => '/crm/queue',
            'hint' => 'Calls the cadences and colleagues are waiting on.',
        ]);

        $add('hrm.employees.manage', fn () => [
            'key' => 'probation_overdue',
            'label' => 'Probations ended, nobody confirmed',
            'count' => Employee::query()
                ->where('status', 'active')
                ->where('employment_type', 'probation')
                ->whereNull('confirmation_date')
                ->whereNotNull('probation_months')
                ->whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))
                ->get()
                ->filter(fn (Employee $employee) => $employee->joining_date !== null
                    && $employee->joining_date->copy()->addMonthsNoOverflow((int) $employee->probation_months)->lte(now()))
                ->count(),
            'url' => '/hrm/employees',
            'hint' => 'Open the profile and press Confirm, or extend the probation.',
        ]);

        $add('hrm.attendance.manage', fn () => [
            'key' => 'attendance_review',
            'label' => 'Attendance days needing review',
            'count' => DB::table('daily_attendances')
                ->where('business_id', $businessId)
                ->where('is_provisional', true)
                ->count(),
            'url' => '/hrm/attendance/daily',
            'hint' => 'Provisional days; some may be holding payroll.',
        ]);

        $add('inventory.reports.view', fn () => [
            'key' => 'low_stock',
            'label' => 'Products under their low-stock line',
            'count' => DB::table('products')
                ->where('products.business_id', $businessId)
                ->where('products.is_active', true)
                ->whereNotNull('products.low_stock_threshold')
                ->whereRaw('(select coalesce(sum(current_quantity), 0) from stock_batches where stock_batches.product_id = products.id) < products.low_stock_threshold')
                ->count(),
            'url' => '/inventory/stock',
            'hint' => 'Reorder before the counter has to refuse a sale.',
        ]);

        $add('accounting.view', function () {
            $weeks = collect(CashFlowForecast::build(8)['weeks']);
            $negative = $weeks->first(fn ($week) => $week['running'] < 0);

            return [
                'key' => 'cash_negative_week',
                'label' => 'Weeks until forecast cash goes negative',
                'count' => $negative === null ? 0 : max(1, $weeks->search(fn ($week) => $week['running'] < 0) + 1),
                'url' => '/accounting/cash-forecast',
                'hint' => $negative === null
                    ? 'The eight-week forecast stays above water.'
                    : 'Cash goes negative in the week of '.$negative['week_start'].' - collect sooner or pay later.',
            ];
        });

        // Personal, not permission-gated: your own approvals queue.
        $add('', fn () => [
            'key' => 'approvals_waiting',
            'label' => 'Approvals waiting on you',
            'count' => app(ApprovalInbox::class)->countFor($user),
            'url' => '/approvals',
            'hint' => 'Somebody cannot move until you decide.',
        ]);

        return Inertia::render('health', [
            'signals' => $signals,
        ]);
    }
}
