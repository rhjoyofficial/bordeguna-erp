<?php

namespace App\Modules\Purchasing\Support;

/**
 * What an expense file WOULD pay, worked out completely before a taka moves.
 *
 * ⚠⚠ A plan carrying even one error is NEVER applied, and this importer needs
 * that rule more than the supplier one does. A supplier import that skipped a
 * bad row leaves a missing supplier, which somebody notices. An expense import
 * that skipped a bad row leaves a bill unpaid and a ledger that balances
 * perfectly - so the file is either fit to import or it is not.
 */
class ExpenseImportPlan
{
    /** @var list<array{line:int, reference:string, message:string}> */
    public array $errors = [];

    /** @var list<array{line:int, reference:string, message:string}> */
    public array $warnings = [];

    /**
     * @var list<array{line:int, date:string, payee:string, account:string, description:string,
     *     amount:float, paid_from:string, reference:string, note:string,
     *     account_id:int, money_account_id:int, supplier_id:int|null}>
     */
    public array $vouchers = [];

    /** @var list<string> */
    public array $ignoredColumns = [];

    public function addError(int $line, string $reference, string $message): void
    {
        $this->errors[] = ['line' => $line, 'reference' => $reference, 'message' => $message];
    }

    public function addWarning(int $line, string $reference, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'reference' => $reference, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function total(): float
    {
        return round(array_sum(array_column($this->vouchers, 'amount')), 2);
    }
}
