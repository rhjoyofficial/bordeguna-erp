<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales targets + commission, phase 1 (Module 4, Slice 5).
 *
 * ⚠ CALCULATION ONLY, and proudly so. The screen shows target vs the
 * invoices a person actually wrote and what the commission WOULD be; paying
 * it stays with payroll, by a human hand. A screen that computed money into
 * a salary by itself would be a second payroll engine, and the first one is
 * the only one.
 */
class SalesTargetController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = app(BusinessContext::class)->id();
        $month = $request->query('month', Carbon::now()->format('Y-m'));
        $monthStart = Carbon::parse($month.'-01');
        $monthEnd = $monthStart->copy()->endOfMonth();

        $rows = DB::table('sales_targets')
            ->where('business_id', $businessId)
            ->whereDate('month', $monthStart->toDateString())
            ->get()
            ->map(function ($target) use ($businessId, $monthStart, $monthEnd) {
                // The actual = invoices this person WROTE this month. Not
                // orders, not promises - invoiced revenue.
                $actual = (float) DB::table('sales_invoices')
                    ->where('business_id', $businessId)
                    ->where('created_by', $target->user_id)
                    ->whereBetween('invoice_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->sum('total');

                return [
                    'id' => $target->id,
                    'user' => DB::table('users')->where('id', $target->user_id)->value('name'),
                    'target_amount' => (float) $target->target_amount,
                    'actual' => round($actual, 2),
                    'achievement_percent' => $target->target_amount > 0
                        ? round($actual / (float) $target->target_amount * 100, 1)
                        : 0.0,
                    'commission_percent' => (float) $target->commission_percent,
                    'commission' => round($actual * (float) $target->commission_percent / 100, 2),
                ];
            });

        return Inertia::render('sales/targets', [
            'month' => $monthStart->format('Y-m'),
            'rows' => $rows,
            'users' => DB::table('users')
                ->join('business_user', 'users.id', '=', 'business_user.user_id')
                ->where('business_user.business_id', $businessId)
                ->orderBy('users.name')->get(['users.id', 'users.name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'month' => ['required', 'date_format:Y-m'],
            'target_amount' => ['required', 'numeric', 'min:0'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        // One target per person per month - setting again REPLACES, because
        // two targets for the same month is an argument, not a plan.
        DB::table('sales_targets')->updateOrInsert(
            [
                'business_id' => app(BusinessContext::class)->id(),
                'user_id' => $data['user_id'],
                'month' => $data['month'].'-01',
            ],
            [
                'target_amount' => $data['target_amount'],
                'commission_percent' => $data['commission_percent'] ?? 0,
                'created_by' => $request->user()->id,
                'updated_at' => now(), 'created_at' => now(),
            ],
        );

        return back()->with('success', 'Target set.');
    }
}
