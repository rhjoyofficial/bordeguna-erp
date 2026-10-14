<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\NormalBalance;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use Illuminate\Support\Carbon;

/**
 * ⚠⚠ THE REGISTER AGAINST THE LEDGER (loans plan v2, phase 2; the ChatGPT
 * release gate "schedule-to-GL reconciliation"). Two questions, both shown on
 * Accounting > Loans > Reconciliation and both tested:
 *
 *  1. LOAN BY LOAN: does the principal and accrued interest the register says
 *     is owed (its live entries) equal what the ledger holds on the loan's
 *     heads for lines carrying the loan's id?
 *  2. HEAD BY HEAD: does the register's total on each loan head equal the
 *     ledger's control balance on that head for the loans' counterparties?
 *     A difference here is money booked for one of those counterparties that
 *     names no loan (before the register existed, or by hand) and is named in
 *     `untagged`.
 *
 * Amounts are "owed" amounts: a debit balance on an asset head, a credit
 * balance on a liability head, both positive.
 */
final class LoanReconciliation
{
    /**
     * @return array{loans: list<array<string, mixed>>, heads: list<array<string, mixed>>, agrees: bool}
     */
    public static function build(?Carbon $asAt = null): array
    {
        $loans = LoanContract::query()->with('counterparty')->approvedOnce()->orderBy('loan_number')->get();

        if ($loans->isEmpty()) {
            return ['loans' => [], 'heads' => [], 'agrees' => true];
        }

        $ids = $loans->pluck('id')->all();
        $ledger = LoanLedger::balances($ids, $asAt);
        $register = LoanEntry::query()->live()
            ->whereIn('loan_contract_id', $ids)
            ->when($asAt !== null, fn ($q) => $q->whereDate('entry_date', '<=', $asAt->toDateString()))
            ->groupBy('loan_contract_id')
            ->selectRaw('loan_contract_id, SUM(principal_minor) as principal_minor, SUM(accrued_interest_minor) as accrued_minor')
            ->toBase()
            ->get()
            ->keyBy('loan_contract_id');

        $loanRows = [];
        /** @var array<int, int> account id => register total (owed) */
        $byHead = [];
        /** @var array<int, list<int>> account id => counterparty ids */
        $counterpartiesOn = [];

        foreach ($loans as $loan) {
            $sign = $loan->direction->sign();
            $heads = $ledger[$loan->id] ?? [];
            $registerPrincipal = (int) ($register[$loan->id]->principal_minor ?? 0);
            $registerAccrued = (int) ($register[$loan->id]->accrued_minor ?? 0);

            $ledgerPrincipal = 0;

            foreach ($loan->principalHeadIds() as $headId) {
                $ledgerPrincipal += ($heads[$headId] ?? 0) * $sign;
            }

            $ledgerAccrued = ($heads[(int) $loan->interest_accrual_account_id] ?? 0) * $sign;
            $currentHeld = $loan->current_portion_account_id === null ? 0 : ($heads[(int) $loan->current_portion_account_id] ?? 0) * $sign;

            $loanRows[] = [
                'loan' => $loan,
                'register_principal' => $registerPrincipal,
                'ledger_principal' => $ledgerPrincipal,
                'register_accrued' => $registerAccrued,
                'ledger_accrued' => $ledgerAccrued,
                'agrees' => $registerPrincipal === $ledgerPrincipal && $registerAccrued === $ledgerAccrued,
            ];

            // The register's split of principal between the long-term head and
            // its current portion is the reclassification the ledger carries.
            $byHead[(int) $loan->principal_account_id] = ($byHead[(int) $loan->principal_account_id] ?? 0) + $registerPrincipal - $currentHeld;

            if ($loan->current_portion_account_id !== null) {
                $byHead[(int) $loan->current_portion_account_id] = ($byHead[(int) $loan->current_portion_account_id] ?? 0) + $currentHeld;
            }

            $byHead[(int) $loan->interest_accrual_account_id] = ($byHead[(int) $loan->interest_accrual_account_id] ?? 0) + $registerAccrued;

            foreach ($loan->headIds() as $headId) {
                $counterpartiesOn[$headId][] = (int) $loan->counterparty_id;
            }
        }

        $accounts = Account::query()->whereIn('id', array_keys($byHead))->get()->keyBy('id');
        $headRows = [];

        foreach ($byHead as $accountId => $registerTotal) {
            $account = $accounts->get($accountId);
            $sign = $account?->normalBalance() === NormalBalance::Credit ? -1 : 1;
            $parties = array_values(array_unique($counterpartiesOn[$accountId] ?? []));

            $lines = JournalLine::query()
                ->where('journal_lines.account_id', $accountId)
                ->where('journal_lines.party_type', (new Counterparty)->getMorphClass())
                ->whereIn('journal_lines.party_id', $parties ?: [0])
                ->when($asAt !== null, fn ($q) => $q
                    ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                    ->where('journals.journal_date', '<=', $asAt->toDateString()))
                ->selectRaw('SUM(journal_lines.amount_minor) as total_minor, SUM(CASE WHEN journal_lines.loan_contract_id IS NULL THEN journal_lines.amount_minor ELSE 0 END) as untagged_minor')
                ->toBase()
                ->first();

            $ledgerTotal = (int) ($lines->total_minor ?? 0) * $sign;

            $headRows[] = [
                'account' => $account,
                'register' => $registerTotal,
                'ledger' => $ledgerTotal,
                'untagged' => (int) ($lines->untagged_minor ?? 0) * $sign,
                'difference' => $registerTotal - $ledgerTotal,
                'agrees' => $registerTotal === $ledgerTotal,
            ];
        }

        usort($headRows, fn (array $a, array $b) => strcmp((string) $a['account']?->code, (string) $b['account']?->code));

        $agrees = collect($loanRows)->every(fn (array $row) => $row['agrees']) && collect($headRows)->every(fn (array $row) => $row['agrees']);

        return ['loans' => $loanRows, 'heads' => $headRows, 'agrees' => $agrees];
    }
}
