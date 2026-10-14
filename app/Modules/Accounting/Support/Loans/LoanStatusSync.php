<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;

/**
 * Moves an approved loan between approved, active, settled and written off
 * from what its live entries add up to, after every movement and reversal.
 *
 *  - no principal has moved yet                         approved
 *  - principal or accrued interest still owed           active
 *  - nothing owed, and a principal write-off is live    written off
 *  - nothing owed                                       settled
 *
 * ⚠ Settled needs principal AND interest at nothing (ChatGPT review: "require
 * principal, interest and other settlement balances to reconcile before
 * marking the loan settled"). A reversal can bring a settled loan back.
 */
final class LoanStatusSync
{
    public static function refresh(LoanContract $loan): LoanContract
    {
        if (! $loan->status->wasApproved()) {
            return $loan;
        }

        $live = LoanEntry::query()->live()->where('loan_contract_id', $loan->id)->get(['kind', 'entry_date', 'principal_minor', 'accrued_interest_minor']);

        $advanced = (int) $live->where('principal_minor', '>', 0)->sum('principal_minor');
        $principal = (int) $live->sum('principal_minor');
        $accrued = (int) $live->sum('accrued_interest_minor');

        if ($advanced === 0) {
            $status = LoanStatus::Approved;
            $settledOn = null;
        } elseif ($principal > 0 || $accrued > 0) {
            $status = LoanStatus::Active;
            $settledOn = null;
        } else {
            $status = $live->contains(fn (LoanEntry $e) => $e->kind === LoanEntryKind::WriteOff) ? LoanStatus::WrittenOff : LoanStatus::Settled;
            $settledOn = $live->max(fn (LoanEntry $e) => $e->entry_date->toDateString());
        }

        if ($loan->status !== $status || $loan->settled_on?->toDateString() !== $settledOn) {
            $loan->update(['status' => $status, 'settled_on' => $settledOn]);
        }

        return $loan;
    }
}
