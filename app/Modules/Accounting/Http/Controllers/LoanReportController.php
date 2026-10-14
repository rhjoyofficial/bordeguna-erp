<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Loans\LoanPresenter;
use App\Modules\Accounting\Support\Loans\LoanReclassification;
use App\Modules\Accounting\Support\Loans\LoanReconciliation;
use App\Modules\Accounting\Support\Loans\LoanReports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounting > Loans > Reports (loans plan v2, phase 2): the receivable and
 * payable registers with their current and non-current parts, maturity
 * buckets, overdue installments, related-party loans, interest by period, TDS
 * on interest, and the register held against the ledger.
 */
class LoanReportController extends Controller
{
    public const REPORTS = ['receivable', 'payable', 'maturity', 'overdue', 'related', 'interest', 'tds', 'reconciliation'];

    public function index(Request $request): Response
    {
        $report = in_array($request->query('report'), self::REPORTS, true) ? $request->query('report') : 'receivable';
        $asAt = $this->date($request->query('as_at'), Carbon::today());
        $from = $this->date($request->query('from'), Carbon::today()->startOfMonth());
        $to = $this->date($request->query('to'), Carbon::today());

        return Inertia::render('accounting/loans/reports', [
            'report' => $report,
            'filters' => ['as_at' => $asAt->toDateString(), 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data' => match ($report) {
                'receivable' => $this->register($asAt, LoanDirection::Given),
                'payable' => $this->register($asAt, LoanDirection::Taken),
                'maturity' => $this->maturity($asAt),
                'overdue' => $this->overdue($asAt),
                'related' => $this->related($asAt),
                'interest' => $this->interest($from, $to),
                'tds' => $this->tds($from, $to),
                'reconciliation' => $this->reconciliation($asAt),
            },
            'canPostJournal' => $request->user()->can('accounting.journal.post'),
        ]);
    }

    /** @return array<string, mixed> */
    private function register(Carbon $asAt, LoanDirection $direction): array
    {
        $rows = array_values(array_filter(LoanReclassification::rows($asAt), fn (array $row) => $row['loan']->direction === $direction));
        $accrued = [];

        foreach ($rows as $row) {
            $accrued[$row['loan']->id] = LoanPosition::of($row['loan'], $asAt)->accrued;
        }

        $byCounterparty = collect($rows)->groupBy(fn (array $row) => $row['loan']->counterparty_id)->filter(fn ($group) => $group->contains(fn ($row) => $row['move'] !== 0))->keys()->all();

        return [
            'rows' => array_map(fn (array $row) => [
                'loan' => $this->loanCell($row['loan']),
                'principal_head' => $row['loan']->principalAccount?->label(),
                'outstanding' => LoanPresenter::money($row['outstanding']),
                'current' => LoanPresenter::money($row['current']),
                'non_current' => LoanPresenter::money($row['non_current']),
                'held_current' => $row['long_term'] ? LoanPresenter::money($row['held']) : null,
                'move' => $row['move'] === 0 ? null : LoanPresenter::money($row['move']),
                'accrued' => LoanPresenter::money($accrued[$row['loan']->id] ?? 0),
            ], $rows),
            'totals' => [
                'outstanding' => LoanPresenter::money(array_sum(array_column($rows, 'outstanding'))),
                'current' => LoanPresenter::money(array_sum(array_column($rows, 'current'))),
                'non_current' => LoanPresenter::money(array_sum(array_column($rows, 'non_current'))),
                'accrued' => LoanPresenter::money(array_sum($accrued)),
            ],
            'reclass_counterparties' => $byCounterparty,
        ];
    }

