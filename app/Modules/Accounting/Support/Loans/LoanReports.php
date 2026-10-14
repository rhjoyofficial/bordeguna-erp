<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Loan Register's reports (loans plan v2, phase 2): everything read from
 * the register's live entries and schedules, never stored, so each one agrees
 * with the loan page it summarises. Money is in paisa here; the screen formats.
 */
final class LoanReports
{
    /**
     * Maturity buckets as at a day: principal overdue, due within 12 months,
     * due later, and any principal the schedule does not place (a manual
     * movement) so the buckets add up to what is owed.
     *
     * @return list<array<string, mixed>>
     */
    public static function maturity(Carbon $asAt, ?LoanDirection $direction = null): array
    {
        $horizon = $asAt->copy()->addMonthsNoOverflow(12);
        $rows = [];

        foreach (self::openLoans($asAt, $direction) as $loan) {
            $position = LoanPosition::of($loan, $asAt);
            $outstanding = max(0, $position->principal);

            if ($outstanding === 0) {
                continue;
            }

            $overdue = 0;
            $within = 0;
            $later = 0;

            foreach ($position->currentRows() as $row) {
                $unpaid = $row['principal_unpaid'];

                if ($row['due_date']->lessThan($asAt)) {
                    $overdue += $unpaid;
                } elseif ($row['due_date']->lessThanOrEqualTo($horizon)) {
                    $within += $unpaid;
                } else {
                    $later += $unpaid;
                }
            }

            $rows[] = [
                'loan' => $loan,
                'outstanding' => $outstanding,
                'overdue' => $overdue,
                'within_12_months' => $within,
                'later' => $later,
                'unscheduled' => $outstanding - ($overdue + $within + $later),
            ];
        }

        return $rows;
    }

    /**
     * Every installment past its due day and not fully paid.
     *
     * @return list<array<string, mixed>>
     */
    public static function overdue(Carbon $asAt, ?LoanDirection $direction = null): array
    {
        $rows = [];

        foreach (self::openLoans($asAt, $direction) as $loan) {
            foreach (LoanPosition::of($loan, $asAt)->currentRows() as $row) {
                if ($row['due_date']->lessThan($asAt) && $row['principal_unpaid'] + $row['interest_unpaid'] > 0) {
                    $rows[] = [
                        'loan' => $loan,
                        'due_date' => $row['due_date'],
                        'days_late' => (int) $row['due_date']->diffInDays($asAt),
                        'principal' => $row['principal_unpaid'],
                        'interest' => $row['interest_unpaid'],
                    ];
                }
            }
        }

        usort($rows, fn (array $a, array $b) => $a['due_date'] <=> $b['due_date']);

        return $rows;
    }

    /**
     * Loans with a related party: the disclosure list.
     *
     * @return list<array<string, mixed>>
     */
    public static function related(Carbon $asAt): array
    {
        return LoanContract::query()->with('counterparty')
            ->approvedOnce()
            ->whereHas('counterparty', fn ($q) => $q->where('related_party', true))
            ->orderBy('loan_number')
            ->get()
            ->map(function (LoanContract $loan) use ($asAt) {
                $position = LoanPosition::of($loan, $asAt);

                return ['loan' => $loan, 'outstanding' => max(0, $position->principal), 'accrued' => $position->accrued];
            })
            ->all();
    }

    /**
     * Interest earned (loans given) and paid (loans taken) in a period, loan by
     * loan: accrued, recognised straight on a repayment, waived, the total in
     * profit and loss, and the gross interest actually settled.
     *
     * @return list<array<string, mixed>>
     */
    public static function interest(Carbon $from, Carbon $to): array
    {
        $entries = LoanEntry::query()->live()
            ->whereDate('entry_date', '>=', $from->toDateString())
            ->whereDate('entry_date', '<=', $to->toDateString())
            ->get(['loan_contract_id', 'kind', 'interest_pl_minor', 'interest_settled_minor', 'tds_minor'])
            ->groupBy('loan_contract_id');

        if ($entries->isEmpty()) {
            return [];
        }

        return LoanContract::query()->with('counterparty')->whereIn('id', $entries->keys()->all())->orderBy('loan_number')->get()
            ->map(function (LoanContract $loan) use ($entries) {
                /** @var Collection<int, LoanEntry> $mine */
                $mine = $entries->get($loan->id);
                $accrued = (int) $mine->filter(fn (LoanEntry $e) => $e->kind->isAccrual())->sum('interest_pl_minor');
                $waived = (int) $mine->filter(fn (LoanEntry $e) => $e->kind === LoanEntryKind::Waiver)->sum('interest_pl_minor');
                $total = (int) $mine->sum('interest_pl_minor');

                return [
                    'loan' => $loan,
                    'accrued' => $accrued,
                    'direct' => $total - $accrued - $waived,
                    'waived' => $waived,
                    'total' => $total,
                    'settled' => (int) $mine->sum('interest_settled_minor'),
                    'tds' => (int) $mine->sum('tds_minor'),
                ];
            })
            ->filter(fn (array $row) => $row['total'] !== 0 || $row['settled'] !== 0)
            ->values()
            ->all();
    }

    /**
     * Tax withheld on loan interest in a period: by the business on interest
     * it paid (2180 TDS Payable), and by borrowers on interest it received
     * (1190 Advance Income Tax).
     *
     * @return list<array<string, mixed>>
     */
    public static function tds(Carbon $from, Carbon $to): array
    {
        return LoanEntry::query()->live()
            ->with(['loan.counterparty', 'journal'])
            ->where('tds_minor', '>', 0)
            ->whereDate('entry_date', '>=', $from->toDateString())
            ->whereDate('entry_date', '<=', $to->toDateString())
            ->orderBy('entry_date')->orderBy('id')
            ->get()
            ->map(fn (LoanEntry $entry) => [
                'entry' => $entry,
                'loan' => $entry->loan,
                'gross' => (int) $entry->interest_settled_minor,
                'tds' => (int) $entry->tds_minor,
            ])
            ->all();
    }

    /**
     * Approved loans that had money moved by the day and are not cancelled.
     *
     * @return Collection<int, LoanContract>
     */
    public static function openLoans(Carbon $asAt, ?LoanDirection $direction = null): Collection
    {
        return LoanContract::query()->with('counterparty')
            ->approvedOnce()
            ->when($direction !== null, fn ($q) => $q->where('direction', $direction->value))
            ->whereDate('start_date', '<=', $asAt->toDateString())
            ->orderBy('loan_number')
            ->get();
    }
}
