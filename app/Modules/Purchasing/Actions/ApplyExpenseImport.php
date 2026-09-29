<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\ExpenseImportPlan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns an approved plan into real vouchers.
 *
 * ⚠⚠ IT GOES THROUGH `RecordExpenseVoucher`, ROW BY ROW, and never writes a
 * voucher or a journal itself. Everything that document knows - that only an
 * expense account may be debited, that a closed period refuses, that the money
 * account must have a ledger account behind it, that the journal balances -
 * would otherwise have to be repeated here, and would drift the first time one
 * of them changed.
 *
 * ⚠⚠ ONE TRANSACTION FOR THE WHOLE FILE. Half an import is worse than none: a
 * file of thirty bills that stopped at row nineteen leaves nobody able to say
 * which eleven still need paying, and re-importing it would pay the first
 * nineteen twice.
 */
class ApplyExpenseImport
{
    public function __construct(private readonly RecordExpenseVoucher $record) {}

    /**
     * @return list<string> the voucher numbers written, in file order
     */
    public function handle(ExpenseImportPlan $plan, User $by): array
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('A plan with errors is never applied.');
        }

        return DB::transaction(function () use ($plan, $by) {
            $numbers = [];

            foreach ($plan->vouchers as $row) {
                $account = Account::findOrFail($row['account_id']);
                $moneyAccount = MoneyAccount::findOrFail($row['money_account_id']);
                $supplier = $row['supplier_id'] === null ? null : Supplier::find($row['supplier_id']);

                $voucher = $this->record->handle(
                    moneyAccount: $moneyAccount,
                    spentOn: $row['date'],
                    lines: [[
                        'account' => $account,
                        'amount' => number_format($row['amount'], 2, '.', ''),
                        'description' => $row['description'] === '' ? null : $row['description'],
                    ]],
                    createdBy: $by,
                    supplier: $supplier,
                    // ⚠ Only where there is no supplier. A voucher naming both
                    // would answer "who was paid" two ways.
                    paidTo: $supplier === null && $row['payee'] !== '' ? $row['payee'] : null,
                    reference: $row['reference'] === '' ? null : $row['reference'],
                    checkBalance: false,
                    note: $row['note'] === '' ? null : $row['note'],
                );

                $numbers[] = $voucher->voucher_number;
            }

            return $numbers;
        });
    }
}
