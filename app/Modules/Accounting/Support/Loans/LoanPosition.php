<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\LoanAllocation;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Models\LoanScheduleRow;
use Illuminate\Support\Carbon;

/**
 * Where a loan stands, computed from its LIVE entries every time it is asked
 * (loans plan v2, phase 2). Nothing here is stored, so a reversed repayment
 * un-pays its installments without anybody touching them.
 *
 *   principal   principal still owed
 *   accrued     interest accrued and not yet settled or waived
 *   advanced    principal that has moved out (given) or in (taken) in total
 *   rows        every schedule row with what is paid against it and a status
 */
final class LoanPosition
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function __construct(
        public readonly int $principal,
        public readonly int $accrued,
        public readonly int $advanced,
        public readonly array $rows,
        public readonly int $currentVersion,
    ) {}

    public static function of(LoanContract $loan, ?Carbon $asAt = null): self
    {
        $entries = LoanEntry::query()->live()
            ->where('loan_contract_id', $loan->id)
            ->when($asAt !== null, fn ($q) => $q->whereDate('entry_date', '<=', $asAt->toDateString()))
            ->get(['id', 'principal_minor', 'accrued_interest_minor', 'kind']);

        $paid = LoanAllocation::query()
            ->whereIn('loan_entry_id', $entries->pluck('id')->all() ?: [0])
            ->get()
            ->groupBy('loan_schedule_row_id');

        $today = ($asAt ?? Carbon::today())->copy()->startOfDay();
        $closed = in_array($loan->status, [LoanStatus::Settled, LoanStatus::WrittenOff, LoanStatus::Cancelled], true);

        $rows = LoanScheduleRow::query()
            ->where('loan_contract_id', $loan->id)
            ->orderBy('version')->orderBy('sequence')
            ->get()
            ->map(function (LoanScheduleRow $row) use ($paid, $loan, $today, $closed) {
                $allocations = $paid->get($row->id, collect());
                $principalPaid = (int) $allocations->sum('principal_minor');
                $interestPaid = (int) $allocations->sum('interest_minor');
                $principalUnpaid = max(0, $row->principal_due_minor - $principalPaid);
                $interestUnpaid = max(0, $row->interest_due_minor - $interestPaid);
                $current = $row->version === (int) $loan->terms_version;

                $status = match (true) {
                    ! $current => 'superseded',
                    $principalUnpaid + $interestUnpaid === 0 => 'paid',
                    $closed => 'closed',
                    $row->due_date->lessThan($today) => 'overdue',
                    $principalPaid + $interestPaid > 0 => 'part_paid',
                    $row->due_date->equalTo($today) => 'due',
                    default => 'upcoming',
                };

                return [
                    'id' => $row->id,
                    'version' => $row->version,
                    'sequence' => $row->sequence,
                    'due_date' => $row->due_date->copy(),
                    'principal_due' => $row->principal_due_minor,
                    'interest_due' => $row->interest_due_minor,
                    'principal_paid' => $principalPaid,
                    'interest_paid' => $interestPaid,
                    'principal_unpaid' => $principalUnpaid,
                    'interest_unpaid' => $interestUnpaid,
                    'current' => $current,
                    'status' => $status,
                    'days_late' => $status === 'overdue' ? (int) $row->due_date->diffInDays($today) : 0,
                ];
            })
            ->values()
            ->all();

        return new self(
            principal: (int) $entries->sum('principal_minor'),
            accrued: (int) $entries->sum('accrued_interest_minor'),
            advanced: (int) $entries->where('principal_minor', '>', 0)->sum('principal_minor'),
            rows: $rows,
            currentVersion: (int) $loan->terms_version,
        );
    }

    /** @return list<array<string, mixed>> the rows of the terms in force */
    public function currentRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $row) => $row['current']));
    }

    /** @return list<array<string, mixed>> current rows due on or before the day and not fully paid */
    public function dueRows(Carbon $on): array
    {
        return array_values(array_filter(
            $this->currentRows(),
            fn (array $row) => $row['due_date']->lessThanOrEqualTo($on) && $row['principal_unpaid'] + $row['interest_unpaid'] > 0,
        ));
    }

    public function dueInterest(Carbon $on): int
    {
        return array_sum(array_column($this->dueRows($on), 'interest_unpaid'));
    }

    public function duePrincipal(Carbon $on): int
    {
        return array_sum(array_column($this->dueRows($on), 'principal_unpaid'));
    }

    /** @return list<array<string, mixed>> */
    public function overdueRows(): array
    {
        return array_values(array_filter($this->currentRows(), fn (array $row) => $row['status'] === 'overdue'));
    }

    public function isOverdue(): bool
    {
        return $this->overdueRows() !== [];
    }

    /** @return array<string, mixed>|null the next current row with something unpaid */
    public function nextDue(): ?array
    {
        foreach ($this->currentRows() as $row) {
            if ($row['principal_unpaid'] + $row['interest_unpaid'] > 0 && $row['status'] !== 'closed') {
                return $row;
            }
        }

        return null;
    }

    /** Scheduled interest not yet paid on any current row. */
    public function unpaidScheduledInterest(): int
    {
        return array_sum(array_column($this->currentRows(), 'interest_unpaid'));
    }

    /** Principal on current rows not yet paid, falling due by the day. */
    public function unpaidPrincipalDueBy(Carbon $on): int
    {
        $total = 0;

        foreach ($this->currentRows() as $row) {
            if ($row['due_date']->lessThanOrEqualTo($on)) {
                $total += $row['principal_unpaid'];
            }
        }

        return $total;
    }
}
