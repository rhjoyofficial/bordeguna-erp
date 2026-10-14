<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Models\LoanContract;

/**
 * The register loans a voucher or journal line may name (loans plan v2,
 * phase 2): this business's approved loans, each with its counterparty and
 * the heads it is kept on, so the form offers a loan only where it fits and
 * insists on one where the rule does (LoanLines).
 */
final class LoanOptions
{
    /**
     * @return list<array{id: int, label: string, counterparty_id: int, account_ids: list<int>, live: bool}>
     */
    public static function forPickers(): array
    {
        return LoanContract::query()
            ->with('counterparty:id,legal_name')
            ->approvedOnce()
            ->orderBy('loan_number')
            ->get()
            ->map(fn (LoanContract $loan) => [
                'id' => $loan->id,
                'label' => "{$loan->loan_number} - {$loan->counterparty?->legal_name} ({$loan->principal()->format()})",
                'counterparty_id' => (int) $loan->counterparty_id,
                'account_ids' => $loan->headIds(),
                'live' => $loan->status->isLive(),
            ])
            ->values()
            ->all();
    }
}
