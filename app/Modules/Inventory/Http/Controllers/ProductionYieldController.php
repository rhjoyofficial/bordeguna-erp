<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Models\ProductionRun;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The production yield report (C-MFG-11): where a drifting yield gets noticed.
 *
 * Per output product for the chosen month: runs, output produced, consumed
 * value, waste value, and the VALUE-based waste percentage - the only waste
 * ratio that aggregates honestly across mixed units. Per-unit percentages stay
 * on the run detail, in each line's own unit, where they mean something.
 */
class ProductionYieldController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $request->string('month')->toString() ?: Carbon::now()->format('Y-m');
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // ⚠ Confirmed only. A reversed run un-happened economically, and a
        // draft has not happened yet; counting either would drift the trend.
        $runs = ProductionRun::query()
            ->where('status', ProductionRunStatus::Confirmed)
            // ⚠ Two whereDate bounds, never whereBetween on a date-cast column -
            // the sixth occurrence of that trap is not happening here.
            ->whereDate('occurred_at', '>=', $start->toDateString())
            ->whereDate('occurred_at', '<=', $end->toDateString())
            ->with(['outputProduct:id,name,sku', 'warehouse:id,name'])
            ->orderBy('occurred_at')
            ->get();

        $products = $runs->groupBy('output_product_id')->map(function ($group) {
            $consumed = Money::sum($group->map(fn ($run) => Money::ofTaka((string) ($run->consumed_value ?? '0'))));
            $waste = Money::sum($group->map(fn ($run) => Money::ofTaka((string) ($run->waste_value ?? '0'))));

            $first = $group->first();

            return [
                'product' => $first->outputProduct?->name,
                'sku' => $first->outputProduct?->sku,
                'runs' => $group->count(),
                'output_quantity' => (string) $group->sum(fn ($run) => (float) $run->output_quantity),
                'consumed_value' => $consumed->format(),
                'waste_value' => $waste->format(),
                // Value-based waste share: exact minor units, displayed to 2dp.
                'waste_percent' => $consumed->minor > 0
                    ? round($waste->minor / $consumed->minor * 100, 2)
                    : null,
                'flagged_runs' => $group->filter(fn ($run) => $run->yieldFlagged())->count(),
            ];
        })->values();

        return Inertia::render('inventory/production-yield', [
            'month' => $month,
            'products' => $products,
            'runs' => $runs->map(fn (ProductionRun $run) => [
                'id' => $run->id,
                'run_number' => $run->run_number,
                'occurred_at' => $run->occurred_at?->toDateString(),
                'warehouse' => $run->warehouse?->name,
                'product' => $run->outputProduct?->name,
                'output_quantity' => $run->output_quantity,
                'consumed_value' => $run->consumed_value,
                'waste_value' => $run->waste_value,
                'expense_waste' => $run->expense_waste,
                'yield_check' => $run->yield_check,
                'flagged' => $run->yieldFlagged(),
            ]),
        ]);
    }
}
