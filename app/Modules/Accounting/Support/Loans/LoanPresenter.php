<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Support\Money;

/**
 * How a loan reads on a screen: money formatted once, here, from paisa, and
 * the raw paisa beside it where the page computes a preview.
 */
final class LoanPresenter
{
    public static function money(int $minor): string
    {
        return Money::ofMinor($minor)->format();
    }

    /** @return array<string, mixed> */
    public static function summary(LoanContract $loan, ?LoanPosition $position = null): array
    {
        $position ??= LoanPosition::of($loan);
        $next = $position->nextDue();

        return [
            'id' => $loan->id,
            'number' => $loan->loan_number,
            'direction' => $loan->direction->value,
            'status' => $loan->status->value,
            'counterparty' => [
                'id' => $loan->counterparty_id,
                'name' => $loan->counterparty?->legal_name,
                'kind' => $loan->counterparty?->kind?->value,
                'related' => (bool) $loan->counterparty?->related_party,
                'relationship' => $loan->counterparty?->relationship,
            ],
            'principal' => $loan->principal()->format(),
            'rate' => LoanMath::rateString($loan->rateE4()),
            'start_date' => $loan->start_date->toDateString(),
            'maturity_date' => $loan->maturity_date->toDateString(),
            'outstanding' => self::money(max(0, $position->principal)),
            'accrued' => self::money($position->accrued),
            'overdue' => $position->isOverdue() && $loan->status->value === 'active',
            'next_due' => $next === null || $loan->status->value !== 'active' ? null : [
                'date' => $next['due_date']->toDateString(),
                'amount' => self::money($next['principal_unpaid'] + $next['interest_unpaid']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function row(array $row): array
    {
        return [
            'id' => $row['id'],
            'version' => $row['version'],
            'sequence' => $row['sequence'],
            'due_date' => $row['due_date']->toDateString(),
            'principal_due' => self::money($row['principal_due']),
            'interest_due' => self::money($row['interest_due']),
            'principal_paid' => self::money($row['principal_paid']),
            'interest_paid' => self::money($row['interest_paid']),
            'principal_unpaid_minor' => $row['principal_unpaid'],
            'interest_unpaid_minor' => $row['interest_unpaid'],
            'status' => $row['status'],
            'days_late' => $row['days_late'],
        ];
    }

    /** @return array<string, mixed> */
    public static function entry(LoanEntry $entry, bool $live): array
    {
        return [
            'id' => $entry->id,
            'kind' => $entry->kind->value,
            'date' => $entry->entry_date->toDateString(),
            'journal' => $entry->journal === null ? null : ['id' => $entry->journal->id, 'number' => $entry->journal->journal_number],
            'principal' => $entry->principal_minor === 0 ? null : self::money($entry->principal_minor),
            'accrued' => $entry->accrued_interest_minor === 0 ? null : self::money($entry->accrued_interest_minor),
            'interest' => $entry->interest_settled_minor === 0 ? null : self::money($entry->interest_settled_minor),
            'interest_pl' => $entry->interest_pl_minor === 0 ? null : self::money($entry->interest_pl_minor),
            'tds' => $entry->tds_minor === 0 ? null : self::money($entry->tds_minor),
            'cash' => $entry->cash_minor === 0 ? null : self::money($entry->cash_minor),
            'money_account' => $entry->moneyAccount?->label(),
            'period' => $entry->accrual_period,
            'note' => $entry->note,
            'live' => $live,
            'reversal' => $entry->reversed_at === null && $live ? null : [
                'number' => $entry->reversalJournal?->journal_number,
                'reason' => $entry->reversal_reason,
            ],
            'reversible' => $live && $entry->kind->reversibleHere(),
        ];
    }
}
