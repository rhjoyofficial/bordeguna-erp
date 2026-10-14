<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\Loans\AccrueLoanInterest;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Support\Loans\LoanPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounting > Loans > Accrue interest (loans plan v2, phase 2): the month's
 * interest for every active interest-bearing loan, PREVIEWED first (computed,
 * already accrued, the difference), then posted by a person. Reading the
 * preview is `accounting.loans.view`; posting is `accounting.loans.manage`.
 */
class LoanAccrualController extends Controller
{
    public function index(Request $request, AccrueLoanInterest $accrue): Response
    {
        $period = $this->period($request);
        [$start] = AccrueLoanInterest::month($period);

        $rows = array_map(fn (array $row) => [
            'loan' => LoanPresenter::summary($row['loan']),
            'computed' => LoanPresenter::money($row['computed']),
            'accrued' => LoanPresenter::money($row['accrued']),
            'difference' => LoanPresenter::money($row['difference']),
            'difference_minor' => $row['difference'],
            'date' => $row['date'],
            'note' => $row['note'],
        ], $accrue->preview($period));

        return Inertia::render('accounting/loans/accruals', [
            'period' => $period,
            'monthLabel' => $start->format('F Y'),
            'rows' => $rows,
            'total' => LoanPresenter::money(array_sum(array_column($rows, 'difference_minor'))),
            'canManage' => $request->user()->can('accounting.loans.manage'),
        ]);
    }

    public function store(Request $request, AccrueLoanInterest $accrue): RedirectResponse
    {
        $period = $this->period($request);
        [$start] = AccrueLoanInterest::month($period);
        $posted = 0;
        $refused = [];

        foreach (AccrueLoanInterest::candidates($start) as $loan) {
            /** @var LoanContract $loan */
            try {
                if ($accrue->handle($loan, $period, $request->user()) !== null) {
                    $posted++;
                }
            } catch (ValidationException $e) {
                $refused[] = "{$loan->loan_number}: ".collect($e->errors())->flatten()->first();
            }
        }

        $message = $posted === 0 ? "Nothing new to accrue for {$start->format('F Y')}." : "Interest for {$start->format('F Y')} booked on {$posted} loan(s).";

        return redirect()->route('accounting.loans.accruals', ['period' => $period])
            ->with($refused === [] ? 'success' : 'warning', $refused === [] ? $message : $message.' Not booked: '.implode(' ', $refused));
    }

    private function period(Request $request): string
    {
        $period = (string) $request->input('period', '');

        return preg_match('/^\d{4}-\d{2}$/', $period) ? $period : Carbon::today()->subMonthNoOverflow()->format('Y-m');
    }
}
