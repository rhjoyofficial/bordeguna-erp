<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\CashFlow;
use App\Modules\Accounting\Support\CashFlowLine;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\ReportDates;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The statement of cash flows - the third primary statement.
 *
 * ⚠ A RANGE, not a date. Unlike the balance sheet this is a film and not a
 * photograph: it says what happened between two dates, so offering a single
 * date would be offering the wrong question.
 */
class CashFlowController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        $to = ($request->filled('to')
            ? ReportDates::parse($request, 'to')
            : Carbon::today())->endOfDay();

        $from = ($request->filled('from')
            ? ReportDates::parse($request, 'from')
            : ($business !== null ? FiscalYear::containing($to, $business)->startsOn() : $to->copy()->startOfMonth()))
            ->startOfDay();

        $flow = CashFlow::between($from, $to);

        return Inertia::render('accounting/cash-flow/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            // For the presets on the date bar; the server keeps deciding the default window.
            'fiscal_year_start_month' => $business?->fiscal_year_start_month,
            'fiscal_year' => $business !== null ? FiscalYear::containing($to, $business)->label() : null,
            'operating' => self::lines($flow->operating),
            'investing' => self::lines($flow->investing),
            'financing' => self::lines($flow->financing),
            'totals' => [
                'operating' => $flow->totalOperating()->format(),
                'investing' => $flow->totalInvesting()->format(),
                'financing' => $flow->totalFinancing()->format(),
                'net_movement' => $flow->netMovement()->format(),
                'opening' => $flow->opening->format(),
                'closing' => $flow->closing->format(),
            ],
            'signs' => [
                'operating' => $flow->totalOperating()->minor,
                'investing' => $flow->totalInvesting()->minor,
                'financing' => $flow->totalFinancing()->minor,
                'net_movement' => $flow->netMovement()->minor,
            ],
            // ⚠⚠ Shown, never hidden - the same rule the balance sheet follows.
            // A statement that quietly does not prove is worse than one that
            // says it does not, because the reader trusts it either way.
            'proves' => $flow->proves(),
            'difference' => $flow->difference()->format(),
            // ⚠ Without a mapped money account there is no cash to follow and
            // every figure would be a confident zero.
            'any_cash_account_mapped' => $flow->anyCashAccountMapped,
        ]);
    }

    /** @param  Collection<int, CashFlowLine>  $lines */
    private static function lines(Collection $lines): array
    {
        return $lines->map(fn (CashFlowLine $line) => [
            'code' => $line->account->code,
            'name' => $line->account->name,
            'flow' => $line->flow->format(),
            'minor' => $line->flow->minor,
            'is_inflow' => $line->isInflow(),
        ])->values()->all();
    }
}