    /** @return array<string, mixed> */
    private function maturity(Carbon $asAt): array
    {
        $rows = LoanReports::maturity($asAt);

        return [
            'rows' => array_map(fn (array $row) => [
                'loan' => $this->loanCell($row['loan']),
                'outstanding' => LoanPresenter::money($row['outstanding']),
                'overdue' => LoanPresenter::money($row['overdue']),
                'within_12_months' => LoanPresenter::money($row['within_12_months']),
                'later' => LoanPresenter::money($row['later']),
                'unscheduled' => $row['unscheduled'] === 0 ? null : LoanPresenter::money($row['unscheduled']),
            ], $rows),
            'totals' => collect(['outstanding', 'overdue', 'within_12_months', 'later'])
                ->mapWithKeys(fn (string $key) => [$key => LoanPresenter::money(array_sum(array_column($rows, $key)))])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function overdue(Carbon $asAt): array
    {
        return ['rows' => array_map(fn (array $row) => [
            'loan' => $this->loanCell($row['loan']),
            'due_date' => $row['due_date']->toDateString(),
            'days_late' => $row['days_late'],
            'principal' => LoanPresenter::money($row['principal']),
            'interest' => LoanPresenter::money($row['interest']),
        ], LoanReports::overdue($asAt))];
    }

    /** @return array<string, mixed> */
    private function related(Carbon $asAt): array
    {
        return ['rows' => array_map(fn (array $row) => [
            'loan' => $this->loanCell($row['loan']),
            'relationship' => $row['loan']->counterparty?->relationship,
            'rate' => $row['loan']->annual_rate === null ? null : rtrim(rtrim((string) $row['loan']->annual_rate, '0'), '.'),
            'interest_free_reason' => $row['loan']->interest_free_reason,
            'maturity_date' => $row['loan']->maturity_date->toDateString(),
            'outstanding' => LoanPresenter::money($row['outstanding']),
            'accrued' => LoanPresenter::money($row['accrued']),
        ], LoanReports::related($asAt))];
    }

    /** @return array<string, mixed> */
    private function interest(Carbon $from, Carbon $to): array
    {
        $rows = LoanReports::interest($from, $to);
        $sum = fn (LoanDirection $direction, string $key) => LoanPresenter::money(array_sum(array_map(fn (array $r) => $r['loan']->direction === $direction ? $r[$key] : 0, $rows)));

        return [
            'rows' => array_map(fn (array $row) => [
                'loan' => $this->loanCell($row['loan']),
                'accrued' => LoanPresenter::money($row['accrued']),
                'direct' => LoanPresenter::money($row['direct']),
                'waived' => LoanPresenter::money($row['waived']),
                'total' => LoanPresenter::money($row['total']),
                'settled' => LoanPresenter::money($row['settled']),
                'tds' => LoanPresenter::money($row['tds']),
            ], $rows),
            'totals' => ['earned' => $sum(LoanDirection::Given, 'total'), 'paid' => $sum(LoanDirection::Taken, 'total')],
        ];
    }

    /** @return array<string, mixed> */
    private function tds(Carbon $from, Carbon $to): array
    {
        $rows = LoanReports::tds($from, $to);

        return [
            'rows' => array_map(fn (array $row) => [
                'loan' => $this->loanCell($row['loan']),
                'date' => $row['entry']->entry_date->toDateString(),
                'journal' => $row['entry']->journal?->journal_number,
                'journal_id' => $row['entry']->journal_id,
                'gross' => LoanPresenter::money($row['gross']),
                'tds' => LoanPresenter::money($row['tds']),
                'rate' => $row['entry']->tds_rate === null ? null : rtrim(rtrim((string) $row['entry']->tds_rate, '0'), '.'),
                'certificate' => $row['loan']->tds_certificate_ref,
            ], $rows),
            'totals' => [
                'withheld_by_us' => LoanPresenter::money(array_sum(array_map(fn ($r) => $r['loan']->direction === LoanDirection::Taken ? $r['tds'] : 0, $rows))),
                'withheld_from_us' => LoanPresenter::money(array_sum(array_map(fn ($r) => $r['loan']->direction === LoanDirection::Given ? $r['tds'] : 0, $rows))),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reconciliation(Carbon $asAt): array
    {
        $result = LoanReconciliation::build($asAt);

        return [
            'agrees' => $result['agrees'],
            'loans' => array_map(fn (array $row) => [
                'loan' => $this->loanCell($row['loan']),
                'register_principal' => LoanPresenter::money($row['register_principal']),
                'ledger_principal' => LoanPresenter::money($row['ledger_principal']),
                'register_accrued' => LoanPresenter::money($row['register_accrued']),
                'ledger_accrued' => LoanPresenter::money($row['ledger_accrued']),
                'agrees' => $row['agrees'],
            ], $result['loans']),
            'heads' => array_map(fn (array $row) => [
                'account' => $row['account']?->label(),
                'register' => LoanPresenter::money($row['register']),
                'ledger' => LoanPresenter::money($row['ledger']),
                'untagged' => $row['untagged'] === 0 ? null : LoanPresenter::money($row['untagged']),
                'difference' => LoanPresenter::money($row['difference']),
                'agrees' => $row['agrees'],
            ], $result['heads']),
        ];
    }

    /** @return array<string, mixed> */
    private function loanCell(LoanContract $loan): array
    {
        return [
            'id' => $loan->id,
            'number' => $loan->loan_number,
            'direction' => $loan->direction->value,
            'counterparty' => $loan->counterparty?->legal_name,
            'counterparty_id' => $loan->counterparty_id,
            'related' => (bool) $loan->counterparty?->related_party,
        ];
    }

    private function date(mixed $value, Carbon $default): Carbon
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::parse($value)->startOfDay() : $default->copy()->startOfDay();
    }
}
